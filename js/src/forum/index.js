import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import DiscussionHero from 'flarum/forum/components/DiscussionHero';
import DiscussionListItem from 'flarum/forum/components/DiscussionListItem';
import DiscussionPage from 'flarum/forum/components/DiscussionPage';
import DiscussionControls from 'flarum/forum/utils/DiscussionControls';
import Button from 'flarum/common/components/Button';
import CommentPost from 'flarum/forum/components/CommentPost';
import Rating from '../common/models/Rating';
import StarRating from './components/StarRating';
import LastRatedInfo from './components/LastRatedInfo';
import RatingPolling from './components/RatingPolling';
import ResetRatingsModal from './components/ResetRatingsModal';
import installDiscussionListLayout from './discussionListLayout';
import '../common/extend';

// Must mirror the @phone breakpoint used by discussionListLayout.js so the
// variant chosen here and the placement chosen there agree on the device.
const PHONE_QUERY = '(max-width: 767.98px)';

app.initializers.add('tryhackx-topic-rating', () => {
    app.store.models['discussion-ratings'] = Rating;

    // Shared, idempotent layout override coordinated with thumb-sliders + FoF views.
    installDiscussionListLayout();

    extend(DiscussionHero.prototype, 'items', function (items) {
        const discussion = this.attrs.discussion;
        if (!discussion || discussion.ratingDisabled()) return;
        if (discussion.ratingDisplayMode && discussion.ratingDisplayMode() === 'hidden') return;

        items.add('rating',
            <StarRating
                discussion={discussion}
                interactive={true}
                size="normal"
                showCount={true}
            />,
            -5
        );
    });

    extend(DiscussionListItem.prototype, 'infoItems', function (items) {
        // Admin can hide the rating on the discussion list while keeping it on
        // the discussion page. Undefined (older config) counts as enabled.
        if (app.forum.attribute('tryhackxTopicRatingShowOnList') === false) return;

        const discussion = this.attrs.discussion;
        if (!discussion || discussion.ratingDisabled()) return;
        if (discussion.ratingDisplayMode && discussion.ratingDisplayMode() === 'hidden') return;

        // Resolve the per-device display style. discussionListLayout.js reads the
        // SAME attributes to decide placement (meta column vs end of the title);
        // here we only derive the visual variant and whether rating-from-list
        // stays interactive. isPhone is computed exactly as the layout does it.
        const isPhone = window.matchMedia(PHONE_QUERY).matches;
        const style = (isPhone
            ? app.forum.attribute('tryhackxTopicRatingListStyleMobile')
            : app.forum.attribute('tryhackxTopicRatingListStyleDesktop')) || 'full_meta';

        let variant = 'full';
        if (style.indexOf('single_bucket') === 0) variant = 'single_bucket';
        else if (style.indexOf('single_filled') === 0) variant = 'single_filled';

        const afterTitle = /_title$/.test(style);
        const isCompact = variant !== 'full';

        // Single-star (compact) clickability is an admin toggle. When on, the
        // whole star + number opens the ratings modal — even after the title,
        // where StarRating captures the click so it doesn't navigate the link.
        const singleClickable = app.forum.attribute('tryhackxTopicRatingSingleClickable') !== false;
        const compactClickable = isCompact && singleClickable;
        const count = discussion.ratingCount() || 0;

        // Admin option: on the discussion list, hide the (empty) widget from
        // people who can't rate when a topic has no ratings yet. `rate` is the
        // only display mode where the viewer can rate; anything else = can't.
        const viewerCanRate = discussion.ratingDisplayMode && discussion.ratingDisplayMode() === 'rate';
        if (count === 0 && !viewerCanRate && app.forum.attribute('tryhackxTopicRatingHideEmptyForNonVoters') === true) {
            return;
        }

        // Per request: when the single star is NOT clickable, don't render a lone
        // star on topics that have no rating at all.
        if (isCompact && !compactClickable && count === 0) return;

        // Click-to-rate only survives for the full stars in the meta column. The
        // compact and after-title styles are display-only (after-title also sits
        // inside the title link, so its clicks must fall through to navigation).
        const interactive = variant === 'full' && !afterTitle
            && app.forum.attribute('tryhackxTopicRatingRateOnList') !== false;

        items.add('rating',
            <StarRating
                discussion={discussion}
                interactive={interactive}
                size="small"
                variant={variant}
                showCount={false}
                tooltip={variant === 'full' && !afterTitle}
                clickToModal={compactClickable}
            />,
            15
        );
    });

    extend(DiscussionPage.prototype, 'show', function (discussion) {
        if (discussion && !discussion.ratingDisabled()) {
            RatingPolling.start(discussion);
        }
    });

    extend(DiscussionPage.prototype, 'onremove', function () {
        RatingPolling.stop();
    });

    extend(CommentPost.prototype, 'headerItems', function (items) {
        const post = this.attrs.post;
        if (!post) return;

        const discussion = post.discussion();
        if (!discussion || !discussion.lastRatedAt()) return;

        if (post.number() === 1) {
            items.add('lastRated',
                <LastRatedInfo discussion={discussion} />,
                -10
            );
        }
    });

    extend(DiscussionControls, 'moderationControls', function (items, discussion) {
        // By default the rating moderation items FOLLOW the rating's visibility:
        // shown when the rating is shown on this topic, hidden when its tags /
        // untagged config hide it (managing a hidden rating would do nothing).
        // The "always show" admin setting overrides that. The per-topic moderator
        // toggle is exempt — so a topic disabled from here can still be re-enabled.
        const alwaysShow = app.forum.attribute('tryhackxTopicRatingShowModerationControls') === true;
        if (!alwaysShow) {
            const mode = discussion.ratingDisplayMode && discussion.ratingDisplayMode();
            if (mode === 'hidden' && !discussion.ratingDisabled()) return;
        }

        if (discussion.canToggleRating && discussion.canToggleRating()) {
            items.add('toggleRating',
                Button.component({
                    icon: discussion.ratingDisabled() ? 'fas fa-star' : 'far fa-star',
                    onclick: () => toggleRating(discussion),
                }, discussion.ratingDisabled()
                    ? app.translator.trans('tryhackx-topic-rating.forum.controls.enable_rating')
                    : app.translator.trans('tryhackx-topic-rating.forum.controls.disable_rating')
                )
            );
        }

        if (discussion.canResetRatings && discussion.canResetRatings() && discussion.ratingCount() > 0) {
            items.add('resetRatings',
                Button.component({
                    icon: 'fas fa-eraser',
                    onclick: () => resetRatings(discussion),
                }, app.translator.trans('tryhackx-topic-rating.forum.controls.reset_ratings'))
            );
        }
    });
});

function toggleRating(discussion) {
    app.request({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/discussions/' + discussion.id() + '/toggle-rating',
    }).then((response) => {
        discussion.pushData({
            attributes: {
                ratingDisabled: response.ratingDisabled,
            },
        });
        m.redraw();
    });
}

function resetRatings(discussion) {
    app.modal.show(ResetRatingsModal, { discussion });
}
