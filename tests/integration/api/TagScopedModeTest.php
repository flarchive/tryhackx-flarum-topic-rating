<?php

namespace TryHackX\TopicRating\Tests\integration\api;

use Flarum\Tags\Tag;
use PHPUnit\Framework\Attributes\Test;
use TryHackX\TopicRating\Tests\integration\RatingTestCase;

/**
 * The author mode limited to some tags: e.g. "author only" in a Reviews tag while
 * the rest of the forum keeps community rating.
 */
class TagScopedModeTest extends RatingTestCase
{
    protected const REVIEWS_TAG = 1;
    protected const GENERAL_TAG = 2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags');
        $this->authorMode('author_only', [self::REVIEWS_TAG]);

        $this->prepareDatabase([
            Tag::class => [
                ['id' => self::REVIEWS_TAG, 'name' => 'Reviews', 'slug' => 'reviews', 'position' => 0],
                ['id' => self::GENERAL_TAG, 'name' => 'General', 'slug' => 'general', 'position' => 1],
            ],
            'discussion_tag' => [
                ['discussion_id' => self::REVIEW, 'tag_id' => self::REVIEWS_TAG],
                ['discussion_id' => self::OTHER, 'tag_id' => self::GENERAL_TAG],
            ],
        ]);
    }

    #[Test]
    public function the_mode_only_applies_inside_the_scoped_tags(): void
    {
        $this->assertSame('author_only', $this->discussion(self::REVIEW)['ratingAuthorMode']);
        $this->assertSame('default', $this->discussion(self::OTHER)['ratingAuthorMode']);

        $this->assertSame(403, $this->rate(self::MEMBER, self::REVIEW, 4)->getStatusCode());
        $this->assertRated($this->rate(self::AUTHOR, self::REVIEW, 8));

        // Outside the scope everyone rates, the topic's author included.
        $this->assertRated($this->rate(self::MEMBER, self::OTHER, 6));
        $this->assertRated($this->rate(self::MEMBER2, self::OTHER, 2));

        $this->assertSame(1, (int) $this->row(self::REVIEW)->rating_count);
        $this->assertSame(2, (int) $this->row(self::OTHER)->rating_count);
    }

    #[Test]
    public function saving_the_scope_re_derives_scores_on_both_sides(): void
    {
        $this->assertRated($this->rate(self::MEMBER, self::OTHER, 6));   // MEMBER authored OTHER
        $this->assertRated($this->rate(self::MEMBER2, self::OTHER, 2));
        $this->assertSame(2, (int) $this->row(self::OTHER)->rating_count);

        $response = $this->send($this->request('POST', '/api/settings', [
            'authenticatedAs' => self::ADMIN,
            'json' => ['tryhackx-topic-rating.author_mode_tags' => json_encode([self::REVIEWS_TAG, self::GENERAL_TAG])],
        ]));
        $this->assertSame(204, $response->getStatusCode(), (string) $response->getBody());

        $this->assertSame(1, (int) $this->row(self::OTHER)->rating_count);
        $this->assertEqualsWithDelta(3.0, (float) $this->row(self::OTHER)->rating_average, 0.001);
    }

    #[Test]
    public function a_discussion_leaving_the_scope_goes_back_to_the_default_score(): void
    {
        $this->assertRated($this->rate(self::AUTHOR, self::REVIEW, 10));
        $this->insertRating(self::MEMBER, self::REVIEW, 2);   // from before the mode
        $this->recalculator()->recalculateMany([self::REVIEW]);
        $this->assertSame(1, (int) $this->row(self::REVIEW)->rating_count);

        // Scope moved to the other tag: REVIEW is no longer an author-only review.
        $response = $this->send($this->request('POST', '/api/settings', [
            'authenticatedAs' => self::ADMIN,
            'json' => ['tryhackx-topic-rating.author_mode_tags' => json_encode([self::GENERAL_TAG])],
        ]));
        $this->assertSame(204, $response->getStatusCode(), (string) $response->getBody());

        $this->assertSame(2, (int) $this->row(self::REVIEW)->rating_count);
        $this->assertEqualsWithDelta(3.0, (float) $this->row(self::REVIEW)->rating_average, 0.001);
    }

    #[Test]
    public function deleting_the_last_scoped_tag_switches_the_mode_off(): void
    {
        $this->assertRated($this->rate(self::AUTHOR, self::REVIEW, 10));
        $this->insertRating(self::MEMBER, self::REVIEW, 2);  // a member's rating from before
        $this->recalculator()->recalculateMany([self::REVIEW]);
        $this->assertSame(1, (int) $this->row(self::REVIEW)->rating_count);

        $response = $this->send($this->request('DELETE', '/api/tags/' . self::REVIEWS_TAG, [
            'authenticatedAs' => self::ADMIN,
        ]));
        $this->assertSame(204, $response->getStatusCode(), (string) $response->getBody());

        // Not widened to the whole forum: the mode is off, scores are back to "everyone".
        $settings = $this->database()->table('settings')->pluck('value', 'key');
        $this->assertSame('[]', $settings['tryhackx-topic-rating.author_mode_tags']);
        $this->assertSame('default', $settings['tryhackx-topic-rating.author_mode']);
        $this->assertSame(2, (int) $this->row(self::REVIEW)->rating_count);
        $this->assertSame('default', $this->discussion(self::OTHER)['ratingAuthorMode']);
    }

    #[Test]
    public function deleting_one_of_several_scoped_tags_keeps_the_rest(): void
    {
        $this->authorMode('author_only', [self::REVIEWS_TAG, self::GENERAL_TAG]);

        $response = $this->send($this->request('DELETE', '/api/tags/' . self::REVIEWS_TAG, [
            'authenticatedAs' => self::ADMIN,
        ]));
        $this->assertSame(204, $response->getStatusCode(), (string) $response->getBody());

        $settings = $this->database()->table('settings')->pluck('value', 'key');
        $this->assertSame('["2"]', $settings['tryhackx-topic-rating.author_mode_tags']);
        $this->assertSame('author_only', $settings['tryhackx-topic-rating.author_mode']);
        $this->assertSame('author_only', $this->discussion(self::OTHER)['ratingAuthorMode']);
    }

    #[Test]
    public function retagging_a_discussion_into_the_scope_re_derives_its_score(): void
    {
        $this->assertRated($this->rate(self::MEMBER, self::OTHER, 6));
        $this->assertRated($this->rate(self::MEMBER2, self::OTHER, 2));
        $this->assertSame(2, (int) $this->row(self::OTHER)->rating_count);

        $response = $this->send($this->request('PATCH', '/api/discussions/' . self::OTHER, [
            'authenticatedAs' => self::ADMIN,
            'json' => ['data' => [
                'type' => 'discussions',
                'id' => (string) self::OTHER,
                'relationships' => ['tags' => ['data' => [['type' => 'tags', 'id' => (string) self::REVIEWS_TAG]]]],
            ]],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        // Now a review: only its author's (MEMBER) rating makes up the score.
        $this->assertSame(1, (int) $this->row(self::OTHER)->rating_count);
        $this->assertEqualsWithDelta(3.0, (float) $this->row(self::OTHER)->rating_average, 0.001);
        $this->assertSame('author_only', $this->discussion(self::OTHER)['ratingAuthorMode']);
        $this->assertSame(403, $this->rate(self::MEMBER2, self::OTHER, 10)->getStatusCode());
    }
}
