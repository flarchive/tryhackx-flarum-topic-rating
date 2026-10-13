<?php

namespace TryHackX\TopicRating\Tests\unit;

use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TryHackX\TopicRating\AuthorMode;
use TryHackX\TopicRating\AuthorModeResolver;

class AuthorModeResolverTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function resolver(string $mode, string $tags = '[]', bool $tagsEnabled = true): AuthorModeResolver
    {
        $settings = Mockery::mock(SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->with(AuthorModeResolver::MODE_SETTING, Mockery::any())->andReturn($mode);
        $settings->shouldReceive('get')->with(AuthorModeResolver::TAGS_SETTING, Mockery::any())->andReturn($tags);

        $extensions = Mockery::mock(ExtensionManager::class);
        $extensions->shouldReceive('isEnabled')->with('flarum-tags')->andReturn($tagsEnabled);

        return new AuthorModeResolver($settings, $extensions);
    }

    protected function discussionTagged(array $tagIds): Discussion
    {
        $discussion = new Discussion();
        $discussion->setRelation('tags', collect(array_map(fn ($id) => (object) ['id' => $id], $tagIds)));

        return $discussion;
    }

    #[Test]
    public function unknown_modes_normalise_to_the_default(): void
    {
        $this->assertSame(AuthorMode::DEFAULT, AuthorMode::normalize('nope'));
        $this->assertSame(AuthorMode::DEFAULT, AuthorMode::normalize(null));
        $this->assertSame(AuthorMode::DEFAULT, AuthorMode::normalize(['separate']));
        $this->assertSame(AuthorMode::SEPARATE, AuthorMode::normalize('separate'));
        $this->assertSame(AuthorMode::DEFAULT, $this->resolver('garbage')->configuredMode());
    }

    #[Test]
    public function only_separate_and_author_only_expose_the_author_rating(): void
    {
        $this->assertTrue(AuthorMode::exposesAuthorRating(AuthorMode::SEPARATE));
        $this->assertTrue(AuthorMode::exposesAuthorRating(AuthorMode::AUTHOR_ONLY));
        $this->assertFalse(AuthorMode::exposesAuthorRating(AuthorMode::EXCLUDE));
        $this->assertFalse(AuthorMode::exposesAuthorRating(AuthorMode::DEFAULT));
    }

    public static function tagIdInputs(): array
    {
        return [
            'json list' => ['[1,"2",3]', ['1', '2', '3']],
            'duplicates' => ['["4","4",4]', ['4']],
            'junk dropped' => ['[0,-1,"x","5a",null,true,6]', ['6']],
            'not a list' => ['{"a":1}', ['1']],
            'malformed' => ['[1,', []],
            'empty' => ['', []],
            'null' => [null, []],
        ];
    }

    #[Test]
    #[DataProvider('tagIdInputs')]
    public function tag_scope_is_parsed_defensively(mixed $raw, array $expected): void
    {
        $this->assertSame($expected, AuthorModeResolver::parseTagIds($raw));
    }

    #[Test]
    public function an_unscoped_mode_applies_everywhere(): void
    {
        $resolver = $this->resolver(AuthorMode::AUTHOR_ONLY);

        $this->assertSame(AuthorMode::AUTHOR_ONLY, $resolver->resolve($this->discussionTagged([])));
        $this->assertSame(AuthorMode::AUTHOR_ONLY, $resolver->resolve($this->discussionTagged([7])));
    }

    #[Test]
    public function a_scoped_mode_applies_only_to_discussions_in_those_tags(): void
    {
        $resolver = $this->resolver(AuthorMode::SEPARATE, '["3","5"]');

        $this->assertSame(AuthorMode::SEPARATE, $resolver->resolve($this->discussionTagged([1, 5])));
        $this->assertSame(AuthorMode::DEFAULT, $resolver->resolve($this->discussionTagged([1, 2])));
        $this->assertSame(AuthorMode::DEFAULT, $resolver->resolve($this->discussionTagged([])));
    }

    #[Test]
    public function the_tag_scope_is_ignored_while_tags_is_disabled(): void
    {
        $resolver = $this->resolver(AuthorMode::EXCLUDE, '["3"]', false);

        $this->assertSame([], $resolver->scopeTagIds());
        $this->assertSame(AuthorMode::EXCLUDE, $resolver->resolve($this->discussionTagged([1])));
    }

    #[Test]
    public function the_default_mode_never_looks_at_tags(): void
    {
        $this->assertSame(AuthorMode::DEFAULT, $this->resolver(AuthorMode::DEFAULT, '["3"]')->resolve(new Discussion()));
    }
}
