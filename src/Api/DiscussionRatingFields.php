<?php

namespace TryHackX\TopicRating\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;
use Flarum\Settings\SettingsRepositoryInterface;
use TryHackX\TopicRating\Access\RatingPolicy;
use TryHackX\TopicRating\Rating;

/**
 * Rating-related fields added to the DiscussionResource. Extracted from
 * extend.php so the policy and settings come in through constructor injection
 * (resolved once per request by the container) instead of a resolve() call
 * inside every per-model closure. As a bonus the single injected RatingPolicy
 * shares its per-request memoization across every discussion in the response.
 *
 * Registered via `->fields(DiscussionRatingFields::class)`.
 */
class DiscussionRatingFields
{
    /**
     * Per-request cache of the actor's own ratings, keyed by actor id then
     * discussion id. Loaded in a single query the first time `userRating` is
     * read, which turns the old one-query-per-discussion lookup (an N+1 on
     * every discussion-list render) into one query per request.
     *
     * @var array<int, array<int, int>>
     */
    protected array $actorRatings = [];

    /**
     * Whether {@see actorRatingsFor} loaded the actor's complete set (i.e. the
     * preload wasn't truncated by the cap). When true, an id missing from the map
     * means "not rated" and no per-discussion fallback lookup is needed.
     *
     * @var array<int, bool>
     */
    protected array $actorRatingsComplete = [];

    /**
     * Per-discussion fallback cache (incl. nulls) for actors whose rating count
     * exceeds the preload cap, keyed by actor id then discussion id.
     *
     * @var array<int, array<int, int|null>>
     */
    protected array $actorRatingMisses = [];

    /**
     * Upper bound on the per-request bulk preload of an actor's ratings. Chosen
     * far above any realistic per-user rating count, so normal users load in one
     * query with no fallbacks; only pathological voters hit the bounded path.
     */
    protected const PRELOAD_CAP = 5000;

