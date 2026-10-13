<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Author-rating building blocks (see RatingRecalculator):
 *   - author_rating          the topic author's own rating (1–10), NULL if none
 *   - community_rating_count ratings by everyone except the author
 *   - community_rating_sum   sum of those ratings
 *
 * They let the "review mode" options (AuthorMode) show the author's rating apart
 * from the community score — and let a mode switch re-derive every topic's
 * rating_count / rating_average with cheap same-row arithmetic.
 *
 * The backfill is set-based and limited to discussions that have ratings or a
 * non-zero cached score. While at it, it re-derives the cached default-mode score
 * (every rating counts — no other mode exists before this release): before 2.5.0,
 * deleting a user account removed its ratings through the foreign key without
 * recomputing the topics it had rated, so some scores could still count votes
 * that no longer exist. The SQL mirrors RatingRecalculator (self-contained on
 * purpose: a migration must not change behaviour when the class evolves).
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('discussions', function (Blueprint $table) {
            $table->unsignedTinyInteger('author_rating')->nullable();
            $table->unsignedInteger('community_rating_count')->default(0);
            $table->unsignedInteger('community_rating_sum')->default(0);
        });

        $db = $schema->getConnection();
        $p = $db->getTablePrefix();
        $r = "{$p}discussion_ratings";
        $d = "{$p}discussions";
        $own = "$r.discussion_id = $d.id";
        $notByAuthor = "($d.user_id IS NULL OR $r.user_id <> $d.user_id)";

        $affected = fn () => $db->table('discussions')->where(function ($q) use ($db) {
            $q->whereIn('id', $db->table('discussion_ratings')->select('discussion_id'))
                ->orWhere('rating_count', '>', 0)
                ->orWhereNotNull('last_rated_at');
        });

        $affected()->update([
            'author_rating' => $db->raw("(SELECT MAX($r.rating) FROM $r WHERE $own AND $r.user_id = $d.user_id)"),
            'community_rating_count' => $db->raw("(SELECT COUNT(*) FROM $r WHERE $own AND $notByAuthor)"),
            'community_rating_sum' => $db->raw("(SELECT COALESCE(SUM($r.rating), 0) FROM $r WHERE $own AND $notByAuthor)"),
            'last_rated_at' => $db->raw("(SELECT MAX($r.updated_at) FROM $r WHERE $own)"),
        ]);

        // Default-mode score from the (now correct) building blocks.
        $count = '(community_rating_count + (CASE WHEN author_rating IS NULL THEN 0 ELSE 1 END))';
        $sum = '(community_rating_sum + COALESCE(author_rating, 0))';

        $affected()->update([
            'rating_count' => $db->raw($count),
            'rating_average' => $db->raw("(CASE WHEN $count = 0 THEN 0 ELSE ROUND($sum / (2.0 * $count), 2) END)"),
        ]);
    },
    'down' => function (Builder $schema) {
        $schema->table('discussions', function (Blueprint $table) {
            $table->dropColumn(['author_rating', 'community_rating_count', 'community_rating_sum']);
        });
    },
];
