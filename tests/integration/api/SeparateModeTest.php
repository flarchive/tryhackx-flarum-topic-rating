<?php

namespace TryHackX\TopicRating\Tests\integration\api;

use PHPUnit\Framework\Attributes\Test;
use TryHackX\TopicRating\Tests\integration\RatingTestCase;

/**
 * "Show the author's rating separately": members can't move the author's score
 * and the author's vote isn't part of the community average.
 */
class SeparateModeTest extends RatingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->authorMode('separate');
    }

    #[Test]
    public function the_author_rating_is_kept_apart_from_the_community_average(): void
    {
        $this->assertRated($this->rate(self::AUTHOR, self::REVIEW, 10));
        $this->assertRated($this->rate(self::MEMBER, self::REVIEW, 2));
        $this->assertRated($this->rate(self::MEMBER2, self::REVIEW, 5));

        $attributes = $this->discussion(self::REVIEW, self::MEMBER);

        $this->assertSame('separate', $attributes['ratingAuthorMode']);
        $this->assertSame(10, $attributes['authorRating']);
        $this->assertSame(2, $attributes['ratingCount']);
        $this->assertEqualsWithDelta(1.75, $attributes['ratingAverage'], 0.001); // (2+5)/2/2

        // The stored effective columns (what other extensions sort on) match.
        $row = $this->row(self::REVIEW);
        $this->assertSame(2, (int) $row->rating_count);
        $this->assertEqualsWithDelta(1.75, (float) $row->rating_average, 0.001);

        // The modal lists the community ratings; the author's is shown on its own.
        $this->assertSame([self::MEMBER, self::MEMBER2], $this->listedRaters(self::REVIEW));
    }

    #[Test]
    public function members_cannot_change_the_author_rating(): void
    {
        $this->assertRated($this->rate(self::AUTHOR, self::REVIEW, 9));

        foreach ([1, 2, 3] as $value) {
            $this->assertRated($this->rate(self::MEMBER, self::REVIEW, $value));
        }

        $this->assertSame(9, $this->discussion(self::REVIEW)['authorRating']);
    }

    #[Test]
    public function everyone_with_permission_can_still_rate(): void
    {
        $this->assertTrue($this->discussion(self::REVIEW, self::AUTHOR)['canRate']);
        $this->assertTrue($this->discussion(self::REVIEW, self::MEMBER)['canRate']);
    }

    #[Test]
    public function the_author_rating_is_null_until_the_author_rates(): void
    {
        $this->assertRated($this->rate(self::MEMBER, self::REVIEW, 8));

        $attributes = $this->discussion(self::REVIEW);
        $this->assertNull($attributes['authorRating']);
        $this->assertSame(1, $attributes['ratingCount']);
    }

    #[Test]
    public function the_poll_endpoint_reports_the_author_rating(): void
    {
        $this->assertRated($this->rate(self::AUTHOR, self::REVIEW, 7));
        $this->assertRated($this->rate(self::MEMBER, self::REVIEW, 3));

        $poll = $this->poll(self::REVIEW, self::MEMBER);

        $this->assertSame('separate', $poll['ratingAuthorMode']);
        $this->assertSame(7, $poll['authorRating']);
        $this->assertSame(1, $poll['ratingCount']);
        $this->assertSame(3, $poll['userRating']);
    }
}
