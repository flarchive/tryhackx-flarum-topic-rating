import Component from 'flarum/common/Component';
import app from 'flarum/forum/app';

export default class StarRating extends Component {
    oninit(vnode) {
        super.oninit(vnode);
        this.hoveredValue = 0;
        this.loading = false;
    }

    oncreate(vnode) {
        super.oncreate(vnode);
        this.dom = vnode.dom;
    }

    // Paint the stars to a value (0–10) directly on the DOM, bypassing Mithril.
    // Needed on the discussion list: DiscussionListItem uses a SubtreeRetainer
    // that blocks the redraw which would otherwise reflect `hoveredValue`, so
    // without this the hover preview never appears (only the CSS tooltip does).
    paintStars(value) {
        if (!this.dom) return;
        this.dom.querySelectorAll('.StarRating-star').forEach((star, idx) => {
            const i = idx + 1;
            star.classList.remove('StarRating-star--full', 'StarRating-star--half', 'StarRating-star--empty');
            if (value >= i * 2) {
                star.classList.add('StarRating-star--full');
            } else if (value >= (i - 1) * 2 + 1) {
                star.classList.add('StarRating-star--half');
            } else {
                star.classList.add('StarRating-star--empty');
            }
        });
    }

    view() {
        const discussion = this.attrs.discussion;
        if (!discussion) return null;

        const average = discussion.ratingAverage() || 0;
        const count = discussion.ratingCount() || 0;
        const userRating = discussion.userRating();
        const canRate = discussion.canRate();
        const ratingDisabled = discussion.ratingDisabled();
        const requiresActivation = discussion.canRateRequiresActivation();
        const isLoggedIn = !!app.session.user;
        const displayMode = (discussion.ratingDisplayMode && discussion.ratingDisplayMode()) || 'rate';
        const size = this.attrs.size || 'normal';

        // 'hidden' mode is enforced upstream (forum/index.js skips rendering),
        // but be defensive in case this is mounted from elsewhere.
        if (displayMode === 'hidden') return null;

        // Compact discussion-list variants: a single star + the numeric average
        // (e.g. ★4.6). Display-only — see renderCompact().
        const variant = this.attrs.variant || 'full';
        if (variant === 'single_filled' || variant === 'single_bucket') {
            return this.renderCompact(variant, average, count, discussion);
        }

        // 'message' mode: skip the stars entirely, show a short note.
        if (displayMode === 'message' && !canRate) {
            return (
                <div className={'StarRating StarRating--' + size + ' StarRating--restricted'}>
                    <span className="StarRating-restrictedMessage">
                        <i className="fas fa-lock StarRating-restrictedIcon"></i>
                        {app.translator.trans('tryhackx-topic-rating.forum.rating_restricted_message')}
                    </span>
                </div>
            );
        }

        const interactive = this.attrs.interactive !== false && !ratingDisabled && displayMode === 'rate';

        const displayValue = this.hoveredValue > 0
            ? this.hoveredValue
            : (average * 2);

        return (
            <div className={'StarRating StarRating--' + size + (this.loading ? ' StarRating--loading' : '') + (interactive && canRate ? ' StarRating--interactive' : '') + (!canRate && displayMode === 'readonly' ? ' StarRating--readonly' : '')}>
                <div className="StarRating-starsWrap">
                    <div
                        className="StarRating-stars"
                        onmouseleave={() => {
                            if (interactive && canRate && isLoggedIn) {
                                this.hoveredValue = 0;
                                this.paintStars((discussion.ratingAverage() || 0) * 2);
                            }
                        }}
                    >
                        {this.renderStars(displayValue, interactive && canRate && isLoggedIn, discussion)}
                    </div>
                    {this.attrs.tooltip !== false && (
                        <div className="StarRating-tooltip">
                            {this.renderTooltipContent(userRating, count, isLoggedIn, requiresActivation, canRate, displayMode)}
                        </div>
                    )}
                </div>
                {this.attrs.showCount !== false && (
                    <span
                        className="StarRating-count"
                        onclick={(e) => {
                            e.stopPropagation();
                            e.preventDefault();
                            if (count > 0) {
                                this.showRatingsModal(discussion);
                            }
                        }}
                    >
                        {app.translator.trans('tryhackx-topic-rating.forum.rating_count', { count })}
                    </span>
                )}
            </div>
        );
    }

