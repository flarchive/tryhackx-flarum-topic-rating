<?php

namespace TryHackX\TopicRating\Api\Serializer;

use Flarum\Api\Serializer\AbstractSerializer;
use Flarum\Api\Serializer\BasicUserSerializer;

class RatingSerializer extends AbstractSerializer
{
    protected $type = 'discussion-ratings';

    protected function getDefaultAttributes($rating)
    {
        return [
            'rating'    => (int) $rating->rating,
            'createdAt' => $this->formatDate($rating->created_at),
            'updatedAt' => $this->formatDate($rating->updated_at),
        ];
    }

    protected function user($rating)
    {
        return $this->hasOne($rating, BasicUserSerializer::class);
    }
}
