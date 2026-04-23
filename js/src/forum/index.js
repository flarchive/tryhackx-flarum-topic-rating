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
import '../common/extend';

app.initializers.add('tryhackx-topic-rating', () => {
    app.store.models['discussion-ratings'] = Rating;

    extend(DiscussionHero.prototype, 'items', function (items) {
        const discussion = this.attrs.discussion;
        if (!discussion || discussion.ratingDisabled()) return;

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
        const discussion = this.attrs.discussion;
        if (!discussion || discussion.ratingDisabled()) return;

        items.add('rating',
            <StarRating
                discussion={discussion}
                interactive={false}
                size="small"
                showCount={false}
            />,
            15
        );
    });

    extend(DiscussionPage.prototype, 'show', function (discussion) {
        if (discussion && !discussion.ratingDisabled()) {
            RatingPolling.start(discussion);
        }
    });

    const originalOnremove = DiscussionPage.prototype.onremove;
    DiscussionPage.prototype.onremove = function (vnode) {
        RatingPolling.stop();
        if (originalOnremove) {
            originalOnremove.call(this, vnode);
        }
    };

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
        const data = response.data;
        discussion.pushData({
            attributes: {
                ratingDisabled: data.attributes.ratingDisabled,
            },
        });
        m.redraw();
    });
}

function resetRatings(discussion) {
    app.modal.show(ResetRatingsModal, { discussion });
}
