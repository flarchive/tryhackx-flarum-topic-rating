<?php

namespace TryHackX\TopicRating\Listener;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Tags\Event\Deleting as TagDeleting;
use Illuminate\Contracts\Queue\Queue;
use TryHackX\TopicRating\AuthorMode;
use TryHackX\TopicRating\AuthorModeResolver;
use TryHackX\TopicRating\Job\RefreshEffectiveScores;

/**
 * Strip a deleted tag from this extension's tag-based settings, so they don't
 * keep growing with dead IDs (or silently change meaning).
 *
 * `tryhackx-topic-rating.tag_config` — cleaned keys:
 *   - "{tagId}"                 — when the deleted tag was used standalone
 *   - "{tagId}_{secondaryId}"   — when the deleted tag was the primary
 *   - "{primaryId}_{tagId}"     — when the deleted tag was a secondary
 *
 * `tryhackx-topic-rating.author_mode_tags` — the id is removed from the author
 * mode's tag scope. If it was the last one, the mode is switched off instead of
 * letting an empty scope ("every discussion") widen it to the whole forum.
 */
class CleanupTagConfig
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Queue $queue
    ) {
    }

    public function handle(TagDeleting $event): void
    {
        $tagId = (string) $event->tag->id;
        if ($tagId === '') {
            return;
        }

        $this->cleanTagConfig($tagId);
        $this->cleanAuthorModeScope($tagId);
    }

    protected function cleanTagConfig(string $tagId): void
    {
        $raw = $this->settings->get('tryhackx-topic-rating.tag_config', '{}');
        $config = json_decode((string) $raw, true);
        if (! is_array($config) || empty($config)) {
            return;
        }

        $prefix = $tagId . '_';
        $suffix = '_' . $tagId;
        $changed = false;

        foreach (array_keys($config) as $key) {
            $k = (string) $key;
            if ($k === $tagId || str_starts_with($k, $prefix) || str_ends_with($k, $suffix)) {
                unset($config[$key]);
                $changed = true;
            }
        }

        if ($changed) {
            $this->settings->set('tryhackx-topic-rating.tag_config', json_encode((object) $config));
        }
    }

    protected function cleanAuthorModeScope(string $tagId): void
    {
        $scope = AuthorModeResolver::parseTagIds($this->settings->get(AuthorModeResolver::TAGS_SETTING, '[]'));
        if (! in_array($tagId, $scope, true)) {
            return;
        }

        $remaining = array_values(array_diff($scope, [$tagId]));
        $this->settings->set(AuthorModeResolver::TAGS_SETTING, json_encode($remaining));

        $mode = AuthorMode::normalize($this->settings->get(AuthorModeResolver::MODE_SETTING));
        if ($mode === AuthorMode::DEFAULT) {
            return;
        }

        if (! $remaining) {
            $this->settings->set(AuthorModeResolver::MODE_SETTING, AuthorMode::DEFAULT);
        }

        // Discussions in the deleted tag leave the scope: re-derive the scores.
        // The scope no longer lists the tag, so this is already correct while its
        // pivot rows still exist (this runs just before the delete).
        $this->queue->push(new RefreshEffectiveScores());
    }
}
