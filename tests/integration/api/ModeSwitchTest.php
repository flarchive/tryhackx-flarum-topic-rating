<?php

namespace TryHackX\TopicRating\Tests\integration\api;

use PHPUnit\Framework\Attributes\Test;
use TryHackX\TopicRating\Tests\integration\RatingTestCase;

/**
 * Saving the author mode in the admin panel re-derives every stored score
 * (rating_count / rating_average are what other extensions sort on), and no
 * rating is ever lost by switching.
 */
class ModeSwitchTest extends RatingTestCase
{
    protected function saveSettings(array $settings): void
    {
        $response = $this->send($this->request('POST', '/api/settings', [
            'authenticatedAs' => self::ADMIN,
            'json' => $settings,
        ]));

        $this->assertSame(204, $response->getStatusCode(), (string) $response->getBody());
    }

    protected function assertScore(int $discussionId, int $count, float $average): void
    {
        $row = $this->row($discussionId);
        $this->assertSame($count, (int) $row->rating_count, 'rating_count');
        $this->assertEqualsWithDelta($average, (float) $row->rating_average, 0.001, 'rating_average');
    }

    #[Test]
    public function switching_modes_re_derives_the_stored_scores(): void
    {
        $this->assertRated($this->rate(self::AUTHOR, self::REVIEW, 10));
        $this->assertRated($this->rate(self::MEMBER, self::REVIEW, 2));
        $this->assertRated($this->rate(self::MEMBER2, self::REVIEW, 4));
        $this->assertRated($this->rate(self::AUTHOR, self::OTHER, 6));
        $this->assertScore(self::REVIEW, 3, 2.67);     // (10+2+4)/3/2 = 2.666…

        $this->saveSettings(['tryhackx-topic-rating.author_mode' => 'author_only']);
        $this->assertScore(self::REVIEW, 1, 5.0);
        $this->assertScore(self::OTHER, 0, 0.0);       // its author (MEMBER) never rated it

        $this->saveSettings(['tryhackx-topic-rating.author_mode' => 'separate']);
        $this->assertScore(self::REVIEW, 2, 1.5);       // (2+4)/2/2
        $this->assertScore(self::OTHER, 1, 3.0);
        $this->assertSame(10, $this->discussion(self::REVIEW)['authorRating']);

        $this->saveSettings(['tryhackx-topic-rating.author_mode' => 'exclude']);
        $this->assertScore(self::REVIEW, 2, 1.5);

        $this->saveSettings(['tryhackx-topic-rating.author_mode' => 'default']);
        $this->assertScore(self::REVIEW, 3, 2.67);
        $this->assertScore(self::OTHER, 1, 3.0);
        $this->assertSame(4, $this->database()->table('discussion_ratings')->count());
    }

    #[Test]
    public function an_unknown_mode_falls_back_to_the_default(): void
    {
        $this->assertRated($this->rate(self::AUTHOR, self::REVIEW, 10));
        $this->assertRated($this->rate(self::MEMBER, self::REVIEW, 2));

        $this->saveSettings(['tryhackx-topic-rating.author_mode' => 'nonsense']);

        $this->assertScore(self::REVIEW, 2, 3.0);
        $this->assertSame('default', $this->discussion(self::REVIEW)['ratingAuthorMode']);
        $this->assertTrue($this->discussion(self::REVIEW, self::AUTHOR)['canRate']);
    }

    #[Test]
    public function unrelated_settings_do_not_trigger_a_refresh(): void
    {
        $this->assertRated($this->rate(self::MEMBER, self::REVIEW, 8));

        // Corrupt the stored score: only an author-mode save may rewrite it.
        $this->database()->table('discussions')->where('id', self::REVIEW)->update(['rating_count' => 99]);
        $this->saveSettings(['tryhackx-topic-rating.poll_interval' => '30']);

        $this->assertSame(99, (int) $this->row(self::REVIEW)->rating_count);
    }
}
