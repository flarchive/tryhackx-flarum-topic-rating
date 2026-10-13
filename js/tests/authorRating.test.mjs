// Run with `npm test` (Node's built-in test runner, no extra dependencies).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    authorModeOf,
    authorRatingOf,
    formatScore,
    hasAnyScore,
    normalizeMode,
    pollExtras,
    showsAuthorBadge,
    storedRatingCount,
    tooltipKey,
} from '../src/forum/utils/authorRating.js';

const discussion = (attrs) => ({
    ratingAuthorMode: () => attrs.mode,
    authorRating: () => attrs.authorRating,
});

const state = (overrides) => ({
    isLoggedIn: true,
    requiresActivation: false,
    canRate: true,
    displayMode: 'rate',
    mode: 'default',
    viewerIsAuthor: false,
    userRating: null,
    count: 3,
    ...overrides,
});

test('unknown or missing modes fall back to default', () => {
    assert.equal(normalizeMode('separate'), 'separate');
    assert.equal(normalizeMode('bogus'), 'default');
    assert.equal(normalizeMode(undefined), 'default');
    assert.equal(authorModeOf({}), 'default'); // payload from an older backend
    assert.equal(authorModeOf(discussion({ mode: 'author_only' })), 'author_only');
});

test('author rating is only taken when it is a real 1–10 value', () => {
    assert.equal(authorRatingOf(discussion({ authorRating: 9 })), 9);
    assert.equal(authorRatingOf(discussion({ authorRating: null })), null);
    assert.equal(authorRatingOf(discussion({ authorRating: 0 })), null);
    assert.equal(authorRatingOf({}), null);
});

test('the badge shows only in separate mode with a rating', () => {
    assert.equal(showsAuthorBadge('separate', 8), true);
    assert.equal(showsAuthorBadge('separate', null), false);
    assert.equal(showsAuthorBadge('author_only', 8), false); // the stars already are the author's score
    assert.equal(showsAuthorBadge('default', 8), false);
});

test('scores are shown on the 0–5 scale with one decimal', () => {
    assert.equal(formatScore(9), '4.5');
    assert.equal(formatScore(10), '5.0');
    assert.equal(formatScore(null), '0.0');
});

test('a topic with only the author rating still counts as rated in separate mode', () => {
    assert.equal(hasAnyScore(0, 'separate', 8), true);
    assert.equal(hasAnyScore(0, 'default', 8), false);
    assert.equal(hasAnyScore(2, 'default', null), true);
    assert.equal(hasAnyScore(0, 'separate', null), false);
});

test('tooltip: legacy messages are unchanged in the default mode', () => {
    assert.equal(tooltipKey(state({ isLoggedIn: false, canRate: false })), 'login_required');
    assert.equal(tooltipKey(state({ requiresActivation: true, canRate: false })), 'activation_required');
    assert.equal(tooltipKey(state({ canRate: false, displayMode: 'readonly' })), 'restricted');
    assert.equal(tooltipKey(state({ userRating: 6 })), 'your_rating');
    assert.equal(tooltipKey(state({ count: 0 })), 'rate_first');
    assert.equal(tooltipKey(state({})), 'rate_this');
    // The author in the default mode is just another rater.
    assert.equal(tooltipKey(state({ viewerIsAuthor: true, userRating: 6 })), 'your_rating');
});

test('tooltip: author-only mode', () => {
    assert.equal(tooltipKey(state({ mode: 'author_only', canRate: false, displayMode: 'readonly' })), 'author_only');
    assert.equal(tooltipKey(state({ mode: 'author_only', isLoggedIn: false, canRate: false })), 'author_only');
    assert.equal(tooltipKey(state({ mode: 'author_only', canRate: false, count: 0 })), 'author_only_unrated');
    assert.equal(tooltipKey(state({ mode: 'author_only', viewerIsAuthor: true, count: 0 })), 'rate_as_author');
    assert.equal(tooltipKey(state({ mode: 'author_only', viewerIsAuthor: true, userRating: 8 })), 'your_rating_as_author');
});

test('tooltip: exclude mode tells the author why they cannot rate', () => {
    assert.equal(tooltipKey(state({ mode: 'exclude', viewerIsAuthor: true, canRate: false, displayMode: 'readonly' })), 'own_topic');
    assert.equal(tooltipKey(state({ mode: 'exclude', userRating: 4 })), 'your_rating');
});

test('tooltip: separate mode labels the author vote as the author rating', () => {
    assert.equal(tooltipKey(state({ mode: 'separate', viewerIsAuthor: true })), 'rate_as_author');
    assert.equal(tooltipKey(state({ mode: 'separate', viewerIsAuthor: true, userRating: 10 })), 'your_rating_as_author');
    assert.equal(tooltipKey(state({ mode: 'separate', userRating: 3 })), 'your_rating');
});

test('only the extra attributes a poll response carries are pushed', () => {
    assert.deepEqual(pollExtras({ ratingAuthorMode: 'separate', authorRating: null }), { ratingAuthorMode: 'separate', authorRating: null });
    assert.deepEqual(pollExtras({ ratingCount: 2, ratingTotalCount: 3 }), { ratingTotalCount: 3 });
    assert.deepEqual(pollExtras({ ratingCount: 2 }), {});
    assert.deepEqual(pollExtras(null), {});
});

test('reset uses the stored total when the backend provides it', () => {
    const d = (total, count) => ({ ratingTotalCount: () => total, ratingCount: () => count });
    assert.equal(storedRatingCount(d(5, 1)), 5);
    assert.equal(storedRatingCount(d(0, 2)), 2); // total not refreshed yet
    assert.equal(storedRatingCount(d(undefined, 3)), 3);
    assert.equal(storedRatingCount({ ratingCount: () => null }), 0);
});
