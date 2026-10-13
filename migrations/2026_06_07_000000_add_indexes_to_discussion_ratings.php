<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Performance indexes for the two hot lookups that aren't already covered by the
 * unique (discussion_id, user_id) key:
 *   - user_id            : the per-request "all of this actor's ratings" load
 *                          that backs the `userRating` field on discussion lists.
 *   - discussion_id,
 *     updated_at         : the poll endpoint's "any ratings since X?" range scan.
 * Additive only — safe to run on large tables.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('discussion_ratings', function (Blueprint $table) {
            $table->index('user_id', 'discussion_ratings_user_id_index');
            $table->index(['discussion_id', 'updated_at'], 'discussion_ratings_discussion_updated_index');
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('discussion_ratings', function (Blueprint $table) {
            $table->dropIndex('discussion_ratings_user_id_index');
            $table->dropIndex('discussion_ratings_discussion_updated_index');
        });
    },
];
