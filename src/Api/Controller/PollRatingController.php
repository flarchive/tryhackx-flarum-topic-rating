<?php

namespace TryHackX\TopicRating\Api\Controller;

use TryHackX\TopicRating\AuthorMode;
use TryHackX\TopicRating\AuthorModeResolver;
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
    public function __construct(
        protected AuthorModeResolver $authorModes
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $discussionId = intval(Arr::get($params, 'discussion_id', 0));
        $since = Arr::get($params, 'since');

        // Only poll discussions the actor may see — otherwise aggregate ratings
        // (and their existence) leak for private/restricted threads.
        $actor = RequestUtil::getActor($request);
        $discussion = Discussion::whereVisibleTo($actor)->findOrFail($discussionId);

        $authorMode = $this->authorModes->resolve($discussion);

        $result = [
            'ratingAverage' => (float) $discussion->rating_average,
            'ratingCount' => (int) $discussion->rating_count,
            'lastRatedAt' => $discussion->last_rated_at
                ? (is_string($discussion->last_rated_at) ? $discussion->last_rated_at : $discussion->last_rated_at->toIso8601String())
                : null,
            'ratingDisabled' => (bool) $discussion->rating_disabled,
            'ratingAuthorMode' => $authorMode,
            'authorRating' => AuthorMode::exposesAuthorRating($authorMode) && $discussion->author_rating !== null
                ? (int) $discussion->author_rating
                : null,
        ];

        // Every stored rating (what "Reset All Ratings" deletes) — only for those
        // who may reset, like the matching Discussion attribute.
        if ($actor->hasPermission('discussion.rate.reset')) {
            $result['ratingTotalCount'] = (int) $discussion->community_rating_count + ($discussion->author_rating !== null ? 1 : 0);
        }

        if ($actor->id) {
            $userRating = Rating::where('discussion_id', $discussionId)
                ->where('user_id', $actor->id)
                ->first();
            $result['userRating'] = $userRating ? (int) $userRating->rating : null;
        }

        // Server clock, taken BEFORE the "anything new?" check: the client sends it
        // back as the next `since`, so polling never depends on the visitor's
        // (possibly skewed) clock and nothing written after this check is missed.
        $result['serverTime'] = gmdate('Y-m-d\TH:i:s\Z');

        // `since` comes from the client; only use it when it parses as a date, and
        // compare in the database's own format (UTC, like every Flarum timestamp)
        // — a raw ISO string compares as text on SQLite. `>=` because timestamps
        // have one-second precision: a duplicate refresh beats a missed rating.
        if (is_string($since) && $since !== '' && ($sinceTime = strtotime($since)) !== false) {
            $newRatingsCount = Rating::where('discussion_id', $discussionId)
                ->where('updated_at', '>=', gmdate('Y-m-d H:i:s', $sinceTime))
                ->count();
            $result['hasNewRatings'] = $newRatingsCount > 0;
        }

        return new JsonResponse($result);
    }
}
