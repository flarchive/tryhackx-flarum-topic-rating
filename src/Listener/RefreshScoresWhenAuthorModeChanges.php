<?php

namespace TryHackX\TopicRating\Listener;

use Flarum\Extension\Event\Disabled;
use Flarum\Extension\Event\Enabled;
use Flarum\Settings\Event\Saved;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Queue;
use TryHackX\TopicRating\AuthorMode;
use TryHackX\TopicRating\AuthorModeResolver;
use TryHackX\TopicRating\Job\RefreshEffectiveScores;

/**
 * The stored rating_count / rating_average depend on the author mode, so they
 * must be re-derived whenever what decides a discussion's mode changes:
 *  - the mode or its tag scope is saved in the admin panel;
 *  - flarum/tags is enabled or disabled while a tag scope is configured (the
 *    scope only applies while Tags is on).
 * (A single discussion being retagged is handled by RefreshScoreWhenRetagged.)
 */
class RefreshScoresWhenAuthorModeChanges
{
    // The container, not the queue itself: subscribers are built while the app
    // boots, so a direct Queue dependency would be constructed on every request.
    public function __construct(
        protected Container $container,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Saved::class, $this->whenSettingsSaved(...));
        $events->listen(Enabled::class, $this->whenExtensionToggled(...));
        $events->listen(Disabled::class, $this->whenExtensionToggled(...));
    }

    public function whenSettingsSaved(Saved $event): void
    {
        if (array_key_exists(AuthorModeResolver::MODE_SETTING, $event->settings)
            || array_key_exists(AuthorModeResolver::TAGS_SETTING, $event->settings)) {
            $this->refresh();
        }
    }

    public function whenExtensionToggled(Enabled|Disabled $event): void
    {
        if ($event->extension->getId() !== 'flarum-tags') {
            return;
        }

        $mode = AuthorMode::normalize($this->settings->get(AuthorModeResolver::MODE_SETTING));
        $scope = AuthorModeResolver::parseTagIds($this->settings->get(AuthorModeResolver::TAGS_SETTING, '[]'));

        if ($mode !== AuthorMode::DEFAULT && $scope) {
            $this->refresh();
        }
    }

    protected function refresh(): void
    {
        $this->container->make(Queue::class)->push(new RefreshEffectiveScores());
    }
}
