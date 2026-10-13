<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Index the `last_rated_at` column (added by
 * 2024_01_01_000001_add_rating_columns_to_discussions).
 *
 * The bundled flarum-homepage-blocks "rated in period" filter (RatingFilter)
 * runs `WHERE discussions.last_rated_at >= ?` on the discussion list. On a forum
 * with millions of discussions that range predicate needs an index to avoid a
 * full table scan. This extension owns the column, so the index belongs here.
 * Additive only — safe to run on large tables (online/in-place DDL on MySQL
 * 5.6+/8, MariaDB, PostgreSQL).
 *
 * No index is added for the homepage-blocks "Steam rating" SORT: it orders by a
 * computed expression of rating_average/rating_count, which a plain column index
 * cannot accelerate (it always filesorts). Indexing the aggregate columns would
 * add write cost on every rating with no read benefit, so it is intentionally
 * omitted.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('discussions', function (Blueprint $table) {
            $table->index('last_rated_at', 'discussions_last_rated_at_index');
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('discussions', function (Blueprint $table) {
            $table->dropIndex('discussions_last_rated_at_index');
        });
    },
];
