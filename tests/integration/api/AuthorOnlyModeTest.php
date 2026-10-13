<?php

namespace TryHackX\TopicRating\Tests\integration\api;

use PHPUnit\Framework\Attributes\Test;
use TryHackX\TopicRating\Tests\integration\RatingTestCase;

/**
 * "Only the author rates" (the topic's score is the review score).
 */
class AuthorOnlyModeTest extends RatingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->authorMode('author_only');
    }

    #[Test]
    public function only_the_author_can_rate(): void
    {
        $this->assertSame(403, $this->rate(self::MEMBER, self::REVIEW, 2)->getStatusCode());
        $this->assertSame(403, $this->rate(self::ADMIN, self::REVIEW, 2)->getStatusCode());
        $this->assertRated($this->rate(self::AUTHOR, self::REVIEW, 9));

        $attributes = $this->discussion(self::REVIEW, self::MEMBER);

        $this->assertSame('author_only', $attributes['ratingAuthorMode']);
        $this->assertSame(1, $attributes['ratingCount']);
        $this->assertEqualsWithDelta(4.5, $attributes['ratingAverage'], 0.001);
        $this->assertSame(9, $attributes['authorRating']);
        $this->assertSame([self::AUTHOR], $this->listedRaters(self::REVIEW));
    }

    #[Test]
    public function everyone_else_sees_the_score_read_only(): void
    {
        $this->setting('tryhackx-topic-rating.display_when_restricted', 'hidden');

        $member = $this->discussion(self::REVIEW, self::MEMBER);
        $this->assertFalse($member['canRate']);
        $this->assertSame('readonly', $member['ratingDisplayMode']);

        // Even with "hide the widget from restricted users": the review score is content.
        $this->assertSame('readonly', $this->discussion(self::REVIEW)['ratingDisplayMode']);

        $author = $this->discussion(self::REVIEW, self::AUTHOR);
        $this->assertTrue($author['canRate']);
        $this->assertSame('rate', $author['ratingDisplayMode']);
    }

    #[Test]
    public function unactivated_non_authors_are_not_told_to_activate(): void
    {
        $this->database()->table('users')->where('id', self::MEMBER)->update(['is_email_confirmed' => 0]);

        $this->assertFalse($this->discussion(self::REVIEW, self::MEMBER)['canRateRequiresActivation']);
    }

    #[Test]
    public function member_ratings_from_before_the_switch_do_not_count(): void
    {
        $this->insertRating(self::MEMBER, self::REVIEW, 2);
        $this->insertRating(self::MEMBER2, self::REVIEW, 2);
        $this->assertRated($this->rate(self::AUTHOR, self::REVIEW, 8));

        $this->assertSame(1, (int) $this->row(self::REVIEW)->rating_count);
        $this->assertEqualsWithDelta(4.0, (float) $this->row(self::REVIEW)->rating_average, 0.001);

        // Moderators are told how many ratings "Reset All Ratings" would delete…
        $this->assertSame(3, $this->discussion(self::REVIEW, self::ADMIN)['ratingTotalCount']);
        // …everyone else isn't.
        $this->assertArrayNotHasKey('ratingTotalCount', $this->discussion(self::REVIEW, self::MEMBER));
    }
}
