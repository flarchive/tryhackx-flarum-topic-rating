<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'discussion.rate.bypass' => Group::MODERATOR_ID,
]);
