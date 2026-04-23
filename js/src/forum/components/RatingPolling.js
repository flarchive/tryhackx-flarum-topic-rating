import app from 'flarum/forum/app';

class RatingPolling {
    constructor() {
        this.interval = null;
        this.discussionId = null;
    }

    start(discussion) {
        this.stop();
        this.discussionId = discussion.id();

        this.interval = setInterval(() => {
            this.poll(discussion);
        }, 8000);
    }

    stop() {
        if (this.interval) {
            clearInterval(this.interval);
            this.interval = null;
        }
        this.discussionId = null;
    }

    poll(discussion) {
        if (!discussion || discussion.id() !== this.discussionId) {
            this.stop();
            return;
        }

        app.request({
            method: 'GET',
            url: app.forum.attribute('apiUrl') + '/discussion-ratings/poll',
            params: {
                discussion_id: discussion.id(),
            },
            errorHandler: () => {},
        }).then((data) => {
            const oldAvg = discussion.ratingAverage();
            const oldCount = discussion.ratingCount();

            if (data.ratingAverage !== oldAvg || data.ratingCount !== oldCount) {
                discussion.pushData({
                    attributes: {
                        ratingAverage: data.ratingAverage,
                        ratingCount: data.ratingCount,
                        lastRatedAt: data.lastRatedAt,
                        ratingDisabled: data.ratingDisabled,
                        userRating: data.userRating !== undefined ? data.userRating : discussion.userRating(),
                    },
                });
                m.redraw();
            }
        });
    }
}

export default new RatingPolling();
