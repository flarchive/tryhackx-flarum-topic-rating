<?php

namespace TryHackX\TopicRating\Tests\integration\api;

use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use TryHackX\TopicRating\Tests\integration\RatingTestCase;

class PollTest extends RatingTestCase
{
    #[Test]
    public function it_reports_the_score_and_a_server_timestamp(): void
    {
        $this->assertRated($this->rate(self::MEMBER, self::REVIEW, 7));

        $poll = $this->poll(self::REVIEW, self::MEMBER);

        $this->assertSame(1, $poll['ratingCount']);
        $this->assertEqualsWithDelta(3.5, $poll['ratingAverage'], 0.001);
        $this->assertSame(7, $poll['userRating']);
        $this->assertSame('default', $poll['ratingAuthorMode']);
        $this->assertNull($poll['authorRating']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $poll['serverTime']);
        $this->assertArrayNotHasKey('hasNewRatings', $poll);
    }

    #[Test]
    public function since_detects_ratings_written_after_it(): void
    {
        $this->assertRated($this->rate(self::MEMBER, self::REVIEW, 7));

        // ISO-8601 with milliseconds and a Z suffix, as the browser sends it.
        $before = Carbon::now('UTC')->subMinute()->format('Y-m-d\TH:i:s.v\Z');
        $after = Carbon::now('UTC')->addMinute()->format('Y-m-d\TH:i:s.v\Z');

        $this->assertTrue($this->poll(self::REVIEW, null, ['since' => $before])['hasNewRatings']);
        $this->assertFalse($this->poll(self::REVIEW, null, ['since' => $after])['hasNewRatings']);
    }

    #[Test]
    public function a_malformed_since_is_ignored(): void
    {
        $poll = $this->poll(self::REVIEW, null, ['since' => "'; DROP TABLE x; --"]);

        $this->assertArrayNotHasKey('hasNewRatings', $poll);
    }

    #[Test]
    public function only_users_who_may_reset_get_the_stored_total(): void
    {
        $this->setting('tryhackx-topic-rating.author_mode', 'author_only');
        $this->insertRating(self::MEMBER, self::REVIEW, 4);
        $this->assertRated($this->rate(self::AUTHOR, self::REVIEW, 8));

        $this->assertSame(2, $this->poll(self::REVIEW, self::ADMIN)['ratingTotalCount']);
        $this->assertSame(1, $this->poll(self::REVIEW, self::ADMIN)['ratingCount']);
        $this->assertArrayNotHasKey('ratingTotalCount', $this->poll(self::REVIEW, self::MEMBER));
        $this->assertArrayNotHasKey('ratingTotalCount', $this->poll(self::REVIEW));
    }

    #[Test]
    public function guests_get_no_user_rating(): void
    {
        $this->assertArrayNotHasKey('userRating', $this->poll(self::REVIEW));
    }

    #[Test]
    public function invisible_discussions_are_not_polled(): void
    {
        $this->database()->table('discussions')->where('id', self::REVIEW)->update(['is_private' => 1]);

        $request = $this->request('GET', '/api/discussion-ratings/poll')
            ->withQueryParams(['discussion_id' => (string) self::REVIEW]);

        $this->assertSame(404, $this->send($request)->getStatusCode());
    }
}
