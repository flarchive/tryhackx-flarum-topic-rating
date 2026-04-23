<?php

namespace TryHackX\TopicRating\Api\Controller;

use TryHackX\TopicRating\Rating;
use Flarum\Api\Controller\AbstractShowController;
use Flarum\Api\Serializer\DiscussionSerializer;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;

class ResetRatingsController extends AbstractShowController
{
    public $serializer = DiscussionSerializer::class;

    protected function data(ServerRequestInterface $request, Document $document)
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $discussionId = Arr::get($request->getQueryParams(), 'id');
        $discussion = Discussion::findOrFail($discussionId);

        $actor->assertCan('discussion.rate.reset');

        Rating::where('discussion_id', $discussion->id)->delete();

        $discussion->rating_count = 0;
        $discussion->rating_average = 0;
        $discussion->last_rated_at = null;
        $discussion->save();

        return $discussion;
    }
}
