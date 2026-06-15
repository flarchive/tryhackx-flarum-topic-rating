<?php

namespace TryHackX\TopicRating\Access;

use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

class RatingPolicy extends AbstractPolicy
{
    /** Per-request memoization (Gate caches policy instances within a request). */
    protected ?array $cachedTagConfig = null;
    protected ?array $cachedBypassGroups = null;
    /** @var array<int|string, array<int, string>> */
    protected array $cachedActorGroupIds = [];

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ExtensionManager $extensions
    ) {
    }

    public function rate(User $actor, Discussion $discussion)
    {
        if ($discussion->rating_disabled) {
            return $this->deny();
        }

        // Bypass-group short-circuit — costs one JSON parse per request thanks
        // to memoization, then it's O(1) intersect of small arrays.
        if ($this->actorBypassesGlobal($actor)) {
            return $this->allowIfActivated($actor);
        }

        $hasLegacyRate = $this->actorHasPermission($actor, 'discussion.rate');
        $tagsOn = $this->extensions->isEnabled('flarum-tags');
        $tagConfig = $this->getTagConfig();

        if (! $tagsOn) {
            return $this->legacyDecide($actor, $hasLegacyRate);
        }

        $tags = $this->loadDiscussionTags($discussion);

        // Tagless discussions are governed by their own admin switch. When it is
        // off they can't be rated at all; otherwise they fall back to the legacy
        // (global Rate permission) decision.
        if ($tags->isEmpty()) {
            if (! $this->untaggedRatingEnabled()) {
                return $this->deny();
            }
            return $this->legacyDecide($actor, $hasLegacyRate);
        }

        if (empty($tagConfig)) {
            return $this->legacyDecide($actor, $hasLegacyRate);
        }

        $resolvedStates = $this->resolveDiscussionStates($tags, $tagConfig);
        $actorGroupIds = $this->actorGroupIds($actor);

        foreach ($resolvedStates as $resolved) {
            $tag = $resolved['tag'];
            $config = $resolved['config'];
            $state = $config['state'];

            // Per-tag bypass permission (legacy — set via PermissionGrid on tag$id.discussion.rate.bypass).
            if ($this->actorBypassesTag($actor, $tag)) {
                return $this->allowIfActivated($actor);
            }

            if ($state === 'enabled') {
                if ($hasLegacyRate) {
                    return $this->allowIfActivated($actor);
                }
            } elseif ($state === 'groups') {
                $allowedGroups = array_map(fn ($g) => (string) $g, $config['groups'] ?? []);
                if ($actorGroupIds && array_intersect($actorGroupIds, $allowedGroups)) {
                    return $this->allowIfActivated($actor);
                }
            }
        }

        return $this->deny();
    }

    /**
     * Returns true when every resolved (primary × secondary) combination on the
     * discussion is `disabled`. Used by the `ratingDisplayMode` serializer field
     * to decide between hidden/message/readonly when the actor can't rate.
     */
    public function isRatingDisabled(Discussion $discussion): bool
    {
        if ($discussion->rating_disabled) {
            return true;
        }

        if (! $this->extensions->isEnabled('flarum-tags')) {
            return false;
        }

        $tags = $this->loadDiscussionTags($discussion);
        if ($tags->isEmpty()) {
            // Tagless discussions: disabled only when the admin switched off
            // rating for them.
            return ! $this->untaggedRatingEnabled();
        }

        $tagConfig = $this->getTagConfig();
        if (empty($tagConfig)) {
            return false;
        }

        $resolvedStates = $this->resolveDiscussionStates($tags, $tagConfig);

        foreach ($resolvedStates as $resolved) {
            if (($resolved['config']['state'] ?? null) !== 'disabled') {
                return false;
            }
        }

        return true;
    }

    /**
     * Admin switch: may discussions that carry NO tags at all be rated /
     * show the rating widget? Default true (legacy behaviour).
     */
    public function untaggedRatingEnabled(): bool
    {
        return (bool) $this->settings->get('tryhackx-topic-rating.untagged_enabled', true);
    }

    /**
     * True when the rating widget should be removed entirely (not just made
     * read-only) by configuration — currently: a tagless discussion while
     * "allow rating on untagged discussions" is off. Consumed by the
     * `ratingDisplayMode` serializer field to return `hidden`.
     */
    public function ratingForcedHidden(Discussion $discussion): bool
    {
        if (! $this->extensions->isEnabled('flarum-tags')) {
            return false;
        }
        if ($this->untaggedRatingEnabled()) {
            return false;
        }

        return $this->loadDiscussionTags($discussion)->isEmpty();
    }

    /**
     * True when the actor is in one of the groups picked in
     * `tryhackx-topic-rating.bypass_groups`. Public so the API serializer
     * and downstream code don't have to re-implement the parsing.
     */
    public function actorBypassesGlobal(User $actor): bool
    {
        $bypassGroups = $this->getBypassGroups();
        if (! $bypassGroups) {
            return false;
        }
        return (bool) array_intersect($this->actorGroupIds($actor), $bypassGroups);
    }

    /**
     * Per-tag bypass via the standard Flarum permission key pattern
     * `tag{id}.discussion.rate.bypass`. Kept for power-users who want
     * permission-grid level granularity on top of the group picker.
     */
    public function actorBypassesTag(User $actor, $tag): bool
    {
        if ($tag && isset($tag->id)) {
            return $this->actorHasPermission($actor, 'tag'.$tag->id.'.discussion.rate.bypass');
        }
        return false;
    }

    /**
     * Walk discussion tags into (primary × secondary) state combinations.
     * Returns array of ['tag' => Tag|null, 'config' => ['state' => ..., 'groups' => [...]]]
     */
    protected function resolveDiscussionStates($discussionTags, array $tagConfig): array
    {
        $primaryTags = $discussionTags->filter(fn ($t) => (bool) $t->is_primary);
        $secondaryTags = $discussionTags->filter(fn ($t) => ! (bool) $t->is_primary);

        $resolved = [];

        if ($primaryTags->isEmpty()) {
            if ($secondaryTags->isEmpty()) {
                return [['tag' => null, 'config' => ['state' => 'enabled']]];
            }
            foreach ($secondaryTags as $sTag) {
                // Standalone secondary tags (a discussion with NO primary tag)
                // default to DISABLED — the admin opts specific ones back in via
                // the "Secondary tags used on their own" list. Only an explicit
                // `enabled` (stored as a bare key) permits rating.
                $entry = $tagConfig[(string) $sTag->id] ?? ['state' => 'disabled'];
                $state = $entry['state'] ?? 'disabled';
                if ($state === 'inherit') {
                    $state = 'disabled';
                }
                $entry['state'] = $state;
                $resolved[] = ['tag' => $sTag, 'config' => $entry];
            }
            return $resolved;
        }

        foreach ($primaryTags as $pTag) {
            $pTagId = (string) $pTag->id;
            $pEntry = $tagConfig[$pTagId] ?? ['state' => 'enabled'];
            $pState = $pEntry['state'] ?? 'enabled';
            if ($pState === 'inherit') {
                $pState = 'enabled';
            }
            $pEntry['state'] = $pState;

            if ($secondaryTags->isEmpty()) {
                $resolved[] = ['tag' => $pTag, 'config' => $pEntry];
                continue;
            }

            foreach ($secondaryTags as $sTag) {
                $compoundKey = $pTagId . '_' . (string) $sTag->id;
                $entry = $tagConfig[$compoundKey] ?? ['state' => 'inherit'];
                $state = $entry['state'] ?? 'inherit';

                if ($state === 'inherit') {
                    $resolved[] = ['tag' => $sTag, 'config' => $pEntry];
                } else {
                    $entry['state'] = $state;
                    $resolved[] = ['tag' => $sTag, 'config' => $entry];
                }
            }
        }

        return $resolved;
    }

    protected function legacyDecide(User $actor, bool $hasLegacyRate)
    {
        // Always route through allowIfActivated so the email-activation gate
        // applies consistently on both the legacy and the tag-aware paths.
        // (Pre-2.1 the legacy path skipped activation when the user already
        // had `discussion.rate` — leaving an inconsistent loophole.)
        return $this->allowIfActivated($actor, $hasLegacyRate);
    }

    protected function allowIfActivated(User $actor, bool $hasLegacyRate = true)
    {
        $bypass = $this->actorBypassesGlobal($actor);
        $emailUnconfirmed = $actor->id && ! $actor->is_email_confirmed;

        if ($emailUnconfirmed) {
            $allowUnactivated = (bool) $this->settings->get('tryhackx-topic-rating.allow_unactivated', false);
            return ($bypass || $allowUnactivated) ? $this->allow() : $this->deny();
        }

        return ($hasLegacyRate || $bypass) ? $this->allow() : $this->deny();
    }

    /**
     * Like User::hasPermission(), but Flarum's "admin sees everything" shortcut
     * is gated by the bypass-group picker. When the Admin group is NOT in the
     * bypass list, an admin account doesn't auto-pass this extension's checks.
     */
    protected function actorHasPermission(User $actor, string $permission): bool
    {
        if ($actor->isAdmin()) {
            $bypassGroups = $this->getBypassGroups();
            if (in_array('1', $bypassGroups, true)) {
                return true;
            }
            return in_array($permission, $actor->getPermissions(), true);
        }

        return $actor->hasPermission($permission);
    }

    /**
     * Parse `tryhackx-topic-rating.tag_config` once per request.
     */
    protected function getTagConfig(): array
    {
        if ($this->cachedTagConfig !== null) {
            return $this->cachedTagConfig;
        }
        $raw = $this->settings->get('tryhackx-topic-rating.tag_config', '{}');
        $decoded = json_decode((string) $raw, true);
        return $this->cachedTagConfig = is_array($decoded) ? $decoded : [];
    }

    /**
     * Parse `tryhackx-topic-rating.bypass_groups` once per request.
     * @return array<int, string>
     */
    protected function getBypassGroups(): array
    {
        if ($this->cachedBypassGroups !== null) {
            return $this->cachedBypassGroups;
        }
        $raw = $this->settings->get('tryhackx-topic-rating.bypass_groups', '["1"]');
        $decoded = json_decode((string) $raw, true);
        return $this->cachedBypassGroups = is_array($decoded)
            ? array_map(fn ($g) => (string) $g, $decoded)
            : [];
    }

    /**
     * Memoize actor → group-ids list (admin auto-includes group '1').
     * @return array<int, string>
     */
    protected function actorGroupIds(User $actor): array
    {
        $key = (string) ($actor->id ?? 'guest');
        if (isset($this->cachedActorGroupIds[$key])) {
            return $this->cachedActorGroupIds[$key];
        }
        $ids = $actor->id ? $actor->groups->pluck('id')->map(fn ($id) => (string) $id)->all() : [];
        if ($actor->isAdmin() && ! in_array('1', $ids, true)) {
            $ids[] = '1';
        }
        return $this->cachedActorGroupIds[$key] = $ids;
    }

    /**
     * Load the discussion's tags. Caller guarantees Tags is enabled.
     * `method_exists($discussion, 'tags')` lies because Tags registers the
     * relation through `Extend\Model` → `__call`, so we go straight through
     * the magic-method path and wrap in try/catch as a safety net.
     */
    protected function loadDiscussionTags(Discussion $discussion)
    {
        try {
            if ($discussion->relationLoaded('tags')) {
                $tags = $discussion->getRelation('tags');
                $needsParent = $tags->contains(fn ($t) => $t->parent_id && ! $t->relationLoaded('parent'));
                if ($needsParent) {
                    $tags->load('parent.parent');
                }
                return $tags;
            }
            return $discussion->tags()->with('parent.parent')->get();
        } catch (\PDOException $e) {
            // A genuine database failure (lost connection, a missing column after
            // a botched migration, …) must surface and stay diagnosable — not be
            // silently masked as "no tags", which would quietly deny rating. The
            // wider request is failing anyway. (QueryException extends PDOException,
            // so both wrapped and raw driver errors are covered.)
            throw $e;
        } catch (\Throwable $e) {
            // Tags relation genuinely unavailable / an unexpected shape (e.g. Tags
            // not installed → BadMethodCallException via the magic relation): degrade
            // gracefully so a tag hiccup never 500s the whole discussion list.
            return collect();
        }
    }
}
