<?php

namespace TryHackX\TopicRating\Api\Controller;

use TryHackX\TopicRating\Rating;
use TryHackX\TopicRating\RatingRecalculator;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class DeleteRatingController implements RequestHandlerInterface
{
    public function __construct(
        protected RatingRecalculator $recalculator
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $data = Arr::get($request->getParsedBody(), 'data.attributes', []);
        $discussionId = intval(Arr::get($data, 'discussionId', 0));

        // Confirm the discussion is visible to the actor before touching the
        // ratings table — same ordering as CreateRatingController, so a restricted
        // or missing discussion returns 404 before any rating lookup.
        $discussion = Discussion::whereVisibleTo($actor)->findOrFail($discussionId);
        $actor->assertCan('rate', $discussion);

        $rating = Rating::where('discussion_id', $discussionId)
            ->where('user_id', $actor->id)
            ->firstOrFail();

        $rating->delete();

        $this->recalculator->recalculate($discussion);

        return new EmptyResponse(204);
    }
}
