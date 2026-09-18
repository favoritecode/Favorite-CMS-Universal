<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit;

use FavoriteCMS\Services\ContentSanitizer;
use PHPUnit\Framework\TestCase;

class ContentSanitizerTest extends TestCase
{
    public function testSanitizesDangerousScriptsForStandardUsers(): void
    {
        $dirty = '<p>Hello world</p><script>alert("XSS")</script><p>Second paragraph</p>';
        $cleaned = ContentSanitizer::sanitizeMarkup($dirty);

        $this->assertStringNotContainsString('<script>', $cleaned);
        $this->assertStringNotContainsString('alert("XSS")', $cleaned);
        $this->assertStringContainsString('<p>Hello world</p>', $cleaned);
        $this->assertStringContainsString('<p>Second paragraph</p>', $cleaned);
    }

    public function testStripsInlineEventHandlers(): void
    {
        $dirty = '<p onclick="malicious()" onmouseover="steal()">Text with <img src="x.jpg" onerror="alert(1)"></p>';
        $cleaned = ContentSanitizer::sanitizeMarkup($dirty);

        $this->assertStringNotContainsString('onclick', $cleaned);
        $this->assertStringNotContainsString('onmouseover', $cleaned);
        $this->assertStringNotContainsString('onerror', $cleaned);
        $this->assertStringContainsString('<img src="x.jpg">', $cleaned);
    }

    public function testStripsJavascriptUrlsInLinks(): void
    {
        $dirty = '<a href="javascript:alert(1)">Click Me</a><a href="https://example.com">Safe Link</a>';
        $cleaned = ContentSanitizer::sanitizeMarkup($dirty);

        $this->assertStringNotContainsString('javascript:', $cleaned);
        $this->assertStringContainsString('href="#"', $cleaned);
        $this->assertStringContainsString('href="https://example.com"', $cleaned);
    }

    public function testPreservesRichFormattingAndTables(): void
    {
        $html = '<h2>Section Title</h2>' .
                '<p>This is <strong>bold</strong> and <em>italic</em> with a <a href="https://favorite-cms.org">link</a>.</p>' .
                '<blockquote>A great quote</blockquote>' .
                '<ul><li>Item 1</li><li>Item 2</li></ul>' .
                '<table><thead><tr><th>Col 1</th><th>Col 2</th></tr></thead><tbody><tr><td>Val 1</td><td>Val 2</td></tr></tbody></table>' .
                '<pre><code>$x = 42;</code></pre>' .
                '<hr>';

        $cleaned = ContentSanitizer::sanitizeMarkup($html);

        $this->assertStringContainsString('<h2>Section Title</h2>', $cleaned);
        $this->assertStringContainsString('<strong>bold</strong>', $cleaned);
        $this->assertStringContainsString('<em>italic</em>', $cleaned);
        $this->assertStringContainsString('<blockquote>A great quote</blockquote>', $cleaned);
        $this->assertStringContainsString('<ul><li>Item 1</li><li>Item 2</li></ul>', $cleaned);
        $this->assertStringContainsString('<table><thead><tr><th>Col 1</th><th>Col 2</th></tr></thead>', $cleaned);
        $this->assertStringContainsString('<pre><code>$x = 42;</code></pre>', $cleaned);
    }

    public function testCleansWordPasteJunk(): void
    {
        $wordHtml = '<!--[if gte mso 9]><xml><w:WordDocument></w:WordDocument></xml><![endif]-->' .
                    '<p class="MsoNormal">Paragraph from Microsoft Word<o:p></o:p></p>';

        $cleaned = ContentSanitizer::sanitizeMarkup($wordHtml);

        $this->assertStringNotContainsString('mso', $cleaned);
        $this->assertStringNotContainsString('<o:p>', $cleaned);
        $this->assertStringContainsString('Paragraph from Microsoft Word', $cleaned);
    }

    public function testHandlesPlainTextGracefully(): void
    {
        $plain = "Line 1\nLine 2";
        $cleaned = ContentSanitizer::sanitizeMarkup($plain);

        $this->assertSame("<p>Line 1<br />\nLine 2</p>", $cleaned);
    }
}

