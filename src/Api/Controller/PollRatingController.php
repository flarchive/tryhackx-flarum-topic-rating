<?php

namespace TryHackX\TopicRating\Api\Controller;

use TryHackX\TopicRating\Rating;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class PollRatingController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $discussionId = intval(Arr::get($params, 'discussion_id', 0));
        $since = Arr::get($params, 'since');

        $discussion = Discussion::findOrFail($discussionId);

        $result = [
            'ratingAverage' => (float) $discussion->rating_average,
            'ratingCount' => (int) $discussion->rating_count,
            'lastRatedAt' => $discussion->last_rated_at
                ? (is_string($discussion->last_rated_at) ? $discussion->last_rated_at : $discussion->last_rated_at->toIso8601String())
                : null,
            'ratingDisabled' => (bool) $discussion->rating_disabled,
        ];

        $actor = RequestUtil::getActor($request);
        if ($actor->id) {
            $userRating = Rating::where('discussion_id', $discussionId)
                ->where('user_id', $actor->id)
                ->first();
            $result['userRating'] = $userRating ? (int) $userRating->rating : null;
        }

        if ($since) {
            $newRatingsCount = Rating::where('discussion_id', $discussionId)
                ->where('updated_at', '>', $since)
                ->count();
            $result['hasNewRatings'] = $newRatingsCount > 0;
        }

        return new JsonResponse($result);
    }
}
