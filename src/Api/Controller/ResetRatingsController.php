<?php

namespace TryHackX\TopicRating\Api\Controller;

use TryHackX\TopicRating\Rating;
use TryHackX\TopicRating\RatingRecalculator;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ResetRatingsController implements RequestHandlerInterface
{
    public function __construct(
        protected RatingRecalculator $recalculator
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $discussionId = Arr::get($request->getQueryParams(), 'id');
        $discussion = Discussion::whereVisibleTo($actor)->findOrFail($discussionId);

        $actor->assertCan('discussion.rate.reset');

        Rating::where('discussion_id', $discussion->id)->delete();

        $this->recalculator->recalculate($discussion);

        return new JsonResponse([
            'success' => true,
        ]);
    }
}
