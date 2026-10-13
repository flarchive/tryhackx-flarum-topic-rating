<?php

namespace TryHackX\TopicRating\Tests\integration\api;

use PHPUnit\Framework\Attributes\Test;
use TryHackX\TopicRating\Tests\integration\RatingTestCase;

/**
 * Regression coverage for the default (pre-review-mode) behaviour: the author
 * rates like any other member and every rating counts.
 */
class DefaultModeTest extends RatingTestCase
{
    #[Test]
    public function author_and_members_rate_and_every_rating_counts(): void
    {
        $this->assertRated($this->rate(self::AUTHOR, self::REVIEW, 10));
        $this->assertRated($this->rate(self::MEMBER, self::REVIEW, 4));
        $this->assertRated($this->rate(self::MEMBER2, self::REVIEW, 7));

        $attributes = $this->discussion(self::REVIEW, self::MEMBER);

        $this->assertSame(3, $attributes['ratingCount']);
        $this->assertEqualsWithDelta(3.5, $attributes['ratingAverage'], 0.001); // (10+4+7)/3/2
        $this->assertSame('default', $attributes['ratingAuthorMode']);
        $this->assertNull($attributes['authorRating']);
        $this->assertSame(4, $attributes['userRating']);
        $this->assertSame([self::AUTHOR, self::MEMBER, self::MEMBER2], $this->listedRaters(self::REVIEW));

        // The building blocks are maintained in every mode.
        $row = $this->row(self::REVIEW);
        $this->assertSame(10, (int) $row->author_rating);
        $this->assertSame(2, (int) $row->community_rating_count);
        $this->assertSame(11, (int) $row->community_rating_sum);
    }

    #[Test]
    public function author_sees_the_interactive_widget_on_their_own_topic(): void
    {
        $attributes = $this->discussion(self::REVIEW, self::AUTHOR);

        $this->assertTrue($attributes['canRate']);
        $this->assertSame('rate', $attributes['ratingDisplayMode']);
        $this->assertTrue($attributes['ratingViewerIsAuthor']);
        $this->assertFalse($this->discussion(self::REVIEW, self::MEMBER)['ratingViewerIsAuthor']);
    }

    #[Test]
    public function changing_and_removing_a_rating_recalculates(): void
    {
        $this->assertRated($this->rate(self::MEMBER, self::REVIEW, 4));
        $this->assertRated($this->rate(self::MEMBER, self::REVIEW, 9));

        $this->assertSame(1, (int) $this->row(self::REVIEW)->rating_count);
        $this->assertEqualsWithDelta(4.5, (float) $this->row(self::REVIEW)->rating_average, 0.001);

        $this->assertSame(204, $this->unrate(self::MEMBER, self::REVIEW)->getStatusCode());

        $row = $this->row(self::REVIEW);
        $this->assertSame(0, (int) $row->rating_count);
        $this->assertEqualsWithDelta(0.0, (float) $row->rating_average, 0.001);
        $this->assertSame(0, (int) $row->community_rating_count);
        $this->assertNull($row->last_rated_at);
    }

    #[Test]
    public function out_of_range_ratings_are_rejected(): void
    {
        $this->assertSame(422, $this->rate(self::MEMBER, self::REVIEW, 11)->getStatusCode());
        $this->assertSame(422, $this->rate(self::MEMBER, self::REVIEW, 0)->getStatusCode());
        $this->assertSame(0, (int) $this->row(self::REVIEW)->rating_count);
    }

    #[Test]
    public function guests_cannot_rate(): void
    {
        // Skip the CSRF check so it's the controller's own guard that answers.
        $response = $this->send($this->request('POST', '/api/discussion-ratings', [
            'json' => ['data' => ['attributes' => ['discussionId' => self::REVIEW, 'rating' => 8]]],
        ])->withAttribute('bypassCsrfToken', true));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(0, $this->database()->table('discussion_ratings')->count());
    }

    #[Test]
    public function ratings_of_a_discussion_the_viewer_cannot_see_are_not_listed(): void
    {
        $this->assertRated($this->rate(self::MEMBER, self::REVIEW, 6));
        $this->database()->table('discussions')->where('id', self::REVIEW)->update(['is_private' => 1]);

        $this->assertSame([], $this->listedRaters(self::REVIEW, self::MEMBER2));
    }

    #[Test]
    public function the_ratings_listing_requires_a_discussion_id(): void
    {
        $this->assertRated($this->rate(self::MEMBER, self::REVIEW, 6));

        $response = $this->send($this->request('GET', '/api/discussion-ratings'));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], json_decode((string) $response->getBody(), true)['data']);
    }
}