    // Compact list display: one star + the numeric average (0–5, one decimal).
    // `single_filled` always shows a solid star (when rated); `single_bucket`
    // picks empty / half / full from the 0–10 score (10 == 5.0):
    //   empty: 0–3.33   half: 3.33–6.67   full: >6.67
    // Display-only. When placed in the meta column (clickToModal) a click opens
    // the ratings list; after the title it sits inside the title link instead.
    renderCompact(variant, average, count, discussion) {
        const size = this.attrs.size || 'normal';
        const value10 = (average || 0) * 2;
        const hasRatings = count > 0;

        let starClass;
        if (!hasRatings) {
            starClass = 'far fa-star StarRating-compactStar--empty';
        } else if (variant === 'single_bucket') {
            if (value10 < 3.33) starClass = 'far fa-star StarRating-compactStar--empty';
            else if (value10 < 6.67) starClass = 'fas fa-star-half-alt StarRating-compactStar--half';
            else starClass = 'fas fa-star StarRating-compactStar--full';
        } else {
            starClass = 'fas fa-star StarRating-compactStar--full';
        }

        // Clickability follows the admin toggle (passed as clickToModal). When on,
        // even an unrated star opens the modal (useful with in-modal rating); when
        // off, forum/index.js has already hidden unrated stars entirely.
        const clickable = !!this.attrs.clickToModal;
        const title = hasRatings
            ? app.translator.trans('tryhackx-topic-rating.forum.rating_count', { count })
            : app.translator.trans('tryhackx-topic-rating.forum.tooltip.rate_first');

        return (
            <span
                className={'StarRating StarRating--compact StarRating--' + size + ' StarRating--' + variant + (clickable ? ' StarRating--compactClickable' : '')}
                title={title}
                onclick={clickable ? (e) => {
                    e.stopPropagation();
                    e.preventDefault();
                    this.showRatingsModal(discussion);
                } : undefined}
            >
                <i className={'StarRating-compactStar ' + starClass}></i>
                {hasRatings ? <span className="StarRating-compactValue">{average.toFixed(1)}</span> : null}
            </span>
        );
    }

    renderTooltipContent(userRating, count, isLoggedIn, requiresActivation, canRate, displayMode) {
        if (!isLoggedIn) {
            return <span>{app.translator.trans('tryhackx-topic-rating.forum.tooltip.login_required')}</span>;
        } else if (requiresActivation) {
            return <span>{app.translator.trans('tryhackx-topic-rating.forum.tooltip.activation_required')}</span>;
        } else if (!canRate && displayMode === 'readonly') {
            return <span>{app.translator.trans('tryhackx-topic-rating.forum.tooltip.restricted')}</span>;
        } else if (userRating) {
            return [
                <div className="StarRating-tooltipLabel">{app.translator.trans('tryhackx-topic-rating.forum.tooltip.your_rating')}</div>,
                <div className="StarRating-tooltipStars">{this.renderTooltipStars(userRating)}</div>
            ];
        } else if (count === 0) {
            return <span>{app.translator.trans('tryhackx-topic-rating.forum.tooltip.rate_first')}</span>;
        } else {
            return <span>{app.translator.trans('tryhackx-topic-rating.forum.tooltip.rate_this')}</span>;
        }
    }

    renderTooltipStars(value) {
        const stars = [];
        for (let i = 1; i <= 5; i++) {
            if (value >= i * 2) {
                stars.push(<i className="fas fa-star StarRating-tooltipStar--full" key={i}></i>);
            } else if (value >= (i - 1) * 2 + 1) {
                stars.push(<i className="fas fa-star-half-alt StarRating-tooltipStar--half" key={i}></i>);
            } else {
                stars.push(<i className="far fa-star StarRating-tooltipStar--empty" key={i}></i>);
            }
        }
        return stars;
    }

