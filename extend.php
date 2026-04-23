<?php

use TryHackX\TopicRating\Api\Controller;
use TryHackX\TopicRating\Api\Serializer\RatingSerializer;
use TryHackX\TopicRating\Access\RatingPolicy;
use TryHackX\TopicRating\Rating;
use Flarum\Api\Serializer\DiscussionSerializer;
use Flarum\Api\Serializer\BasicDiscussionSerializer;
use Flarum\Discussion\Discussion;
use Flarum\Extend;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/less/admin.less'),

    new Extend\Locales(__DIR__ . '/locale'),

    (new Extend\Model(Discussion::class))
        ->hasMany('ratings', Rating::class, 'discussion_id')
        ->cast('last_rated_at', 'datetime'),

    (new Extend\ApiSerializer(DiscussionSerializer::class))
        ->attributes(function (DiscussionSerializer $serializer, Discussion $discussion, array $attributes) {
            $actor = $serializer->getActor();
            $attributes['ratingCount'] = (int) $discussion->rating_count;
            $attributes['ratingAverage'] = (float) $discussion->rating_average;

            $lastRatedAt = $discussion->last_rated_at;
            if ($lastRatedAt) {
                if (is_string($lastRatedAt)) {
                    try {
                        $lastRatedAt = new \DateTime($lastRatedAt);
                    } catch (\Exception $e) {
                        $lastRatedAt = null;
                    }
                }
                $attributes['lastRatedAt'] = $lastRatedAt ? $serializer->formatDate($lastRatedAt) : null;
            } else {
                $attributes['lastRatedAt'] = null;
            }

            $attributes['ratingDisabled'] = (bool) $discussion->rating_disabled;
            $attributes['canRate'] = $actor->can('rate', $discussion);
            $attributes['canRateRequiresActivation'] = false;

            if ($actor->id && !$actor->is_email_confirmed && !$attributes['canRate'] && !$discussion->rating_disabled) {
                $settings = resolve(\Flarum\Settings\SettingsRepositoryInterface::class);
                $allowUnactivated = (bool) $settings->get('tryhackx-topic-rating.allow_unactivated', false);
                if (!$allowUnactivated) {
                    $attributes['canRateRequiresActivation'] = true;
                }
            }

            $attributes['canToggleRating'] = $actor->hasPermission('discussion.rate.toggle');
            $attributes['canResetRatings'] = $actor->hasPermission('discussion.rate.reset');

            if ($actor->id) {
                $userRating = Rating::where('discussion_id', $discussion->id)
                    ->where('user_id', $actor->id)
                    ->first();
                $attributes['userRating'] = $userRating ? (int) $userRating->rating : null;
            } else {
                $attributes['userRating'] = null;
            }

            return $attributes;
        }),

    (new Extend\ApiSerializer(BasicDiscussionSerializer::class))
        ->attributes(function (BasicDiscussionSerializer $serializer, Discussion $discussion, array $attributes) {
            $actor = $serializer->getActor();
            $attributes['ratingCount'] = (int) $discussion->rating_count;
            $attributes['ratingAverage'] = (float) $discussion->rating_average;

            $lastRatedAt = $discussion->last_rated_at;
            if ($lastRatedAt) {
                if (is_string($lastRatedAt)) {
                    try {
                        $lastRatedAt = new \DateTime($lastRatedAt);
                    } catch (\Exception $e) {
                        $lastRatedAt = null;
                    }
                }
                $attributes['lastRatedAt'] = $lastRatedAt ? $serializer->formatDate($lastRatedAt) : null;
            } else {
                $attributes['lastRatedAt'] = null;
            }

            $attributes['ratingDisabled'] = (bool) $discussion->rating_disabled;
            $attributes['canRate'] = $actor->can('rate', $discussion);
            $attributes['canRateRequiresActivation'] = false;

            if ($actor->id && !$actor->is_email_confirmed && !$attributes['canRate'] && !$discussion->rating_disabled) {
                $settings = resolve(\Flarum\Settings\SettingsRepositoryInterface::class);
                $allowUnactivated = (bool) $settings->get('tryhackx-topic-rating.allow_unactivated', false);
                if (!$allowUnactivated) {
                    $attributes['canRateRequiresActivation'] = true;
                }
            }

            if ($actor->id) {
                $userRating = Rating::where('discussion_id', $discussion->id)
                    ->where('user_id', $actor->id)
                    ->first();
                $attributes['userRating'] = $userRating ? (int) $userRating->rating : null;
            } else {
                $attributes['userRating'] = null;
            }

            return $attributes;
        }),

    (new Extend\Routes('api'))
        ->get('/discussion-ratings', 'discussion-ratings.index', Controller\ListRatingsController::class)
        ->post('/discussion-ratings', 'discussion-ratings.create', Controller\CreateRatingController::class)
        ->delete('/discussion-ratings', 'discussion-ratings.delete', Controller\DeleteRatingController::class)
        ->post('/discussions/{id}/toggle-rating', 'discussions.toggle-rating', Controller\ToggleRatingController::class)
        ->post('/discussions/{id}/reset-ratings', 'discussions.reset-ratings', Controller\ResetRatingsController::class)
        ->get('/discussion-ratings/poll', 'discussion-ratings.poll', Controller\PollRatingController::class),

    (new Extend\Policy())
        ->modelPolicy(Discussion::class, RatingPolicy::class),

    (new Extend\Settings())
        ->serializeToForum('tryhackxTopicRatingEnabled', 'tryhackx-topic-rating.enabled', 'boolval', true)
        ->serializeToForum('tryhackxTopicRatingAllowUnactivated', 'tryhackx-topic-rating.allow_unactivated', 'boolval', false)
        ->default('tryhackx-topic-rating.enabled', true)
        ->default('tryhackx-topic-rating.allow_unactivated', false),
];
