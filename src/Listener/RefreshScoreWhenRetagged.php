<?php

namespace TryHackX\TopicRating\Listener;

use Flarum\Tags\Event\DiscussionWasTagged;
use TryHackX\TopicRating\AuthorMode;
use TryHackX\TopicRating\AuthorModeResolver;
use TryHackX\TopicRating\RatingRecalculator;

/**
 * With a tag-scoped author mode, moving a discussion into or out of the scoped
 * tags changes which mode applies to it — re-derive its stored score. Nothing to
 * do without a scope (the mode then doesn't depend on tags). Class-resolved, so
 * it costs nothing when flarum/tags isn't installed (the event never fires).
 */
class RefreshScoreWhenRetagged
{
    public function __construct(
        protected AuthorModeResolver $modes,
        protected RatingRecalculator $recalculator
    ) {
    }

    public function handle(DiscussionWasTagged $event): void
    {
        if ($this->modes->configuredMode() === AuthorMode::DEFAULT || ! $this->modes->scopeTagIds()) {
            return;
        }

        $this->recalculator->refreshEffective([(int) $event->discussion->id]);
    }
}
