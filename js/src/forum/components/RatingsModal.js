import Modal from 'flarum/common/components/Modal';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import app from 'flarum/forum/app';

export default class RatingsModal extends Modal {
    oninit(vnode) {
        super.oninit(vnode);
        this.discussion = this.attrs.discussion;
        this.ratings = [];
        this.loading = true;
        this.moreResults = false;
        this.offset = 0;
        this.limit = 10;
        this.total = 0;
        this.pollInterval = null;
        this.lastPollTime = new Date().toISOString();

        this.loadRatings();
        this.startPolling();
    }

    onremove(vnode) {
        super.onremove(vnode);
        this.stopPolling();
    }

    className() {
        return 'RatingsModal Modal--small';
    }

    title() {
        return app.translator.trans('tryhackx-topic-rating.forum.ratings_modal.title', {
            count: this.total,
        });
    }

    content() {
        return (
            <div className="Modal-body">
                <div className="RatingsModal-list" oncreate={this.setupScroll.bind(this)}>
                    {this.ratings.map((rating) => this.renderRatingItem(rating))}
                    {this.loading && <LoadingIndicator />}
                    {!this.loading && this.ratings.length === 0 && (
                        <div className="RatingsModal-empty">
                            {app.translator.trans('tryhackx-topic-rating.forum.ratings_modal.empty')}
                        </div>
                    )}
                </div>
            </div>
        );
    }

    parseDate(dateStr) {
        if (!dateStr) return null;
        const parsed = new Date(dateStr);
        if (isNaN(parsed.getTime()) || parsed.getFullYear() <= 1970) return null;
        return parsed;
    }

    formatDateTime(date) {
        const pad = (n) => String(n).padStart(2, '0');
        return pad(date.getDate()) + '.'
            + pad(date.getMonth() + 1) + '.'
            + date.getFullYear() + ' '
            + pad(date.getHours()) + ':'
            + pad(date.getMinutes());
    }

    renderRatingItem(rating) {
        const user = rating.user;
        const stars = this.renderStarsDisplay(rating.rating);

        const createdAt = this.parseDate(rating.createdAt);
        const updatedAt = this.parseDate(rating.updatedAt);

        const wasUpdated = updatedAt && createdAt && updatedAt.getTime() - createdAt.getTime() > 1000;
        const displayDate = wasUpdated ? updatedAt : createdAt;

        const userProfileUrl = user ? (app.route('user', { username: user.slug || user.username })) : null;

        return (
            <div className="RatingsModal-item" key={rating.id}>
                <div className="RatingsModal-item-avatar">
                    {user ? (
                        <a href={userProfileUrl}>
                            <span className="Avatar" style={user.avatarUrl ? {'background-image': 'url(' + user.avatarUrl + ')'} : {'background-color': user.color || '#888'}}>
                                {!user.avatarUrl ? (user.displayName || user.username || '?').charAt(0).toUpperCase() : ''}
                            </span>
                        </a>
                    ) : (
                        <span className="Avatar">?</span>
                    )}
                </div>
                <div className="RatingsModal-item-info">
                    <div className="RatingsModal-item-top">
                        {user ? (
                            <a href={userProfileUrl} className="RatingsModal-item-username">
                                {user.displayName || user.username}
                            </a>
                        ) : (
                            <span className="RatingsModal-item-username">
                                {app.translator.trans('tryhackx-topic-rating.forum.ratings_modal.deleted_user')}
                            </span>
                        )}
                        {displayDate && (
                            <span className="RatingsModal-item-date" title={this.formatDateTime(displayDate)}>
                                {wasUpdated && (
                                    <span className="RatingsModal-item-date-prefix">
                                        {app.translator.trans('tryhackx-topic-rating.forum.ratings_modal.updated_prefix')}
                                    </span>
                                )}
                                {this.formatDateTime(displayDate)}
                            </span>
                        )}
                    </div>
                    <div className="RatingsModal-item-stars">
                        {stars}
                        <span className="RatingsModal-item-value">
                            {(rating.rating / 2).toFixed(1)}
                        </span>
                    </div>
                </div>
            </div>
        );
    }

    renderStarsDisplay(value) {
        const stars = [];
        for (let i = 1; i <= 5; i++) {
            let cls = 'StarDisplay';
            if (value >= i * 2) {
                cls += ' StarDisplay--full';
            } else if (value >= (i - 1) * 2 + 1) {
                cls += ' StarDisplay--half';
            } else {
                cls += ' StarDisplay--empty';
            }
            stars.push(<i className={'fas fa-star ' + cls} key={i}></i>);
        }
        return <span className="StarDisplay-container">{stars}</span>;
    }

    loadRatings() {
        this.loading = true;

        const discussionId = this.discussion.id();
        const apiUrl = app.forum.attribute('apiUrl') + '/discussion-ratings'
            + '?discussion_id=' + discussionId
            + '&page[offset]=' + this.offset
            + '&page[limit]=' + this.limit;

        fetch(apiUrl, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-Token': app.session.csrfToken,
            },
            credentials: 'same-origin',
        })
        .then(response => {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then((response) => {
            const newRatings = this.parseRatings(response);
            this.ratings = this.offset === 0 ? newRatings : [...this.ratings, ...newRatings];
            this.total = response.meta ? response.meta.total : this.ratings.length;
            this.moreResults = newRatings.length >= this.limit;
            this.loading = false;
            m.redraw();
        })
        .catch((e) => {
            console.error('RatingsModal load error:', e);
            this.loading = false;
            m.redraw();
        });
    }

    parseRatings(response) {
        const data = response.data || [];
        const included = response.included || [];

        const usersMap = {};
        included.forEach((item) => {
            if (item.type === 'users') {
                usersMap[item.id] = {
                    id: item.id,
                    slug: item.attributes.slug || item.attributes.username,
                    username: item.attributes.username,
                    displayName: item.attributes.displayName || item.attributes.username,
                    avatarUrl: item.attributes.avatarUrl,
                    color: item.attributes.color,
                };
            }
        });

        return data.map((item) => {
            const userId = item.relationships && item.relationships.user
                ? item.relationships.user.data.id
                : null;

            return {
                id: item.id,
                rating: item.attributes.rating,
                createdAt: item.attributes.createdAt,
                updatedAt: item.attributes.updatedAt,
                user: userId ? usersMap[userId] || null : null,
            };
        });
    }

    setupScroll(vnode) {
        const el = vnode.dom;
        el.addEventListener('scroll', () => {
            if (this.loading || !this.moreResults) return;
            if (el.scrollTop + el.clientHeight >= el.scrollHeight - 50) {
                this.offset += this.limit;
                this.loadRatings();
            }
        });
    }

    startPolling() {
        this.pollInterval = setInterval(() => {
            this.pollForNewRatings();
        }, 5000);
    }

    stopPolling() {
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
            this.pollInterval = null;
        }
    }

    pollForNewRatings() {
        const apiUrl = app.forum.attribute('apiUrl') + '/discussion-ratings/poll'
            + '?discussion_id=' + this.discussion.id()
            + '&since=' + encodeURIComponent(this.lastPollTime);

        fetch(apiUrl, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-Token': app.session.csrfToken,
            },
            credentials: 'same-origin',
        })
        .then(r => r.json())
        .then((data) => {
            if (data.hasNewRatings) {
                this.lastPollTime = new Date().toISOString();
                this.total = data.ratingCount;
                this.offset = 0;
                this.loadRatings();
            }
        });
    }
}
