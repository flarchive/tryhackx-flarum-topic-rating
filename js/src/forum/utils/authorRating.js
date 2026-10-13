// Pure helpers for the author mode ("review mode"). No Flarum imports, so they
// can be unit-tested with plain Node (see js/tests). The backend resolves the
// mode per discussion (incl. the optional tag scope) and serializes it as
// `ratingAuthorMode`; these helpers only decide what to show.

export const MODE_DEFAULT = 'default';
export const MODE_EXCLUDE = 'exclude';
export const MODE_SEPARATE = 'separate';
export const MODE_AUTHOR_ONLY = 'author_only';

const MODES = [MODE_DEFAULT, MODE_EXCLUDE, MODE_SEPARATE, MODE_AUTHOR_ONLY];

export function normalizeMode(mode) {
    return MODES.indexOf(mode) !== -1 ? mode : MODE_DEFAULT;
}

// Mode for a discussion model (undefined on payloads from an older backend).
export function authorModeOf(discussion) {
    return normalizeMode(discussion && discussion.ratingAuthorMode ? discussion.ratingAuthorMode() : null);
}

// The author's own rating (1–10) when the mode shows it on its own, else null.
export function authorRatingOf(discussion) {
    const value = discussion && discussion.authorRating ? discussion.authorRating() : null;
    return typeof value === 'number' && value > 0 ? value : null;
}

// "Separate" mode shows the author's rating as a badge next to the community score.
export function showsAuthorBadge(mode, authorRating) {
    return mode === MODE_SEPARATE && authorRating !== null && authorRating > 0;
}

// 1–10 half-star value → "4.5".
export function formatScore(value10) {
    return ((Number(value10) || 0) / 2).toFixed(1);
}

/**
 * Which tooltip message the star widget shows, as a translation-key suffix under
 * `tryhackx-topic-rating.forum.tooltip.*`. `your_rating*` keys are followed by the
 * viewer's own stars.
 *
 * @param {object} s
 * @param {boolean} s.isLoggedIn
 * @param {boolean} s.requiresActivation
 * @param {boolean} s.canRate
 * @param {string}  s.displayMode     'rate' | 'readonly' | 'message' | 'hidden'
 * @param {string}  s.mode            author mode
 * @param {boolean} s.viewerIsAuthor
 * @param {number|null} s.userRating
 * @param {number}  s.count
 */
export function tooltipKey(s) {
    const mode = normalizeMode(s.mode);
    const authorScore = mode === MODE_SEPARATE || mode === MODE_AUTHOR_ONLY;

    // Only the author rates: nobody else is "missing" a login/permission.
    if (mode === MODE_AUTHOR_ONLY && !s.viewerIsAuthor) return s.count > 0 ? 'author_only' : 'author_only_unrated';
    if (!s.isLoggedIn) return 'login_required';
    if (s.requiresActivation) return 'activation_required';
    if (mode === MODE_EXCLUDE && s.viewerIsAuthor) return 'own_topic';
    if (!s.canRate && s.displayMode === 'readonly') return 'restricted';
    if (s.userRating) return s.viewerIsAuthor && authorScore ? 'your_rating_as_author' : 'your_rating';
    if (s.viewerIsAuthor && authorScore) return 'rate_as_author';
    if (s.count === 0) return 'rate_first';
    return 'rate_this';
}

/**
 * Whether a discussion-list row has any score to show — the community score, or
 * (in "separate" mode) just the author's rating.
 */
export function hasAnyScore(count, mode, authorRating) {
    return (count || 0) > 0 || showsAuthorBadge(mode, authorRating);
}

// Extra attributes to push into the discussion model from a poll / refresh
// response (author mode, author rating, moderators' stored total) — only those
// the response actually carries.
export function pollExtras(data) {
    const attrs = {};
    ['ratingAuthorMode', 'authorRating', 'ratingTotalCount'].forEach((key) => {
        if (data && data[key] !== undefined) attrs[key] = data[key];
    });
    return attrs;
}

// Every stored rating, including those the author mode leaves out of the score
// (what "Reset All Ratings" deletes). Only moderators get the total from the
// backend; everyone else falls back to the displayed count.
export function storedRatingCount(discussion) {
    const total = discussion.ratingTotalCount ? discussion.ratingTotalCount() : undefined;
    const shown = discussion.ratingCount() || 0;
    // max(): the displayed count is always part of the total, so this also stays
    // right if the total wasn't refreshed yet.
    return typeof total === 'number' ? Math.max(total, shown) : shown;
}
