<?php

namespace TryHackX\TopicRating\Tests\integration;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Psr\Http\Message\ResponseInterface;
use TryHackX\TopicRating\RatingRecalculator;

/**
 * Shared fixture + helpers: an admin (1, from the install), the author of
 * discussion 1 (2), two other members (3, 4), and discussion 2 started by 3.
 */
abstract class RatingTestCase extends TestCase
{
    protected const ADMIN = 1;
    protected const AUTHOR = 2;
    protected const MEMBER = 3;
    protected const MEMBER2 = 4;

    protected const REVIEW = 1;   // started by AUTHOR
    protected const OTHER = 2;    // started by MEMBER

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('tryhackx-topic-rating');

        $this->prepareDatabase([
            User::class => [
                ['id' => self::AUTHOR, 'username' => 'author', 'email' => 'author@machine.local', 'is_email_confirmed' => 1],
                ['id' => self::MEMBER, 'username' => 'member', 'email' => 'member@machine.local', 'is_email_confirmed' => 1],
                ['id' => self::MEMBER2, 'username' => 'member2', 'email' => 'member2@machine.local', 'is_email_confirmed' => 1],
            ],
            Discussion::class => [
                ['id' => self::REVIEW, 'title' => 'A review', 'user_id' => self::AUTHOR, 'first_post_id' => 1, 'comment_count' => 1, 'last_post_number' => 1],
                ['id' => self::OTHER, 'title' => 'Another topic', 'user_id' => self::MEMBER, 'first_post_id' => 2, 'comment_count' => 1, 'last_post_number' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => self::REVIEW, 'user_id' => self::AUTHOR, 'type' => 'comment', 'content' => '<t><p>Review</p></t>', 'number' => 1],
                ['id' => 2, 'discussion_id' => self::OTHER, 'user_id' => self::MEMBER, 'type' => 'comment', 'content' => '<t><p>Other</p></t>', 'number' => 1],
            ],
        ]);
    }

    protected function authorMode(string $mode, array $tagIds = []): void
    {
        $this->setting('tryhackx-topic-rating.author_mode', $mode);
        $this->setting('tryhackx-topic-rating.author_mode_tags', json_encode($tagIds));
    }

    protected function rate(int $userId, int $discussionId, int $value): ResponseInterface
    {
        return $this->send($this->request('POST', '/api/discussion-ratings', [
            'authenticatedAs' => $userId,
            'json' => ['data' => ['attributes' => ['discussionId' => $discussionId, 'rating' => $value]]],
        ]));
    }

    protected function unrate(int $userId, int $discussionId): ResponseInterface
    {
        return $this->send($this->request('DELETE', '/api/discussion-ratings', [
            'authenticatedAs' => $userId,
            'json' => ['data' => ['attributes' => ['discussionId' => $discussionId]]],
        ]));
    }

    protected function assertRated(ResponseInterface $response): void
    {
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * The discussion's API attributes as seen by $asUser (null = guest).
     */
    protected function discussion(int $id, ?int $asUser = null): array
    {
        $response = $this->send($this->request('GET', '/api/discussions/' . $id, $asUser ? ['authenticatedAs' => $asUser] : []));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true)['data']['attributes'];
    }

    /**
     * Ids of the users whose ratings the ratings modal lists, sorted.
     */
    protected function listedRaters(int $discussionId, ?int $asUser = null): array
    {
        $request = $this->request('GET', '/api/discussion-ratings', $asUser ? ['authenticatedAs' => $asUser] : [])
            ->withQueryParams(['discussion_id' => (string) $discussionId]);
        $response = $this->send($request);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $ids = array_map(
            fn (array $r) => (int) $r['relationships']['user']['data']['id'],
            json_decode((string) $response->getBody(), true)['data']
        );
        sort($ids);

        return $ids;
    }

    protected function poll(int $discussionId, ?int $asUser = null, array $params = []): array
    {
        $request = $this->request('GET', '/api/discussion-ratings/poll', $asUser ? ['authenticatedAs' => $asUser] : [])
            ->withQueryParams(array_merge(['discussion_id' => (string) $discussionId], $params));
        $response = $this->send($request);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    /**
     * The stored aggregate columns, straight from the database.
     */
    protected function row(int $discussionId): object
    {
        return $this->database()->table('discussions')->where('id', $discussionId)->first();
    }

    /**
     * Insert a rating row directly (e.g. one left from before a mode switch).
     */
    protected function insertRating(int $userId, int $discussionId, int $value): void
    {
        $this->database()->table('discussion_ratings')->insert([
            'discussion_id' => $discussionId,
            'user_id' => $userId,
            'rating' => $value,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    protected function recalculator(): RatingRecalculator
    {
        return $this->app()->getContainer()->make(RatingRecalculator::class);
    }
}
