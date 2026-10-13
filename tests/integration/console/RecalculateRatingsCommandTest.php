<?php

namespace TryHackX\TopicRating\Tests\integration\console;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\ConsoleTestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class RecalculateRatingsCommandTest extends ConsoleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('tryhackx-topic-rating');
        $this->setting('tryhackx-topic-rating.author_mode', 'separate');

        $now = Carbon::now();
        $this->prepareDatabase([
            User::class => [
                ['id' => 2, 'username' => 'author', 'email' => 'author@machine.local'],
                ['id' => 3, 'username' => 'member', 'email' => 'member@machine.local'],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'A review', 'user_id' => 2, 'first_post_id' => 1, 'comment_count' => 1],
                ['id' => 2, 'title' => 'Unrated', 'user_id' => 3, 'first_post_id' => 2, 'comment_count' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>x</p></t>', 'number' => 1],
                ['id' => 2, 'discussion_id' => 2, 'user_id' => 3, 'type' => 'comment', 'content' => '<t><p>y</p></t>', 'number' => 1],
            ],
            // Ratings imported straight into the table: no aggregates computed yet.
            'discussion_ratings' => [
                ['discussion_id' => 1, 'user_id' => 2, 'rating' => 9, 'created_at' => $now, 'updated_at' => $now],
                ['discussion_id' => 1, 'user_id' => 3, 'rating' => 4, 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);
    }

    #[Test]
    public function it_rebuilds_every_aggregate_from_the_stored_ratings(): void
    {
        // A stale non-zero score on a discussion that has no ratings at all.
        $this->database()->table('discussions')->where('id', 2)->update(['rating_count' => 5, 'rating_average' => 4.2]);

        $output = $this->runCommand(['command' => 'topic-rating:recalculate']);
        $this->assertStringContainsString('Done', $output);

        $review = $this->database()->table('discussions')->where('id', 1)->first();
        $this->assertSame(9, (int) $review->author_rating);
        $this->assertSame(1, (int) $review->community_rating_count);
        $this->assertSame(4, (int) $review->community_rating_sum);
        $this->assertSame(1, (int) $review->rating_count);              // separate: community only
        $this->assertEqualsWithDelta(2.0, (float) $review->rating_average, 0.001);
        $this->assertNotNull($review->last_rated_at);

        $unrated = $this->database()->table('discussions')->where('id', 2)->first();
        $this->assertSame(0, (int) $unrated->rating_count);
        $this->assertEqualsWithDelta(0.0, (float) $unrated->rating_average, 0.001);
    }
}
