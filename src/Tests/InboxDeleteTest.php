<?php

namespace Ethernick\ActivityPubCore\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Tests\TestCase;

class InboxDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['statamic.editions.pro' => true]);

        // Clean up test data only - preserve real user data
        Entry::query()->whereIn('collection', ['notes', 'activities'])->get()
            ->filter(function ($e) {
                $slug = $e->slug() ?? '';
                return str_contains($slug, 'test-') || str_contains($slug, 'announce-');
            })
            ->each->delete();
    }

    #[Test]
    public function it_can_delete_a_note_and_its_activity()
    {
        $this->withoutExceptionHandling();
        Event::fake(); // Prevent actual ActivityPub side effects

        $uniqueId = uniqid();
        $url = 'https://example.com/note/' . $uniqueId;

        // Create a Note
        $note = Entry::make()
            ->collection('notes')
            ->slug('test-note-' . $uniqueId)
            ->data(['title' => 'Test Note', 'is_internal' => false, 'activitypub_id' => $url]);

        $note->save();

        // Create a Create Activity for that note
        $activity = Entry::make()
            ->collection('activities')
            ->slug('test-create-note-' . $uniqueId)
            ->data([
                'type' => 'Create',
                'object' => $url,
                'is_internal' => false
            ]);

        $activity->save();

        $this->assertNotNull(Entry::find($note->id()));
        $this->assertNotNull(Entry::find($activity->id()));

        // Act
        $user = User::make()->email('test@example.com')->makeSuper();
        $user->save();

        $response = $this->actingAs($user)
            ->post(cp_route('activitypub.inbox.delete'), ['id' => $note->id()]);

        // Assert
        $response->assertOk();
        $this->assertNull(Entry::find($note->id()));
        $this->assertNull(Entry::find($activity->id()));
    }

    #[Test]
    public function it_can_delete_an_activity()
    {
        $this->withoutExceptionHandling();

        $activity = Entry::make()
            ->collection('activities')
            ->slug('test-announce-something')
            ->data([
                'type' => 'Announce',
                'is_internal' => false
            ]);

        $activity->save();

        $this->assertNotNull(Entry::find($activity->id()));

        // Act
        $user = User::make()->email('test@example.com')->makeSuper();
        $user->save();

        $response = $this->actingAs($user)
            ->post(cp_route('activitypub.inbox.delete'), ['id' => $activity->id()]);

        // Assert
        $response->assertOk();
        $this->assertNull(Entry::find($activity->id()));
    }
}
