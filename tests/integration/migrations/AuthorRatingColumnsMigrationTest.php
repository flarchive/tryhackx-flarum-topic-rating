<?php

namespace TryHackX\TopicRating\Tests\integration\migrations;

use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\Test;
use TryHackX\TopicRating\Tests\integration\RatingTestCase;

/**
 * The 2.5.0 migration backfills the author-rating building blocks from ratings
 * that already exist and re-derives the (default-mode) cached score, which
 * repairs scores left stale by the pre-2.5 deleted-user bug.
 *
 * SQLite only: re-running DDL inside the test transaction relies on SQLite's
 * transactional DDL (MySQL/MariaDB would implicitly commit and leak state into
 * the next test). Its SQL mirrors RatingRecalculator's, which every other test
 * exercises on whatever driver the suite runs on.
 */
class AuthorRatingColumnsMigrationTest extends RatingTestCase
{
    #[Test]
    public function it_backfills_existing_ratings(): void
    {
        if ($this->database()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Needs transactional DDL (SQLite).');
        }

        // Back to the pre-2.5.0 schema, with ratings written by 2.4.x.
        $schema = $this->database()->getSchemaBuilder();
        $schema->table('discussions', function (Blueprint $table) {
            $table->dropColumn(['author_rating', 'community_rating_count', 'community_rating_sum']);
        });

        $this->insertRating(self::AUTHOR, self::REVIEW, 9);
        $this->insertRating(self::MEMBER, self::REVIEW, 4);
        $this->insertRating(self::MEMBER2, self::REVIEW, 6);
        $this->insertRating(self::MEMBER2, self::OTHER, 3);
        $this->database()->table('discussions')->where('id', self::REVIEW)->update(['rating_count' => 3, 'rating_average' => 3.17]);
        // Stale from 2.4.x: a deleted account's vote still counted on OTHER.
        $this->database()->table('discussions')->where('id', self::OTHER)->update(['rating_count' => 2, 'rating_average' => 4.25]);

        $migration = require __DIR__ . '/../../../migrations/2026_10_13_000000_add_author_rating_columns_to_discussions.php';
        $migration['up']($schema);

        $review = $this->row(self::REVIEW);
        $this->assertSame(9, (int) $review->author_rating);
        $this->assertSame(2, (int) $review->community_rating_count);
        $this->assertSame(10, (int) $review->community_rating_sum);
        // Default mode = every rating: an already-correct score is unchanged.
        $this->assertSame(3, (int) $review->rating_count);
        $this->assertEqualsWithDelta(3.17, (float) $review->rating_average, 0.001);
        $this->assertNotNull($review->last_rated_at);

        $other = $this->row(self::OTHER);
        $this->assertNull($other->author_rating);   // its author (MEMBER) didn't rate it
        $this->assertSame(1, (int) $other->community_rating_count);
        $this->assertSame(3, (int) $other->community_rating_sum);
        // …and the stale score is repaired.
        $this->assertSame(1, (int) $other->rating_count);
        $this->assertEqualsWithDelta(1.5, (float) $other->rating_average, 0.001);
    }
}
