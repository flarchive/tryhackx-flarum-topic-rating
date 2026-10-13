<?php

namespace TryHackX\TopicRating\Tests\integration\api;

use PHPUnit\Framework\Attributes\Test;
use TryHackX\TopicRating\Tests\integration\RatingTestCase;

/**
 * "Authors can't rate their own topics".
 */
class ExcludeModeTest extends RatingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->authorMode('exclude');
    }

    #[Test]
    public function the_author_cannot_rate_their_own_topic(): void
    {
        $this->assertSame(403, $this->rate(self::AUTHOR, self::REVIEW, 10)->getStatusCode());
        $this->assertSame(0, (int) $this->row(self::REVIEW)->rating_count);

        // They can still rate someone else's topic.
        $this->assertRated($this->rate(self::AUTHOR, self::OTHER, 6));
    }

    #[Test]
    public function the_author_sees_the_score_read_only(): void
    {
        $attributes = $this->discussion(self::REVIEW, self::AUTHOR);

        $this->assertFalse($attributes['canRate']);
        $this->assertFalse($attributes['canRateRequiresActivation']);
        $this->assertSame('readonly', $attributes['ratingDisplayMode']);
        $this->assertSame('exclude', $attributes['ratingAuthorMode']);
        $this->assertTrue($this->discussion(self::REVIEW, self::MEMBER)['canRate']);
    }

    #[Test]
    public function the_rule_applies_to_admins_too(): void
    {
        // The admin is in the bypass group by default; bypass doesn't lift the author rule.
        $this->database()->table('discussions')->where('id', self::OTHER)->update(['user_id' => self::ADMIN]);

        $this->assertSame(403, $this->rate(self::ADMIN, self::OTHER, 10)->getStatusCode());
    }

    #[Test]
    public function a_rating_left_before_the_switch_is_left_out_of_the_score(): void
    {
        $this->insertRating(self::AUTHOR, self::REVIEW, 10);
        $this->assertRated($this->rate(self::MEMBER, self::REVIEW, 4));

        $attributes = $this->discussion(self::REVIEW, self::MEMBER);

        $this->assertSame(1, $attributes['ratingCount']);
        $this->assertEqualsWithDelta(2.0, $attributes['ratingAverage'], 0.001);
        $this->assertNull($attributes['authorRating']);
        $this->assertSame([self::MEMBER], $this->listedRaters(self::REVIEW));
        // The row itself is kept, so switching back restores it.
        $this->assertSame(10, (int) $this->row(self::REVIEW)->author_rating);
    }
}
