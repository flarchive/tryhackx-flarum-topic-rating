<?php

namespace TryHackX\TopicRating;

use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\User\User;

class Rating extends AbstractModel
{
    protected $table = 'discussion_ratings';

    public $timestamps = true;

    protected $dates = ['created_at', 'updated_at'];

    public function discussion()
    {
        return $this->belongsTo(Discussion::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
