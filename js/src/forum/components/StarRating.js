import Component from 'flarum/common/Component';
import app from 'flarum/forum/app';

export default class StarRating extends Component {
    oninit(vnode) {
        super.oninit(vnode);
        this.hoveredValue = 0;
        this.loading = false;
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
        const interactive = this.attrs.interactive !== false && !ratingDisabled;
        const size = this.attrs.size || 'normal';

        const displayValue = this.hoveredValue > 0
            ? this.hoveredValue
            : (average * 2);

        return (
            <div className={'StarRating StarRating--' + size + (this.loading ? ' StarRating--loading' : '') + (interactive && canRate ? ' StarRating--interactive' : '')}>
                <div className="StarRating-starsWrap">
                    <div
                        className="StarRating-stars"
                        onmouseleave={() => {
                            if (interactive && canRate && isLoggedIn) {
                                this.hoveredValue = 0;
                            }
                        }}
                    >
                        {this.renderStars(displayValue, interactive && canRate && isLoggedIn, discussion)}
                    </div>
                    <div className="StarRating-tooltip">
                        {this.renderTooltipContent(userRating, count, isLoggedIn, requiresActivation)}
                    </div>
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

    renderTooltipContent(userRating, count, isLoggedIn, requiresActivation) {
        if (!isLoggedIn) {
            return <span>{app.translator.trans('tryhackx-topic-rating.forum.tooltip.login_required')}</span>;
        } else if (requiresActivation) {
            return <span>{app.translator.trans('tryhackx-topic-rating.forum.tooltip.activation_required')}</span>;
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
                        onmouseenter={() => { if (interactive) this.hoveredValue = leftValue; }}
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
                        onmouseenter={() => { if (interactive) this.hoveredValue = rightValue; }}
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
        });
    }

    showRatingsModal(discussion) {
        const RatingsModal = require('./RatingsModal').default;
        app.modal.show(RatingsModal, { discussion });
    }
}
