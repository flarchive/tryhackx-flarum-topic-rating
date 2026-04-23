<?php

namespace TryHackX\TopicRating\Api\Controller;

use TryHackX\TopicRating\Rating;
use Flarum\Api\Controller\AbstractDeleteController;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

class DeleteRatingController extends AbstractDeleteController
{
    protected function delete(ServerRequestInterface $request)
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $data = Arr::get($request->getParsedBody(), 'data.attributes', []);
        $discussionId = intval(Arr::get($data, 'discussionId', 0));

        $rating = Rating::where('discussion_id', $discussionId)
            ->where('user_id', $actor->id)
            ->firstOrFail();

        $discussion = Discussion::findOrFail($discussionId);
        $actor->assertCan('rate', $discussion);

        $rating->delete();

        $ratings = Rating::where('discussion_id', $discussion->id);
        $discussion->rating_count = $ratings->count();
        $discussion->rating_average = $discussion->rating_count > 0
            ? round($ratings->avg('rating') / 2, 2)
            : 0;
        $discussion->last_rated_at = Rating::where('discussion_id', $discussion->id)
            ->max('updated_at');
        $discussion->save();
    }
}