    public function __construct(
        protected RatingPolicy $policy,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function __invoke(): array
    {
        return [
            Schema\Integer::make('ratingCount')
                ->get(fn (Discussion $discussion) => (int) $discussion->rating_count),
            Schema\Number::make('ratingAverage')
                ->get(fn (Discussion $discussion) => (float) $discussion->rating_average),
            Schema\DateTime::make('lastRatedAt')
                ->nullable()
                ->get(fn (Discussion $discussion) => $discussion->last_rated_at),
            Schema\Boolean::make('ratingDisabled')
                ->get(fn (Discussion $discussion) => (bool) $discussion->rating_disabled),
            Schema\Boolean::make('canRate')
                ->get(fn (Discussion $discussion, Context $context) =>
                    $context->getActor()->can('rate', $discussion)
                ),
            Schema\Boolean::make('canRateRequiresActivation')
                ->get(function (Discussion $discussion, Context $context) {
                    $actor = $context->getActor();
                    if (! $actor->id || $actor->is_email_confirmed
                        || $actor->can('rate', $discussion)
                        || $discussion->rating_disabled
                    ) {
                        return false;
                    }
                    if ($this->policy->actorBypassesGlobal($actor)) {
                        return false;
                    }
                    return ! (bool) $this->settings->get('tryhackx-topic-rating.allow_unactivated', false);
                }),
            Schema\Str::make('ratingDisplayMode')
                ->get(function (Discussion $discussion, Context $context) {
                    $actor = $context->getActor();

                    // Tagless discussions with "allow rating on untagged
                    // discussions" turned off are hidden entirely (regardless of
                    // the restricted-display mode, and for everyone).
                    if ($this->policy->ratingForcedHidden($discussion)) {
                        return 'hidden';
                    }

                    // Those who can rate always see the widget (incl. bypassers).
                    if ($actor->can('rate', $discussion)) {
                        return 'rate';
                    }

                    // Hide existing ratings on topics whose rating is fully
                    // disabled (all tags disabled, or moderator-disabled) for
                    // viewers who can't rate — prevents "rate it, then switch to a
                    // disabled tag to lock the score" abuse. On by default; when
                    // off such topics fall back to the restricted-display mode.
                    if ((bool) $this->settings->get('tryhackx-topic-rating.hide_disabled_ratings', true)
                        && $this->policy->isRatingDisabled($discussion)) {
                        return 'hidden';
                    }

                    $mode = (string) $this->settings->get('tryhackx-topic-rating.display_when_restricted', 'readonly');
                    if (! in_array($mode, ['readonly', 'hidden', 'message'], true)) {
                        $mode = 'readonly';
                    }
                    return $mode;
                }),
            Schema\Boolean::make('canToggleRating')
                ->get(fn (Discussion $discussion, Context $context) =>
                    $context->getActor()->hasPermission('discussion.rate.toggle')
                ),
            Schema\Boolean::make('canResetRatings')
                ->get(fn (Discussion $discussion, Context $context) =>
                    $context->getActor()->hasPermission('discussion.rate.reset')
                ),
            Schema\Integer::make('userRating')
                ->nullable()
                ->get(function (Discussion $discussion, Context $context) {
                    $actor = $context->getActor();
                    if (! $actor->id) {
                        return null;
                    }
                    return $this->userRatingFor((int) $actor->id, (int) $discussion->id);
                }),
        ];
    }

    /**
     * The acting user's rating for one discussion (0–10), or null if unrated.
     *
     * Backed by a single bulk load of the actor's ratings on first use, capped at
     * {@see self::PRELOAD_CAP} rows so a pathological voter (tens of thousands of
     * ratings) can't hydrate an unbounded result set on every discussion-list
     * render. When the cap is hit, discussions outside the preloaded (most-recent)
     * set fall back to a single cached per-discussion lookup, so the value stays
     * correct without re-running the N+1 the bulk load exists to prevent. The
     * Flarum 2.x field-serializer context exposes no list of the page's models
     * (search results are null in getters), so this cap is the bound available
     * without re-architecting the resource layer.
     */
    protected function userRatingFor(int $actorId, int $discussionId): ?int
    {
        $preloaded = $this->actorRatingsFor($actorId);

        if (array_key_exists($discussionId, $preloaded)) {
            return $preloaded[$discussionId];
        }

        // The preload held the actor's full set — an absent id genuinely means
        // "not rated", no lookup needed.
        if ($this->actorRatingsComplete[$actorId] ?? false) {
            return null;
        }

        // Capped preload may have omitted this discussion; resolve it directly and
        // cache the result (incl. nulls) so repeats within the request are free.
        if (! array_key_exists($discussionId, $this->actorRatingMisses[$actorId] ?? [])) {
            $rating = Rating::query()
                ->where('user_id', $actorId)
                ->where('discussion_id', $discussionId)
                ->value('rating');
            $this->actorRatingMisses[$actorId][$discussionId] = $rating === null ? null : (int) $rating;
        }

        return $this->actorRatingMisses[$actorId][$discussionId];
    }

    /**
     * The actor's ratings as a [discussionId => rating] map, loaded once per
     * request, capped at the most-recent {@see self::PRELOAD_CAP} entries.
     *
     * @return array<int, int>
     */
    protected function actorRatingsFor(int $actorId): array
    {
        if (! isset($this->actorRatings[$actorId])) {
            // Fetch one more than the cap so we can tell whether the set was
            // truncated (and thus whether per-discussion fallbacks are needed).
            $rows = Rating::query()
                ->where('user_id', $actorId)
                ->orderByDesc('id')
                ->limit(self::PRELOAD_CAP + 1)
                ->pluck('rating', 'discussion_id');

            $this->actorRatingsComplete[$actorId] = $rows->count() <= self::PRELOAD_CAP;
            $this->actorRatings[$actorId] = $rows
                ->take(self::PRELOAD_CAP)
                ->map(fn ($r) => (int) $r)
                ->all();
        }

        return $this->actorRatings[$actorId];
    }
}
