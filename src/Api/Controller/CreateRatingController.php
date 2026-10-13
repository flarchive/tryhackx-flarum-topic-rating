<?php

namespace TryHackX\TopicRating\Api\Controller;

use TryHackX\TopicRating\Rating;
use TryHackX\TopicRating\RatingRecalculator;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CreateRatingController implements RequestHandlerInterface
{
    public function __construct(
        protected TranslatorInterface $translator,
        protected RatingRecalculator $recalculator
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $data = Arr::get($request->getParsedBody(), 'data.attributes', []);
        $discussionId = intval(Arr::get($data, 'discussionId', 0));
        $ratingValue = intval(Arr::get($data, 'rating', 0));

        if ($ratingValue < 1 || $ratingValue > 10) {
            throw new \Flarum\Foundation\ValidationException([
                'rating' => $this->translator->trans('tryhackx-topic-rating.api.rating_out_of_range'),
            ]);
        }

        // Scope to discussions the actor can actually see — you shouldn't be able
        // to rate a thread you can't view, even if you know its id.
        $discussion = Discussion::whereVisibleTo($actor)->findOrFail($discussionId);

        $actor->assertCan('rate', $discussion);

        if ($discussion->rating_disabled) {
            throw new \Flarum\Foundation\ValidationException([
                'rating' => $this->translator->trans('tryhackx-topic-rating.api.rating_disabled'),
            ]);
        }

        $rating = Rating::where('discussion_id', $discussionId)
            ->where('user_id', $actor->id)
            ->first();

        if (! $rating) {
            $rating = new Rating();
            $rating->discussion_id = $discussionId;
            $rating->user_id = $actor->id;
        }
        $rating->rating = $ratingValue;

        try {
            $rating->save();
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Lost a concurrent first-rate race for this (discussion, user) — two
            // tabs racing the initial insert would otherwise 500 on the unique
            // (discussion_id, user_id) index. The row now exists; update it.
            $rating = Rating::where('discussion_id', $discussionId)
                ->where('user_id', $actor->id)
                ->firstOrFail();
            $rating->rating = $ratingValue;
            $rating->save();
        }

        $this->recalculator->recalculate($discussion);

        return new JsonResponse([
            'rating' => (int) $rating->rating,
            'discussionId' => $discussionId,
        ]);
    }
}
