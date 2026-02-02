<?php

namespace Ethernick\ActivityPubCore\Tests;

use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Tests\TestCase;

class InboxApiTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // Ensure notes collection exists
        if (!\Statamic\Facades\Collection::find('notes')) {
            \Statamic\Facades\Collection::make('notes')->save();
        }
    }

    public function test_inbox_api_includes_internal_items()
    {
        $user = User::make()->id('test-id')->email('test-api@example.com')->makeSuper();
        $user->save();
        $this->actingAs($user);

        // Create an internal note
        $note = Entry::make()
            ->collection('notes')
            ->slug('internal-note-test')
            ->data(['title' => 'Internal Note', 'content' => 'This is internal', 'actor' => ['test-actor'], 'date' => now()->format('Y-m-d H:i')])
            ->published(true);
        $note->set('is_internal', true);
        $note->save();

        $response = $this->get(cp_route('activitypub.inbox.api'));

        $response->assertOk();
        $data = $response->json('data');
        $this->assertTrue(collect($data)->contains('id', $note->id()));
        $apiNote = collect($data)->firstWhere('id', $note->id());
        $this->assertTrue($apiNote['is_internal']);
        $this->assertEquals('This is internal', $apiNote['raw_content']);
    }

    public function test_update_internal_note()
    {
        $user = User::make()->id('test-id-2')->email('test-api2@example.com')->makeSuper();
        $user->save();
        $this->actingAs($user);

        $note = Entry::make()
            ->collection('notes')
            ->slug('note-to-update')
            ->data(['content' => 'Old Content', 'is_internal' => true])
            ->published(true);
        $note->save();

        $response = $this->post(cp_route('activitypub.inbox.update-note'), [
            'id' => $note->id(),
            'content' => 'New Content',
            'content_warning' => 'Updated Warning'
        ]);

        $response->assertOk();
        $this->assertEquals('New Content', $note->fresh()->get('content'));
        $this->assertEquals('Updated Warning', $note->fresh()->get('summary'));
    }

    public function test_cannot_update_external_note()
    {
        $user = User::make()->id('test-id-3')->email('test-api3@example.com')->makeSuper();
        $user->save();
        $this->actingAs($user);

        $note = Entry::make()
            ->collection('notes')
            ->slug('external-note')
            ->data(['content' => 'External', 'is_internal' => false]) // explicitly false
            ->published(true);
        $note->save();

        $response = $this->post(cp_route('activitypub.inbox.update-note'), [
            'id' => $note->id(),
            'content' => 'Hacked Content'
        ]);

        $response->assertStatus(403);
        $this->assertEquals('External', $note->fresh()->get('content'));
    }
}
