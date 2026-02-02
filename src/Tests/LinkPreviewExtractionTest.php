<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Ethernick\ActivityPubCore\Services\LinkPreview;
use Tests\TestCase;

class LinkPreviewExtractionTest extends TestCase
{
    public function test_extracts_normal_link()
    {
        $html = 'Check out <a href="https://example.com">Example Site</a>';
        $url = LinkPreview::extractUrl($html);
        $this->assertEquals('https://example.com', $url);
    }

    public function test_skips_mention()
    {
        $html = 'Hello <a href="https://example.com/u/user">@user</a>, how are you?';
        $url = LinkPreview::extractUrl($html);
        $this->assertNull($url);
    }

    public function test_skips_hashtag()
    {
        $html = 'Loving this <a href="https://example.com/tag/foo">#foo</a>!';
        $url = LinkPreview::extractUrl($html);
        $this->assertNull($url);
    }

    public function test_extracts_valid_mixed_with_mention()
    {
        // First link is mention, second is valid
        $html = 'Hey <a href="https://social.example/u/me">@me</a>, look at <a href="https://cool-site.com">this site</a>';
        $url = LinkPreview::extractUrl($html);
        $this->assertEquals('https://cool-site.com', $url);
    }

    public function test_extracts_valid_mixed_with_hashtag()
    {
        $html = 'Trending <a href="/tag/wow">#wow</a> : <a href="https://news.com">News</a>';
        $url = LinkPreview::extractUrl($html);
        $this->assertEquals('https://news.com', $url);
    }

    public function test_skips_complex_mention()
    {
        // Example from user report
        $html = '<p><span class="h-card" translate="no"><a href="https://hachyderm.io/@thomasfuchs" class="u-url mention">@<span>thomasfuchs</span></a></span> what do you mean by &quot;computer&quot;?</p>';
        $url = LinkPreview::extractUrl($html);
        $this->assertNull($url);
    }

    public function test_image_link_without_text_is_ok()
    {
        // Should default to allowing it if no text starts with @/#
        $html = '<a href="https://img.com"><img src="foo.jpg"></a>';
        $url = LinkPreview::extractUrl($html);
        $this->assertEquals('https://img.com', $url);
    }

    public function test_skips_encoded_mention_symbol()
    {
        // Markdown/HTML parsers might encode @ as &#64;
        $html = '<a href="https://example.com/u/user" class="u-url mention">&#64;user</a>';
        $url = LinkPreview::extractUrl($html);
        $this->assertNull($url);
    }
}
