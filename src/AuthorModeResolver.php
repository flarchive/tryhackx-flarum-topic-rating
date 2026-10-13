<?php

namespace TryHackX\TopicRating;

use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Resolves the {@see AuthorMode} that applies to a given discussion.
 *
 * The admin picks one mode (`tryhackx-topic-rating.author_mode`) and may limit it
 * to discussions carrying at least one of a set of tags
 * (`tryhackx-topic-rating.author_mode_tags`, a JSON id list — empty = every
 * discussion). Discussions outside that set use {@see AuthorMode::DEFAULT}. The
 * tag limit is only honoured while flarum/tags is enabled; without Tags the admin
 * UI hides the picker and the mode applies to every discussion.
 *
 * Deliberately NOT memoized: settings come from Flarum's in-memory settings cache
 * anyway, and the policy that holds this resolver lives for the whole application
 * lifetime (Gate caches policy instances), so a memo would go stale the moment an
 * admin changes the mode. (Writes don't go through this class: RatingRecalculator
 * applies the same rule in SQL, from freshly read settings.)
 */
class AuthorModeResolver
{
    public const MODE_SETTING = 'tryhackx-topic-rating.author_mode';
    public const TAGS_SETTING = 'tryhackx-topic-rating.author_mode_tags';

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ExtensionManager $extensions
    ) {
    }

    public function configuredMode(): string
    {
        return AuthorMode::normalize($this->settings->get(self::MODE_SETTING, AuthorMode::DEFAULT));
    }

    /**
     * The tag ids the mode is limited to, as strings. Empty means "every
     * discussion" — also whenever flarum/tags is disabled.
     *
     * @return array<int, string>
     */
    public function scopeTagIds(): array
    {
        if (! $this->extensions->isEnabled('flarum-tags')) {
            return [];
        }

        return self::parseTagIds($this->settings->get(self::TAGS_SETTING, '[]'));
    }

    /**
     * The mode for one discussion.
     */
    public function resolve(Discussion $discussion): string
    {
        $mode = $this->configuredMode();
        if ($mode === AuthorMode::DEFAULT) {
            return $mode;
        }

        $scope = $this->scopeTagIds();
        if (! $scope) {
            return $mode;
        }

        return array_intersect($this->discussionTagIds($discussion), $scope)
            ? $mode
            : AuthorMode::DEFAULT;
    }

    /**
     * Normalise the stored tag-scope value (JSON array of ids) to a list of
     * unique, positive, string ids. Anything malformed counts as "no limit".
     *
     * @return array<int, string>
     */
    public static function parseTagIds(mixed $raw): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($decoded)) {
            return [];
        }

        $ids = [];
        foreach ($decoded as $id) {
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0) {
                $ids[(string) (int) $id] = true;
            }
        }

        return array_map('strval', array_keys($ids));
    }

    /**
     * @return array<int, string>
     */
    protected function discussionTagIds(Discussion $discussion): array
    {
        if ($discussion->relationLoaded('tags')) {
            return $discussion->getRelation('tags')->pluck('id')->map(fn ($id) => (string) $id)->all();
        }

        if (! $discussion->exists) {
            return [];
        }

        // Straight from the pivot — cheaper than hydrating Tag models (Tags
        // unsets the relation after a retag, so this is never stale either).
        return $discussion->getConnection()
            ->table('discussion_tag')
            ->where('discussion_id', $discussion->id)
            ->pluck('tag_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }
}
