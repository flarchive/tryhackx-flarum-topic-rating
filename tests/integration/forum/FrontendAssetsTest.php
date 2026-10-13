<?php

namespace TryHackX\TopicRating\Tests\integration\forum;

use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Renders the forum and admin frontends so this extension's LESS is actually
 * compiled (with core's variables and mixins).
 */
class FrontendAssetsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'tryhackx-topic-rating');
    }

    protected function compiledCss(string $frontend): string
    {
        $css = '';
        foreach (glob($this->tmpDir() . "/public/assets/$frontend*.css") ?: [] as $file) {
            $css .= file_get_contents($file);
        }

        return $css;
    }

    #[Test]
    public function forum_and_admin_styles_compile(): void
    {
        $this->assertSame(200, $this->send($this->request('GET', '/'))->getStatusCode());
        $this->assertSame(200, $this->send($this->request('GET', '/admin', ['authenticatedAs' => 1]))->getStatusCode());

        $forum = $this->compiledCss('forum');
        $this->assertStringContainsString('.AuthorRatingBadge', $forum);
        $this->assertStringContainsString('.RatingsModal-item-avatar .Avatar', $forum);

        $admin = $this->compiledCss('admin');
        // Per-tag cards: up to 4 columns of at least 340px, stretched across the settings column.
        $this->assertStringContainsString('max(min(340px, 100%), calc((100% - 3 * 12px) / 4))', $admin);
        $this->assertMatchesRegularExpression('/\.TagPermissionTree-list\s*\{[^}]*align-self:\s*stretch/', $admin);
        $this->assertStringContainsString('.AuthorRatingSettings', $admin);
    }
}
