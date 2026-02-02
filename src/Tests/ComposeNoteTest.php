<?php

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Statamic\Facades\Config;

class ComposeNoteTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // Clean up test data only - preserve real user data
        Entry::query()->whereIn('collection', ['notes', 'activities'])->get()
            ->filter(fn($e) => str_contains($e->slug() ?? '', 'test-') || $e->slug() === 'orig')
            ->each->delete();
        Entry::query()->where('collection', 'actors')->get()
            ->filter(fn($e) => $e->slug() === 'me')
            ->each->delete();
        Config::set('statamic.editions.pro', true);
    }

    #[Test]
    public function it_can_create_note_with_content_warning()
    {
        $this->actingAs(User::make()->id('admin')->makeSuper()->save());

        $actor = Entry::make()->collection('actors')->slug('me')->data(['title' => 'Me']);
        $actor->save();

        $response = $this->postJson(cp_route('activitypub.inbox.store-note'), [
            'content' => 'Secret',
            'actor' => $actor->id(),
            'content_warning' => 'Spoiler'
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $note = Entry::query()->where('collection', 'notes')->get()->last();
        $this->assertEquals('Secret', $note->get('content'));
        $this->assertTrue($note->get('sensitive'));
        $this->assertEquals('Spoiler', $note->get('summary'));
    }

    #[Test]
    public function it_can_reply_with_content_warning()
    {
        $this->actingAs(User::make()->id('admin')->makeSuper()->save());

        $actor = Entry::make()->collection('actors')->slug('me')->data(['title' => 'Me']);
        $actor->save();

        // Original note
        $original = Entry::make()->collection('notes')->slug('orig')->data(['content' => 'Original', 'activitypub_id' => 'orig']);
        $original->save();

        $response = $this->postJson(cp_route('activitypub.inbox.reply'), [
            'content' => 'Reply Secret',
            'actor' => $actor->id(),
            'in_reply_to' => $original->id(),
            'content_warning' => 'Reply Spoiler'
        ]);

        $response->assertCreated();
        $response->assertJson(['success' => true]);

        $reply = Entry::query()->where('collection', 'notes')->where('slug', '!=', 'orig')->get()->last();
        $this->assertEquals('Reply Secret', $reply->get('content'));
        $this->assertTrue($reply->get('sensitive'));
        $this->assertEquals('Reply Spoiler', $reply->get('summary'));
    }
}
