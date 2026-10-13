<?php

namespace TryHackX\TopicRating\Listener;

use Flarum\User\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use TryHackX\TopicRating\Rating;
use TryHackX\TopicRating\RatingRecalculator;

/**
 * When a user account is deleted, its ratings go too (the foreign key cascades),
 * but nothing recomputed the cached aggregates of the discussions it had rated —
 * their averages/counts kept counting a vote that no longer exists.
 *
 * Hooked on the Eloquent model events so every deletion path is covered (admin,
 * API, GDPR erasure): `deleting` notes which discussions the user rated (the rows
 * are gone afterwards); `deleted` — i.e. only once the account really is gone —
 * removes the ratings explicitly (so it doesn't depend on the database enforcing
 * the cascade) and recomputes those discussions. A deletion that fails leaves the
 * ratings untouched.
 */
class ReleaseRatingsOfDeletedUser
{
    /** @var array<int, array<int, int>> user id => rated discussion ids */
    protected array $pending = [];

    // The container, not the recalculator itself: subscribers are built while the
    // app boots, and this one is only needed when an account is deleted.
    public function __construct(
        protected Container $container
    ) {
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen('eloquent.deleting: ' . User::class, $this->deleting(...));
        $events->listen('eloquent.deleted: ' . User::class, $this->deleted(...));
    }

    public function deleting(User $user): void
    {
        if (! $user->id) {
            return;
        }

        $ids = Rating::query()->where('user_id', $user->id)->pluck('discussion_id')->all();
        if ($ids) {
            $this->pending[(int) $user->id] = $ids;
        }
    }

    public function deleted(User $user): void
    {
        $ids = $this->pending[(int) $user->id] ?? [];
        unset($this->pending[(int) $user->id]);

        if (! $ids) {
            return;
        }

        Rating::query()->where('user_id', $user->id)->delete();

        $this->container->make(RatingRecalculator::class)->recalculateMany($ids);
    }
}
