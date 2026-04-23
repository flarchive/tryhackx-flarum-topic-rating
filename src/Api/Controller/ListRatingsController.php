<?php

namespace TryHackX\TopicRating\Api\Controller;

use TryHackX\TopicRating\Api\Serializer\RatingSerializer;
use TryHackX\TopicRating\Rating;
use Flarum\Api\Controller\AbstractListController;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;

class ListRatingsController extends AbstractListController
{
    public $serializer = RatingSerializer::class;

    public $include = ['user'];

    public $sortFields = ['createdAt'];

    public $sort = ['createdAt' => 'desc'];

    public $limit = 10;

    protected $url;

    public function __construct(UrlGenerator $url)
    {
        $this->url = $url;
    }

    protected function data(ServerRequestInterface $request, Document $document)
    {
        $actor = RequestUtil::getActor($request);
        $params = $request->getQueryParams();

        $discussionId = intval(
            $params['discussion_id']
            ?? $params['filter']['discussion_id']
            ?? 0
        );

        if ($discussionId === 0) {
            return [];
        }

        $limit = $this->extractLimit($request);
        $offset = $this->extractOffset($request);

        $total = Rating::where('discussion_id', $discussionId)->count();

        $ratings = Rating::where('discussion_id', $discussionId)
            ->orderBy('created_at', 'desc')
            ->skip($offset)
            ->take($limit)
            ->get()
            ->load('user');

        $document->addPaginationLinks(
            $this->url->to('api')->route('discussion-ratings.index'),
            ['discussion_id' => $discussionId],
            $offset,
            $limit,
            $total
        );

        $document->addMeta('total', $total);

        return $ratings;
    }
}
