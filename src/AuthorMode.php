<?php

namespace TryHackX\TopicRating;

/**
 * How the topic author's own rating is treated — the "review mode" options.
 *
 * Forums that use topics as reviews (the author writes a review and scores the
 * reviewed item) don't want other members to be able to drag that score around,
 * while ordinary forums often want the opposite: authors shouldn't be able to
 * inflate their own topics. The admin picks one mode (optionally limited to a set
 * of tags, see {@see AuthorModeResolver}):
 *
 *  - DEFAULT      the author rates like any other member (legacy behaviour).
 *  - EXCLUDE      authors can't rate their own topics; any rating they left
 *                 before the mode was switched on is left out of the score.
 *  - SEPARATE     everyone may rate, but the author's rating is shown on its own
 *                 ("Author's rating") and is NOT part of the community average.
 *  - AUTHOR_ONLY  only the author rates; the topic's score IS the author's rating
 *                 and everyone else sees it read-only.
 *
 * Ratings are never deleted when the mode changes — the mode only decides who may
 * rate from now on and which stored ratings make up the displayed/sorted score, so
 * switching back restores the previous score.
 */
final class AuthorMode
{
    public const DEFAULT = 'default';
    public const EXCLUDE = 'exclude';
    public const SEPARATE = 'separate';
    public const AUTHOR_ONLY = 'author_only';

    public const ALL = [self::DEFAULT, self::EXCLUDE, self::SEPARATE, self::AUTHOR_ONLY];

    public static function normalize(mixed $value): string
    {
        return is_string($value) && in_array($value, self::ALL, true) ? $value : self::DEFAULT;
    }

    /**
     * Whether this mode shows the author's rating as a value of its own (beside,
     * or instead of, the community score) — i.e. whether the frontend needs the
     * separate `authorRating` attribute.
     */
    public static function exposesAuthorRating(string $mode): bool
    {
        return $mode === self::SEPARATE || $mode === self::AUTHOR_ONLY;
    }
}
