<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->table('discussions', function (Blueprint $table) {
            $table->unsignedInteger('rating_count')->default(0);
            $table->decimal('rating_average', 3, 2)->default(0);
            $table->timestamp('last_rated_at')->nullable();
            $table->boolean('rating_disabled')->default(false);
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('discussions', function (Blueprint $table) {
            $table->dropColumn(['rating_count', 'rating_average', 'last_rated_at', 'rating_disabled']);
        });
    },
];
