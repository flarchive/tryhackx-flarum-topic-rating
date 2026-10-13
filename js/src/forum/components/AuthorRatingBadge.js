import Component from 'flarum/common/Component';
import app from 'flarum/forum/app';
import { formatScore } from '../utils/authorRating';

/**
 * The topic author's own rating, shown next to the community score in the
 * "separate" author mode: ✒ Author ★ 4.5. Display-only — members can't change it.
 * The text label is dropped at the small (discussion-list) size to save room;
 * the tooltip still says what it is.
 */
export default class AuthorRatingBadge extends Component {
    view() {
        const value = this.attrs.value;
        if (!value) return null;

        const size = this.attrs.size || 'normal';
        const score = formatScore(value);

        return (
            <span
                className={'AuthorRatingBadge AuthorRatingBadge--' + size}
                title={app.translator.trans('tryhackx-topic-rating.forum.author_badge.title', { score }, true)}
            >
                <i className="fas fa-pen-nib AuthorRatingBadge-icon" aria-hidden="true"></i>
                {size !== 'small' && (
                    <span className="AuthorRatingBadge-label">
                        {app.translator.trans('tryhackx-topic-rating.forum.author_badge.label')}
                    </span>
                )}
                <i className="fas fa-star AuthorRatingBadge-star" aria-hidden="true"></i>
                <span className="AuthorRatingBadge-value">{score}</span>
            </span>
        );
    }
}
