<?php

namespace Ethernick\ActivityPubCore\Tests;

use Ethernick\ActivityPubCore\Http\Controllers\CP\InboxController;
use Illuminate\Http\Request;
use Statamic\Facades\Entry;
use Statamic\Facades\Collection;
use Statamic\Facades\User;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class InboxMarkdownTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create user
        $user = User::make()->email('test@example.com')->makeSuper()->save();
        $this->actingAs($user);

        // Create collection
        if (!Collection::findByHandle('notes')) {
            Collection::make('notes')->save();
        }
    }

    #[Test]
    public function it_renders_markdown_with_strikethrough()
    {
        // Create a note with strikethrough markdown
        $markdown = 'This is ~~strikethrough~~ text.';
        $expectedHtml = '<p>This is <del>strikethrough</del> text.</p>';

        $entry = Entry::make()
            ->collection('notes')
            ->slug('test-note')
            ->data(['content' => $markdown]);

        $entry->save();

        // Instantiating controller directly fails due to dependencies.
        // Use integration test approach instead.
        $response = $this->get(cp_route('activitypub.inbox.api'));

        $response->assertOk();
        $data = $response->json('data');

        // Find our entry in the response
        $note = collect($data)->firstWhere('id', $entry->id());

        $this->assertNotNull($note, 'Note not found in API response');

        // Assert content_raw is present and correct
        $this->assertEquals($markdown, $note['content_raw']);

        // Assert content contains the strikethrough tag
        // Note: Statamic's Markdown parser might use <del> or <s> or <strike>.
        // valid tags in our whitelist: <del><s><strike>

        // Normalize newlines for comparison
        $renderedContent = trim($note['content']);

        $this->assertTrue(
            str_contains($renderedContent, '<del>strikethrough</del>') ||
            str_contains($renderedContent, '<s>strikethrough</s>') ||
            str_contains($renderedContent, '<strike>strikethrough</strike>'),
            "Rendered HTML does not contain strikethrough tag. Got: " . $renderedContent
        );
    }
}
