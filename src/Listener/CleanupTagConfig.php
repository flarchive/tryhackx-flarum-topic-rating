<?php

namespace TryHackX\TopicRating\Listener;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Tags\Event\Deleting as TagDeleting;

/**
 * Strip any orphan entries from `tryhackx-topic-rating.tag_config` when a tag
 * is deleted. Without this, the setting JSON keeps growing with dead IDs.
 *
 * Cleaned keys:
 *   - "{tagId}"                 — when the deleted tag was used standalone
 *   - "{tagId}_{secondaryId}"   — when the deleted tag was the primary
 *   - "{primaryId}_{tagId}"     — when the deleted tag was a secondary
 */
class CleanupTagConfig
{
    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(TagDeleting $event): void
    {
        $tagId = (string) $event->tag->id;
        if ($tagId === '') {
            return;
        }

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
}
