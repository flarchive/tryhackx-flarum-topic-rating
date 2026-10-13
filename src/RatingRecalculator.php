<?php

namespace TryHackX\TopicRating;

use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Recomputes and persists a discussion's denormalised rating aggregates from the
 * `discussion_ratings` rows.
 *
 * Two layers of columns live on `discussions`:
 *
 *  - Building blocks, independent of the author mode (step 1):
 *      author_rating          the topic author's own rating (1–10) or NULL
 *      community_rating_count number of ratings by everyone except the author
 *      community_rating_sum   sum of those ratings (1–10 each)
 *      last_rated_at          newest rating's timestamp
 *  - The effective score, i.e. what is displayed and what other extensions sort
 *    and filter on (step 2): rating_count / rating_average (0–5 scale), derived
 *    from the building blocks according to the discussion's {@see AuthorMode}.
 *
 * Because step 2 only does same-row arithmetic on the building blocks, switching
 * the mode (or its tag scope) can re-derive every score with a cheap set-based
 * UPDATE ({@see refreshEffective}) — no pass over `discussion_ratings` needed.
 *
 * This is the one justified raw-SQL exception in this extension: the correlated
 * subqueries (`discussion_ratings.discussion_id = discussions.id`) recompute each
 * value in a single statement, so two concurrent rate/unrate requests can't
 * interleave a read-modify-write and leave the cached values out of sync — there
 * is no Eloquent equivalent for that. Each request runs step 1 and then step 2,
 * so whichever request finishes last derives the score from the newest building
 * blocks. Only trusted values are interpolated (the configured table prefix and
 * fixed column names); ids are bound.
 *
 * Resolved from the container and injected into the write controllers/listeners.
 */
class RatingRecalculator
{
    /** Discussions per statement for the bulk passes (bounded lock + memory). */
    public const CHUNK = 5000;

    public function __construct(
        protected ConnectionInterface $db,
        protected ExtensionManager $extensions
    ) {
    }

    /**
     * Recompute everything for one discussion (after a rating write).
     */
    public function recalculate(Discussion $discussion): void
    {
        $id = (int) $discussion->id;
        if ($id > 0) {
            $this->recalculateMany([$id]);
        }
    }

    /**
     * Recompute everything for a set of discussions (e.g. those a user who is
     * being deleted had rated).
     *
     * @param array<int, int|string> $ids
     */
    public function recalculateMany(array $ids): void
    {
        foreach (array_chunk(self::cleanIds($ids), self::CHUNK) as $chunk) {
            $only = fn (Builder $q) => $q->whereIn('id', $chunk);

            $this->applyBuildingBlocks($only);
            $this->applyEffective($only);
        }
    }

    /**
     * Rebuild every discussion's aggregates from scratch (console command /
     * repair after manual database edits). Walks the id space in chunks.
     */
    public function recalculateAll(): void
    {
        $this->eachIdRange(function (int $from, int $to) {
            $range = fn (Builder $q) => $q->whereBetween('id', [$from, $to]);

            $this->applyBuildingBlocks(function (Builder $q) use ($range) {
                $range($q);
                // Only rows that have ratings or carry (possibly stale) aggregates.
                $q->where(function (Builder $w) {
                    $w->whereIn('id', $this->db->table('discussion_ratings')->select('discussion_id'))
                        ->orWhere('rating_count', '>', 0)
                        ->orWhere('community_rating_count', '>', 0)
                        ->orWhereNotNull('author_rating')
                        ->orWhereNotNull('last_rated_at');
                });
            });
            $this->applyEffective($range);
        });
    }

    /**
     * Re-derive only the effective score (step 2) — after the author mode or its
     * tag scope changed, or a discussion was retagged.
     *
     * @param array<int, int|string>|null $ids null = every discussion
     */
    public function refreshEffective(?array $ids = null): void
    {
        if ($ids !== null) {
            foreach (array_chunk(self::cleanIds($ids), self::CHUNK) as $chunk) {
                $this->applyEffective(fn (Builder $q) => $q->whereIn('id', $chunk));
            }

            return;
        }

        $this->eachIdRange(function (int $from, int $to) {
            $this->applyEffective(fn (Builder $q) => $q->whereBetween('id', [$from, $to]));
        });
    }

    /**
     * Step 1 — the mode-independent building blocks, from `discussion_ratings`.
     */
    protected function applyBuildingBlocks(callable $constrain): void
    {
        // Flarum does NOT register Laravel's global facades, so go through the
        // connection itself. The base query builder (not Eloquent) also avoids
        // auto-touching discussions.updated_at. The builder prefixes the outer
        // ->table('discussions'), but raw subqueries must prefix their own table
        // names or they'd break on a prefixed install. The prefix is trusted
        // config (never user input), so interpolating it is safe.
        $p = $this->db->getTablePrefix();
        $r = "{$p}discussion_ratings";
        $d = "{$p}discussions";

        $own = "$r.discussion_id = $d.id";
        $byAuthor = "$r.user_id = $d.user_id";
        // A discussion whose author account is gone (user_id NULL) has no author
        // rating — every rating on it is a community rating.
        $notByAuthor = "($d.user_id IS NULL OR $r.user_id <> $d.user_id)";

        $query = $this->db->table('discussions');
        $constrain($query);

        $query->update([
            'last_rated_at' => $this->db->raw("(SELECT MAX($r.updated_at) FROM $r WHERE $own)"),
            'author_rating' => $this->db->raw("(SELECT MAX($r.rating) FROM $r WHERE $own AND $byAuthor)"),
            'community_rating_count' => $this->db->raw("(SELECT COUNT(*) FROM $r WHERE $own AND $notByAuthor)"),
            'community_rating_sum' => $this->db->raw("(SELECT COALESCE(SUM($r.rating), 0) FROM $r WHERE $own AND $notByAuthor)"),
        ]);
    }

