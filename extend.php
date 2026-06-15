<?php

use TryHackX\TopicRating\Api\Controller;
use TryHackX\TopicRating\Api\DiscussionRatingFields;
use TryHackX\TopicRating\Api\Resource\RatingResource;
use TryHackX\TopicRating\Access\RatingPolicy;
use TryHackX\TopicRating\Listener\CleanupTagConfig;
use TryHackX\TopicRating\Rating;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Discussion\Discussion;
use Flarum\Extend;

// Shared avatar-display mode, coordinated with flarum-thumb-sliders. Both
// extensions serialize the same neutral `tryhackx-avatars.*` keys so the value
// is shared whether one or both are installed. Defaults are baked in here
// (NOT ->default(), which throws when both extensions register the same key);
// the duplicate serializer is safe because ForumResource fields are keyed by
// attribute name (last-wins) and both extensions yield the identical value.
$normalizeAvatarMode = function ($value) {
    $v = is_string($value) ? $value : '';

    return in_array($v, ['show', 'with_image', 'always', 'hide'], true) ? $v : 'show';
};

$normalizeListStyle = function ($value) {
    $v = is_string($value) ? $value : '';
    $allowed = ['full_meta', 'full_title', 'single_filled_meta', 'single_filled_title', 'single_bucket_meta', 'single_bucket_title'];

    return in_array($v, $allowed, true) ? $v : 'full_meta';
};

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/less/admin.less'),

    new Extend\Locales(__DIR__ . '/locale'),

    (new Extend\Model(Discussion::class))
        ->hasMany('ratings', Rating::class, 'discussion_id')
        ->cast('last_rated_at', 'datetime')
        ->cast('rating_disabled', 'boolean'),

    // Register RatingResource (handles listing via Index endpoint)
    (new Extend\ApiResource(RatingResource::class)),

    // Extend DiscussionResource with rating fields (definitions live in a
    // dedicated, constructor-injected class — see DiscussionRatingFields).
    (new Extend\ApiResource(DiscussionResource::class))
        ->fields(DiscussionRatingFields::class),

    // Custom API routes
    (new Extend\Routes('api'))
        ->post('/discussion-ratings', 'discussion-ratings.create', Controller\CreateRatingController::class)
        ->delete('/discussion-ratings', 'discussion-ratings.delete', Controller\DeleteRatingController::class)
        ->post('/discussions/{id}/toggle-rating', 'discussions.toggle-rating', Controller\ToggleRatingController::class)
        ->post('/discussions/{id}/reset-ratings', 'discussions.reset-ratings', Controller\ResetRatingsController::class)
        ->get('/discussion-ratings/poll', 'discussion-ratings.poll', Controller\PollRatingController::class),

    (new Extend\Policy())
        ->modelPolicy(Discussion::class, RatingPolicy::class),

    // Orphan-cleanup: drop tag_config entries when a tag is deleted from
    // flarum/tags. The listener is class-resolved, so it costs nothing
    // when flarum-tags isn't installed (event class simply never fires).
    (new Extend\Event())
        ->listen(\Flarum\Tags\Event\Deleting::class, CleanupTagConfig::class),

    (new Extend\Settings())
        ->serializeToForum('tryhackxTopicRatingEnabled', 'tryhackx-topic-rating.enabled', 'boolval', true)
        ->serializeToForum('tryhackxTopicRatingAllowUnactivated', 'tryhackx-topic-rating.allow_unactivated', 'boolval', false)
        ->serializeToForum('tryhackxTopicRatingShowOnList', 'tryhackx-topic-rating.show_on_list', 'boolval', true)
        ->serializeToForum('tryhackxTopicRatingRateOnList', 'tryhackx-topic-rating.rate_on_list', 'boolval', true)
        ->serializeToForum('tryhackxTopicRatingDisplayWhenRestricted', 'tryhackx-topic-rating.display_when_restricted', 'strval', 'readonly')
        // NB: bypass_groups is deliberately NOT serialized to the forum — the
        // frontend never needs the raw group-id list (the backend already
        // resolves canRate / ratingDisplayMode), and exposing it leaked which
        // groups have privileged rating access to every visitor.
        // Discussion-list rating display style, separately for desktop and mobile.
        ->serializeToForum('tryhackxTopicRatingListStyleDesktop', 'tryhackx-topic-rating.list_style_desktop', $normalizeListStyle)
        ->serializeToForum('tryhackxTopicRatingListStyleMobile', 'tryhackx-topic-rating.list_style_mobile', $normalizeListStyle)
        // Single-star (compact) behaviour: whether the star is clickable to open
        // the ratings modal, and whether that modal lets the user rate.
        ->serializeToForum('tryhackxTopicRatingSingleClickable', 'tryhackx-topic-rating.single_clickable', 'boolval', true)
        ->serializeToForum('tryhackxTopicRatingSingleModalRate', 'tryhackx-topic-rating.single_modal_rate', 'boolval', false)
        // On the discussion list, hide the (empty) widget for people who can't
        // rate when a topic has zero ratings yet. Default false = keep showing.
        ->serializeToForum('tryhackxTopicRatingHideEmptyForNonVoters', 'tryhackx-topic-rating.hide_empty_for_nonvoters', 'boolval', false)
        // Add the rating moderation items (Disable Rating / Reset All Ratings)
        // to the discussion's ⋮ controls menu. Handy for managing ratings when
        // the star widget is hidden. Default false = not shown in the menu.
        ->serializeToForum('tryhackxTopicRatingShowModerationControls', 'tryhackx-topic-rating.show_moderation_controls', 'boolval', false)
        // Master switch for live (polled) rating updates. Default ON; when off no
        // background polling runs at all (neither the discussion page nor the modal)
        // — ratings still load and update on the user's own action, just not live.
        ->serializeToForum('tryhackxTopicRatingRealtimeEnabled', 'tryhackx-topic-rating.realtime_enabled', 'boolval', true)
        // Discussion-page live-poll base interval in seconds (clamped 5–300, default
        // 8). Lets large forums dial back the per-visitor polling load. Read by
        // RatingPolling.js; the modal poll keeps its own faster cadence.
        ->serializeToForum('tryhackxTopicRatingPollInterval', 'tryhackx-topic-rating.poll_interval', function ($value) {
            if ($value === null || $value === '') {
                return 8;
            }
            return max(5, min(300, (int) $value));
        })
        // Shared avatar-display mode (see note at top of file). No ->default().
        ->serializeToForum('tryhackxAvatarModeDesktop', 'tryhackx-avatars.mode_desktop', $normalizeAvatarMode)
        ->serializeToForum('tryhackxAvatarModeMobile', 'tryhackx-avatars.mode_mobile', $normalizeAvatarMode)
        ->default('tryhackx-topic-rating.enabled', true)
        ->default('tryhackx-topic-rating.allow_unactivated', false)
        ->default('tryhackx-topic-rating.show_on_list', true)
        ->default('tryhackx-topic-rating.rate_on_list', true)
        ->default('tryhackx-topic-rating.tag_config', '{}')
        ->default('tryhackx-topic-rating.display_when_restricted', 'readonly')
        ->default('tryhackx-topic-rating.bypass_groups', '["1"]')
        ->default('tryhackx-topic-rating.list_style_desktop', 'full_meta')
        ->default('tryhackx-topic-rating.list_style_mobile', 'full_meta')
        ->default('tryhackx-topic-rating.single_clickable', true)
        ->default('tryhackx-topic-rating.single_modal_rate', false)
        ->default('tryhackx-topic-rating.untagged_enabled', true)
        ->default('tryhackx-topic-rating.hide_empty_for_nonvoters', false)
        ->default('tryhackx-topic-rating.hide_disabled_ratings', true)
        ->default('tryhackx-topic-rating.show_moderation_controls', false)
        ->default('tryhackx-topic-rating.realtime_enabled', true)
        ->default('tryhackx-topic-rating.poll_interval', 8),
];
