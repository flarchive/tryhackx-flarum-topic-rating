<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'discussion.rate' => Group::MEMBER_ID,
    'discussion.rate.toggle' => Group::MODERATOR_ID,
    'discussion.rate.reset' => Group::MODERATOR_ID,
]);
