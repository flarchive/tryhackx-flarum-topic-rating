import Component from 'flarum/common/Component';
import humanTime from 'flarum/common/helpers/humanTime';
import app from 'flarum/forum/app';

export default class LastRatedInfo extends Component {
    view() {
        const discussion = this.attrs.discussion;
        if (!discussion) return null;

        const lastRatedAt = discussion.lastRatedAt();
        if (!lastRatedAt) return null;

        return (
            <span className="LastRatedInfo">
                <span className="LastRatedInfo-label">
                    {app.translator.trans('tryhackx-topic-rating.forum.last_rated')}
                </span>
                {' '}
                {humanTime(lastRatedAt)}
            </span>
        );
    }
}
