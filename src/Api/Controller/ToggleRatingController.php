<?php

namespace TryHackX\TopicRating\Api\Controller;

use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ToggleRatingController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $discussionId = Arr::get($request->getQueryParams(), 'id');
        $discussion = Discussion::whereVisibleTo($actor)->findOrFail($discussionId);

        $actor->assertCan('discussion.rate.toggle');

        $discussion->rating_disabled = !$discussion->rating_disabled;
        $discussion->save();

        return new JsonResponse([
            'ratingDisabled' => (bool) $discussion->rating_disabled,
        ]);
    }
}
