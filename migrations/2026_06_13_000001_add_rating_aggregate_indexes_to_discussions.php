<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Index `rating_average` and `rating_count` (added by
 * 2024_01_01_000001_add_rating_columns_to_discussions).
 *
 * Companion to 2026_06_13_000000 (which indexed last_rated_at). The bundled
 * flarum-homepage-blocks exposes "Avg rating" (`ORDER BY rating_average`) and
 * "Number of ratings" (`ORDER BY rating_count`) discussion-list sorts; on a forum
 * with millions of rows those order-bys filesort the whole table without an index.
 * This extension owns the columns, so the indexes live here. Additive/online —
 * safe on large tables (the build can take a while on a very large `discussions`
 * table). InnoDB appends the PK (id) to each secondary index, so a single-column
 * index also covers the trailing `…, id` pagination tiebreaker for same-direction
 * sorts.
 *
 * No index is added for the homepage-blocks "Steam DB" sort — it orders by a
 * computed confidence expression of both columns, which a plain column index
 * cannot serve (always a filesort). Materialising that score is a separate,
 * higher-risk change deliberately deferred.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('discussions', function (Blueprint $table) {
            $table->index('rating_average', 'discussions_rating_average_index');
            $table->index('rating_count', 'discussions_rating_count_index');
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('discussions', function (Blueprint $table) {
            $table->dropIndex('discussions_rating_average_index');
            $table->dropIndex('discussions_rating_count_index');
        });
    },
];