    renderStars(displayValue, interactive, discussion) {
        const stars = [];

        for (let i = 1; i <= 5; i++) {
            const leftValue = (i - 1) * 2 + 1;
            const rightValue = i * 2;

            let starClass = 'StarRating-star';
            if (displayValue >= i * 2) {
                starClass += ' StarRating-star--full';
            } else if (displayValue >= (i - 1) * 2 + 1) {
                starClass += ' StarRating-star--half';
            } else {
                starClass += ' StarRating-star--empty';
            }

            stars.push(
                <span className={starClass} key={i}>
                    <span
                        className="StarRating-starHalf StarRating-starHalf--left"
                        onmouseenter={() => { if (interactive) { this.hoveredValue = leftValue; this.paintStars(leftValue); } }}
                        onclick={(e) => {
                            if (interactive) {
                                e.stopPropagation();
                                e.preventDefault();
                                this.submitRating(discussion, leftValue);
                            }
                        }}
                    ></span>
                    <span
                        className="StarRating-starHalf StarRating-starHalf--right"
                        onmouseenter={() => { if (interactive) { this.hoveredValue = rightValue; this.paintStars(rightValue); } }}
                        onclick={(e) => {
                            if (interactive) {
                                e.stopPropagation();
                                e.preventDefault();
                                this.submitRating(discussion, rightValue);
                            }
                        }}
                    ></span>
                    <i className="fas fa-star StarRating-starIcon"></i>
                </span>
            );
        }

        return stars;
    }

    submitRating(discussion, value) {
        if (this.loading) return;

        if (discussion.userRating() === value) {
            this.deleteRating(discussion);
            return;
        }

        this.loading = true;

        app.request({
            method: 'POST',
            url: app.forum.attribute('apiUrl') + '/discussion-ratings',
            body: {
                data: {
                    attributes: {
                        discussionId: discussion.id(),
                        rating: value,
                    },
                },
            },
        }).then(() => {
            this.loading = false;
            this.refreshDiscussion(discussion);
        }).catch(() => {
            this.loading = false;
            m.redraw();
        });
    }

    deleteRating(discussion) {
        this.loading = true;

        app.request({
            method: 'DELETE',
            url: app.forum.attribute('apiUrl') + '/discussion-ratings',
            body: {
                data: {
                    attributes: {
                        discussionId: discussion.id(),
                    },
                },
            },
        }).then(() => {
            this.loading = false;
            this.refreshDiscussion(discussion);
        }).catch(() => {
            this.loading = false;
            m.redraw();
        });
    }

    refreshDiscussion(discussion) {
        app.request({
            method: 'GET',
            url: app.forum.attribute('apiUrl') + '/discussion-ratings/poll',
            params: {
                discussion_id: discussion.id(),
            },
        }).then((data) => {
            discussion.pushData({
                attributes: {
                    ratingAverage: data.ratingAverage,
                    ratingCount: data.ratingCount,
                    lastRatedAt: data.lastRatedAt,
                    ratingDisabled: data.ratingDisabled,
                    userRating: data.userRating != null ? data.userRating : null,
                },
            });
            m.redraw();
            // Let an embedding view (e.g. the ratings modal) react to the change.
            if (typeof this.attrs.onrated === 'function') this.attrs.onrated();
        }).catch(() => {
            // The rating write itself already succeeded; only this follow-up
            // refresh failed. Re-render so the widget settles, and let the
            // background poll reconcile the average — no unhandled rejection,
            // no stuck state. (Flarum's default handler still surfaces the error.)
            m.redraw();
        });
    }

    showRatingsModal(discussion) {
        const RatingsModal = require('./RatingsModal').default;
        app.modal.show(RatingsModal, { discussion });
    }
}
