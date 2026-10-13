<?php

use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();
        $db->table('group_permission')
            ->where('permission', 'discussion.rate.bypass')
            ->delete();
    },
    'down' => function (Builder $schema) {
        // No-op: we don't re-grant on rollback because the original default
        // (Moderator only) was the wrong choice; admins should consciously
        // pick which group gets the bypass in the Permission grid.
    },
];
