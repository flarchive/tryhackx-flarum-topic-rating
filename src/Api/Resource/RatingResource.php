<?php

namespace TryHackX\TopicRating\Api\Resource;

use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context;
use TryHackX\TopicRating\Rating;

/**
 * @extends AbstractDatabaseResource<Rating>
 */
class RatingResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'discussion-ratings';
    }

    public function model(): string
    {
        return Rating::class;
    }

    public function scope(Builder $query, Context $context): void
    {
        // Read the plain `discussion_id` query param. (A JSON:API `filter[…]`
        // can't be used here — core rejects filters on a resource without a
        // searcher — so there's no `filter` fallback to read.)
        $discussionId = intval($context->request->getQueryParams()['discussion_id'] ?? 0);

        // This listing has exactly one legitimate use: the ratings modal for a
        // single discussion. Without a positive discussion id, expose nothing —
        // never the whole table. (An unauthenticated GET /api/discussion-ratings
        // with no parameters previously dumped every user↔rating↔discussion row.)
        if ($discussionId <= 0) {
            $query->whereRaw('1 = 0');
            return;
        }

        // And only ratings on discussions this actor is actually allowed to see,
        // so private/restricted threads don't leak their ratings to outsiders.
        $actor = RequestUtil::getActor($context->request);

        $query->where('discussion_id', $discussionId)
            ->whereIn(
                'discussion_id',
                Discussion::query()->whereVisibleTo($actor)->select('id')
            );
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->defaultSort('-createdAt')
                ->defaultInclude(['user'])
                ->paginate(10, 50),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Integer::make('rating')
                ->get(fn (Rating $rating) => (int) $rating->rating),
            Schema\DateTime::make('createdAt'),
            Schema\DateTime::make('updatedAt'),
            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable(),
            Schema\Relationship\ToOne::make('discussion')
                ->type('discussions')
                ->includable(),
        ];
    }

    public function sorts(): array
    {
        return [
            SortColumn::make('createdAt'),
        ];
    }
}
