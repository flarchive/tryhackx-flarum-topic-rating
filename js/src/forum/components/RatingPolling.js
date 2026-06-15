import app from 'flarum/forum/app';

// Cadence for the discussion-page rating poll. A self-scheduling timeout (rather
// than a fixed setInterval) lets us spread load on busy/large forums:
//   - ±JITTER randomises each delay so many concurrent clients don't poll in
//     lockstep (avoids a synchronised "thundering herd" against the API);
//   - consecutive failures back off exponentially up to MAX_BACKOFF so a
//     struggling server isn't hammered, recovering to the base rate on success;
//   - a hidden tab (and the modal-held pause) skip the request entirely.
// The base interval is admin-configurable (default 8s) so large forums can dial
// back per-visitor load; the spread/back-off above are what bound the worst case.
const DEFAULT_INTERVAL = 8000;
const JITTER = 0.2; // ±20%
const MAX_BACKOFF = 8; // up to 8× base after repeated errors

// Base poll interval (ms) from the admin setting (seconds, clamped 5–300), with an
// 8s fallback. Read live so an admin change takes effect on the next tick once the
// forum payload refreshes (page reload), without a rebuild.
function baseIntervalMs() {
    const raw = parseInt(app.forum.attribute('tryhackxTopicRatingPollInterval'), 10);
    if (!raw || isNaN(raw)) return DEFAULT_INTERVAL;
    return Math.max(5, Math.min(300, raw)) * 1000;
}

class RatingPolling {
    constructor() {
        this.timeout = null;
        this.discussionId = null;
        this.paused = false;
        this.errorStreak = 0;
    }

    start(discussion) {
        // Master switch: when live rating updates are turned off, never start the
        // background poll. Ratings still load and update on the user's own action.
        if (app.forum.attribute('tryhackxTopicRatingRealtimeEnabled') === false) {
            return;
        }
        this.stop();
        this.discussionId = discussion.id();
        this.paused = false;
        this.errorStreak = 0;
        this.scheduleNext(discussion);
    }

    stop() {
        if (this.timeout) {
            clearTimeout(this.timeout);
            this.timeout = null;
        }
        this.discussionId = null;
        this.paused = false;
        this.errorStreak = 0;
    }

    // Temporarily hold the page-level poll while the ratings modal is open: the
    // modal runs its own (faster) poll and refreshes the discussion, so polling
    // twice for the same data is wasted requests.
    pause() {
        this.paused = true;
    }

    resume() {
        this.paused = false;
    }

    // Schedule the next poll: base × exponential back-off (on consecutive errors),
    // then ±JITTER so concurrent clients spread out instead of firing in lockstep.
    scheduleNext(discussion) {
        if (this.discussionId === null) return; // stopped

        const backoff = Math.min(Math.pow(2, this.errorStreak), MAX_BACKOFF);
        const base = baseIntervalMs() * backoff;
        const delay = base * (1 - JITTER + Math.random() * 2 * JITTER);

        this.timeout = setTimeout(() => this.poll(discussion), delay);
    }

    poll(discussion) {
        this.timeout = null;

        // Discussion changed or gone → stop the loop entirely.
        if (!discussion || discussion.id() !== this.discussionId) {
            this.stop();
            return;
        }

        // Held by the modal, or the tab is in the background → skip the request
        // but keep the loop alive so it resumes on the next tick. Readers leave
        // discussion tabs open; polling a hidden tab is pure wasted server load.
        if (this.paused || (typeof document !== 'undefined' && document.hidden)) {
            this.scheduleNext(discussion);
            return;
        }

        app.request({
            method: 'GET',
            url: app.forum.attribute('apiUrl') + '/discussion-ratings/poll',
            params: {
                discussion_id: discussion.id(),
            },
            // Background poll: never alert on failure (that would pop an error
            // every interval), but surface genuine server errors (5xx) to the
            // console so they stay diagnosable. 401/403/404 are expected/ignorable
            // in a polling context (e.g. session expiry, discussion gone).
            errorHandler: (e) => {
                if (e && e.status >= 500 && typeof console !== 'undefined') {
                    console.warn('[topic-rating] rating poll failed (status ' + e.status + ')');
                }
            },
        }).then((data) => {
            this.errorStreak = 0;

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
        }).catch(() => {
            // errorHandler above already logged genuine 5xx; here we only count the
            // failure for back-off and swallow the rejection.
            this.errorStreak = Math.min(this.errorStreak + 1, MAX_BACKOFF);
        }).finally(() => {
            // Reschedule after each completed poll (success or failure), unless a
            // stop() in the meantime cleared the discussion id.
            this.scheduleNext(discussion);
        });
    }
}

const ratingPolling = new RatingPolling();
export default ratingPolling;
