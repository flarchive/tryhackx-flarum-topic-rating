<?php

namespace TryHackX\TopicRating\Api\Controller;

use Flarum\Api\Controller\AbstractShowController;
use Flarum\Api\Serializer\DiscussionSerializer;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;

class ToggleRatingController extends AbstractShowController
{
    public $serializer = DiscussionSerializer::class;

    protected function data(ServerRequestInterface $request, Document $document)
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $discussionId = Arr::get($request->getQueryParams(), 'id');
        $discussion = Discussion::findOrFail($discussionId);

        $actor->assertCan('discussion.rate.toggle');

        $discussion->rating_disabled = !$discussion->rating_disabled;
        $discussion->save();

        return $discussion;
    }
}
