<?php

namespace TryHackX\TopicRating\Tests\integration\api;

use PHPUnit\Framework\Attributes\Test;
use TryHackX\TopicRating\Tests\integration\RatingTestCase;

/**
 * Deleting an account must take its ratings out of the cached scores (they used
 * to keep counting a vote that no longer existed).
 */
class UserDeletionTest extends RatingTestCase
{
    protected function deleteUser(int $userId): void
    {
        $response = $this->send($this->request('DELETE', '/api/users/' . $userId, [
            'authenticatedAs' => self::ADMIN,
        ]));

        $this->assertSame(204, $response->getStatusCode(), (string) $response->getBody());
    }

    #[Test]
    public function a_deleted_members_ratings_leave_the_score(): void
    {
        $this->assertRated($this->rate(self::MEMBER, self::REVIEW, 10));
        $this->assertRated($this->rate(self::MEMBER2, self::REVIEW, 2));
        $this->assertRated($this->rate(self::MEMBER2, self::OTHER, 4));

        $this->deleteUser(self::MEMBER2);

        $review = $this->row(self::REVIEW);
        $this->assertSame(1, (int) $review->rating_count);
        $this->assertEqualsWithDelta(5.0, (float) $review->rating_average, 0.001);
        $this->assertSame(0, (int) $this->row(self::OTHER)->rating_count);
        $this->assertSame(0, $this->database()->table('discussion_ratings')->where('user_id', self::MEMBER2)->count());
    }

    #[Test]
    public function deleting_the_author_clears_the_author_rating(): void
    {
        $this->setting('tryhackx-topic-rating.author_mode', 'separate');

        $this->assertRated($this->rate(self::AUTHOR, self::REVIEW, 10));
        $this->assertRated($this->rate(self::MEMBER, self::REVIEW, 6));
        $this->assertSame(10, (int) $this->row(self::REVIEW)->author_rating);

        $this->deleteUser(self::AUTHOR);

        $review = $this->row(self::REVIEW);
        $this->assertNull($review->author_rating);
        $this->assertSame(1, (int) $review->rating_count);
        $this->assertEqualsWithDelta(3.0, (float) $review->rating_average, 0.001);
    }
}