    /**
     * Step 2 — rating_count / rating_average from the building blocks, per mode.
     *
     * With a tag-scoped mode the matching discussions get the configured mode and
     * the rest get the default, in two statements decided by the `discussion_tag`
     * pivot (the same rule {@see AuthorModeResolver::resolve} applies in PHP).
     * Because the pivot and the freshly read settings are the source of truth, a
     * queued run is correct whenever it executes.
     */
    protected function applyEffective(callable $constrain): void
    {
        [$mode, $scope] = $this->currentModeAndScope();

        $base = function () use ($constrain) {
            $query = $this->db->table('discussions');
            $constrain($query);

            // Skip never-rated discussions (score 0 in every mode), but keep rows
            // whose effective score is still non-zero — e.g. right after step 1
            // removed the last rating — so it drops back to 0.
            return $query->where(function (Builder $w) {
                $w->where('community_rating_count', '>', 0)
                    ->orWhereNotNull('author_rating')
                    ->orWhere('rating_count', '>', 0);
            });
        };

        if (! $scope) {
            $base()->update(self::effectiveColumns($this->db, $mode));

            return;
        }

        $inScope = function (Builder $q) use ($scope) {
            $q->selectRaw('1')
                ->from('discussion_tag')
                ->whereColumn('discussion_tag.discussion_id', 'discussions.id')
                ->whereIn('discussion_tag.tag_id', $scope);
        };

        $base()->whereExists($inScope)->update(self::effectiveColumns($this->db, $mode));
        $base()->whereNotExists($inScope)->update(self::effectiveColumns($this->db, AuthorMode::DEFAULT));
    }

    /**
     * Column => expression map deriving the effective score for a mode from the
     * same row's building blocks. Portable SQL (MySQL/MariaDB, PostgreSQL, SQLite).
     *
     * @return array<string, \Illuminate\Contracts\Database\Query\Expression>
     */
    public static function effectiveColumns(ConnectionInterface $connection, string $mode): array
    {
        $authorCount = '(CASE WHEN author_rating IS NULL THEN 0 ELSE 1 END)';

        switch (AuthorMode::normalize($mode)) {
            case AuthorMode::AUTHOR_ONLY:
                $count = $authorCount;
                $sum = 'COALESCE(author_rating, 0)';
                break;
            case AuthorMode::EXCLUDE:
            case AuthorMode::SEPARATE:
                $count = 'community_rating_count';
                $sum = 'community_rating_sum';
                break;
            default:
                $count = "(community_rating_count + $authorCount)";
                $sum = '(community_rating_sum + COALESCE(author_rating, 0))';
        }

        return [
            'rating_count' => $connection->raw($count),
            // Ratings are stored 1–10 (half stars); the average is kept on the
            // 0–5 star scale, rounded to 2 decimals like before.
            'rating_average' => $connection->raw("(CASE WHEN $count = 0 THEN 0 ELSE ROUND($sum / (2.0 * $count), 2) END)"),
        ];
    }

    /**
     * The configured mode and tag scope, read straight from the settings table
     * rather than the per-process settings cache: a rating write that started
     * before an admin switched the mode, or a long-lived queue worker, must not
     * re-derive a score for the old mode. Same rules as AuthorModeResolver (the
     * scope only applies while flarum/tags is enabled — asked of the extension
     * manager, the authority on that).
     *
     * @return array{0: string, 1: array<int, string>}
     */
    protected function currentModeAndScope(): array
    {
        $raw = $this->db->table('settings')
            ->whereIn('key', [AuthorModeResolver::MODE_SETTING, AuthorModeResolver::TAGS_SETTING])
            ->pluck('value', 'key');

        $mode = AuthorMode::normalize($raw->get(AuthorModeResolver::MODE_SETTING, AuthorMode::DEFAULT));
        if ($mode === AuthorMode::DEFAULT) {
            return [$mode, []];
        }

        return [$mode, $this->extensions->isEnabled('flarum-tags')
            ? AuthorModeResolver::parseTagIds($raw->get(AuthorModeResolver::TAGS_SETTING, '[]'))
            : []];
    }

    protected function eachIdRange(callable $callback): void
    {
        $max = (int) $this->db->table('discussions')->max('id');

        for ($from = 1; $from <= $max; $from += self::CHUNK) {
            $callback($from, $from + self::CHUNK - 1);
        }
    }

    /**
     * @param array<int, int|string> $ids
     * @return array<int, int>
     */
    protected static function cleanIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0)));
    }
}
