import Modal from 'flarum/common/components/Modal';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import app from 'flarum/forum/app';
import StarRating from './StarRating';
import RatingPolling from './RatingPolling';

export default class RatingsModal extends Modal {
    oninit(vnode) {
        super.oninit(vnode);
        this.discussion = this.attrs.discussion;
        this.ratings = [];
        this.loading = true;
        this.moreResults = false;
        this.offset = 0;
        this.limit = 10;
        // Seed from the discussion's own count so the title never shows
        // "undefined" before the list (and its meta) has loaded.
        this.total = this.discussion.ratingCount() || 0;
        this.pollTimeout = null;
        this.lastPollTime = new Date().toISOString();

        // Hold the page-level RatingPolling while we're open — this modal runs
        // its own (faster) poll, so the two would otherwise duplicate requests.
        RatingPolling.pause();

        this.loadRatings();
        // Live updates can be disabled forum-wide; only poll when enabled. The
        // ratings list still loads once either way.
        if (app.forum.attribute('tryhackxTopicRatingRealtimeEnabled') !== false) {
            this.startPolling();
        }
    }

    onremove(vnode) {
        super.onremove(vnode);
        this.stopPolling();
        RatingPolling.resume();
    }

    className() {
        return 'RatingsModal Modal--small';
    }

    title() {
        // Guard against an undefined/NaN total (e.g. when the API response has
        // no `meta.total`) so the heading never renders "Ratings ({undefined})".
        const count = (typeof this.total === 'number' && !isNaN(this.total))
            ? this.total
            : (this.discussion.ratingCount() || 0);

        return app.translator.trans('tryhackx-topic-rating.forum.ratings_modal.title', { count });
    }

    // Optional in-modal rating control (admin opt-in via single_modal_rate).
    // Only shown when the viewer can actually rate this discussion.
    renderRateControl() {
        if (app.forum.attribute('tryhackxTopicRatingSingleModalRate') !== true) return null;

        const d = this.discussion;
        if (!d || d.ratingDisabled()) return null;

        const mode = (d.ratingDisplayMode && d.ratingDisplayMode()) || 'rate';
        if (mode !== 'rate' || !d.canRate()) return null;

        return (
            <div className="RatingsModal-rate">
                <StarRating
                    discussion={d}
                    interactive={true}
                    size="normal"
                    showCount={false}
                    onrated={() => { this.offset = 0; this.loadRatings(); }}
                />
            </div>
        );
    }

    content() {
        return (
            <div className="Modal-body">
                {this.renderRateControl()}
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

    validDate(date) {
        if (!(date instanceof Date) || isNaN(date.getTime()) || date.getFullYear() <= 1970) return null;
        return date;
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
        const user = rating.user();
        const stars = this.renderStarsDisplay(rating.rating());

        const createdAt = this.validDate(rating.createdAt());
        const updatedAt = this.validDate(rating.updatedAt());

        const wasUpdated = updatedAt && createdAt && updatedAt.getTime() - createdAt.getTime() > 1000;
        const displayDate = wasUpdated ? updatedAt : createdAt;

        const userProfileUrl = user ? app.route.user(user) : null;

        return (
            <div className="RatingsModal-item" key={rating.id()}>
                <div className="RatingsModal-item-avatar">
                    {user ? (
                        <a href={userProfileUrl}>
                            <span
                                className="Avatar"
                                style={user.avatarUrl()
                                    ? { backgroundImage: 'url(' + user.avatarUrl() + ')' }
                                    : { backgroundColor: user.color() || '#888' }}
                            >
                                {!user.avatarUrl() ? (user.displayName() || '?').charAt(0).toUpperCase() : ''}
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
                                {user.displayName()}
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
                            {(rating.rating() / 2).toFixed(1)}
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

        app.store.find('discussion-ratings', {
            discussion_id: this.discussion.id(),
            page: { offset: this.offset, limit: this.limit },
        }).then((results) => {
            const newRatings = Array.isArray(results) ? results : [];
            this.ratings = this.offset === 0 ? newRatings : [...this.ratings, ...newRatings];

            const total = results && results.payload && results.payload.meta
                && results.payload.meta.page && results.payload.meta.page.total;
            this.total = (typeof total === 'number')
                ? total
                : (this.discussion.ratingCount() || this.ratings.length);

            this.moreResults = newRatings.length >= this.limit;
            this.loading = false;
            m.redraw();
        }).catch(() => {
            this.loading = false;
            m.redraw();
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

    // Self-scheduling poll with ±20% jitter and exponential back-off, mirroring
    // RatingPolling — so many clients opening this modal on a trending discussion
    // don't fire in lockstep (a synchronized burst), and a failing endpoint isn't
    // hammered. The base cadence is faster (5s) than the page poll since the modal
    // is foreground and short-lived.
    startPolling() {
        this.pollErrorStreak = 0;
        this.pollingStopped = false;
        this.scheduleNextPoll();
    }

    scheduleNextPoll() {
        if (this.pollingStopped) return;

        const base = 5000 * Math.min(Math.pow(2, this.pollErrorStreak), 8); // back-off, cap 8×
        const delay = base * (0.8 + Math.random() * 0.4); // ±20% jitter

        this.pollTimeout = setTimeout(() => this.pollForNewRatings(), delay);
    }

    stopPolling() {
        this.pollingStopped = true;
        if (this.pollTimeout) {
            clearTimeout(this.pollTimeout);
            this.pollTimeout = null;
        }
    }

    pollForNewRatings() {
        this.pollTimeout = null;

        // Don't poll while the tab is hidden (see RatingPolling.poll) — the modal
        // can sit open in a backgrounded tab; resume on the next tick once visible.
        if (typeof document !== 'undefined' && document.hidden) {
            this.scheduleNextPoll();
            return;
        }

        app.request({
            method: 'GET',
            url: app.forum.attribute('apiUrl') + '/discussion-ratings/poll',
            params: {
                discussion_id: this.discussion.id(),
                since: this.lastPollTime,
            },
            // Background poll: never alert on failure, but surface genuine server
            // errors (5xx) to the console; 401/403/404 are ignorable here.
            errorHandler: (e) => {
                if (e && e.status >= 500 && typeof console !== 'undefined') {
                    console.warn('[topic-rating] ratings-modal poll failed (status ' + e.status + ')');
                }
            },
        }).then((data) => {
            this.pollErrorStreak = 0;
            if (data && data.hasNewRatings) {
                this.lastPollTime = new Date().toISOString();
                this.total = data.ratingCount;
                this.offset = 0;
                this.loadRatings();
            }
        }).catch(() => {
            this.pollErrorStreak = Math.min(this.pollErrorStreak + 1, 8);
        }).finally(() => {
            this.scheduleNextPoll();
        });
    }
}
