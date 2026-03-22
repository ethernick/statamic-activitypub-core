<?php

namespace Ethernick\ActivityPubCore\Tests;

use Ethernick\ActivityPubCore\Jobs\InboxHandler;
use Statamic\Facades\Entry;
use Tests\TestCase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;

class StrayActivityTest extends TestCase
{
    protected $handler;
    protected $localActor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new InboxHandler();

        // Ensure collections exist in sandbox
        $collections = ['notes', 'actors', 'activities'];
        foreach ($collections as $handle) {
            if (!\Statamic\Facades\Collection::find($handle)) {
                $col = \Statamic\Facades\Collection::make($handle);
                if ($handle !== 'actors') $col->dated(true);
                if ($handle === 'actors') $col->route('/actor/{slug}');
                $col->save();
            }
        }

        // Create activitypub.yaml config in sandbox
        file_put_contents(
            \Ethernick\ActivityPubCore\Services\ActivityPubUtils::settingsPath(),
            "notes:\n  enabled: true\n  type: Note\n  federated: true\npolls:\n  enabled: true\n  type: Question\n  federated: true\nactivities:\n  enabled: true\n  type: Activity\n"
        );

        // Create a local actor in sandbox
        $this->localActor = Entry::make()
            ->collection('actors')
            ->slug('test-stray-local-actor')
            ->data([
                'title' => 'Local Actor',
                'is_internal' => true,
                'following_actors' => [],
                'followed_by_actors' => [],
            ]);
        $this->localActor->save();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_discards_stray_delete_activity()
    {
        // Stranger actor (not following, not followed) - Ephemeral (Not Saved)
        $stranger = Entry::make()
            ->collection('actors')
            ->slug('stranger')
            ->data(['title' => 'Stranger', 'activitypub_id' => 'https://example.com/users/stranger', 'is_internal' => false]);
        // $stranger->save(); // INTENTIONALLY NOT SAVED

        $payload = [
            'type' => 'Delete',
            'actor' => 'https://example.com/users/stranger',
            'object' => 'https://example.com/users/stranger/statuses/12345',
            'id' => 'https://example.com/users/stranger/activities/delete-123',
        ];

        // Ensure object does not exist
        $this->assertNull(Entry::query()->where('collection', 'notes')->where('activitypub_id', 'https://example.com/users/stranger/statuses/12345')->first());

        // Process
        $this->handler->handle($payload, $this->localActor, $stranger);

        // Assert Activity was NOT saved
        $activity = Entry::query()->where('collection', 'activities')->where('activitypub_id', 'https://example.com/users/stranger/activities/delete-123')->first();
        $this->assertNull($activity, 'Stray Delete activity should not be saved');

        // Assert Actor was NOT saved
        $savedActor = Entry::query()->where('collection', 'actors')->where('activitypub_id', 'https://example.com/users/stranger')->first();
        $this->assertNull($savedActor, 'Stray Actor should not be saved');

    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_processes_valid_delete_activity_for_existing_object()
    {
        // Stranger actor (author of the note)
        $stranger = Entry::make()
            ->collection('actors')
            ->slug('stranger-author')
            ->data(['title' => 'Stranger Author', 'activitypub_id' => 'https://example.com/users/author', 'is_internal' => false]);
        $stranger->save();

        // Create the valid object first
        $note = Entry::make()
            ->collection('notes')
            ->slug('note-to-delete')
            ->data([
                'title' => 'Note to Delete',
                'activitypub_id' => 'https://example.com/users/author/statuses/existing-note',
                'actor' => $stranger->id(),
            ]);
        $note->save();

        $payload = [
            'type' => 'Delete',
            'actor' => 'https://example.com/users/author',
            'object' => 'https://example.com/users/author/statuses/existing-note',
            'id' => 'https://example.com/users/author/activities/delete-valid',
        ];

        // Process
        $this->handler->handle($payload, $this->localActor, $stranger);

        // Assert Note IS deleted
        $deletedNote = Entry::query()->where('collection', 'notes')->where('activitypub_id', 'https://example.com/users/author/statuses/existing-note')->first();
        $this->assertNull($deletedNote, 'Existing note should be deleted');

        // Assert Activity IS saved
        $activity = Entry::query()->where('collection', 'activities')->where('activitypub_id', 'https://example.com/users/author/activities/delete-valid')->first();
        $this->assertNotNull($activity, 'Valid Delete activity should be saved');

        // Assert Actor IS saved (because we processed a valid activity from them)
        // Even if they were ephemeral originally, handling a valid activity should persist them.
        // But here we started with saved actor because they authored the note.


        $stranger->delete();
        if ($activity)
            $activity->delete();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_discards_stray_update_activity()
    {
        // Stranger actor - Ephemeral
        $stranger = Entry::make()
            ->collection('actors')
            ->slug('stranger-update')
            ->data(['title' => 'Stranger Update', 'activitypub_id' => 'https://example.com/users/stranger-update', 'is_internal' => false]);
        // $stranger->save(); // INTENTIONALLY NOT SAVED

        $payload = [
            'type' => 'Update',
            'actor' => 'https://example.com/users/stranger-update',
            'object' => [
                'id' => 'https://example.com/users/stranger-update/statuses/unknown-note',
                'content' => 'Updated content'
            ],
            'id' => 'https://example.com/users/stranger-update/activities/update-123',
        ];

        // Ensure object does not exist
        $this->assertNull(Entry::query()->where('collection', 'notes')->where('activitypub_id', 'https://example.com/users/stranger-update/statuses/unknown-note')->first());

        // Process
        $this->handler->handle($payload, $this->localActor, $stranger);

        // Assert Activity was NOT saved
        $activity = Entry::query()->where('collection', 'activities')->where('activitypub_id', 'https://example.com/users/stranger-update/activities/update-123')->first();
        $this->assertNull($activity, 'Stray Update activity should not be saved');

        // Assert Actor was NOT saved
        $savedActor = Entry::query()->where('collection', 'actors')->where('activitypub_id', 'https://example.com/users/stranger-update')->first();
        $this->assertNull($savedActor, 'Stray Actor should not be saved');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_processes_update_activity_if_object_exists()
    {
        // 1. Create Valid Author and Note first
        $originalAuthor = Entry::make()
            ->collection('actors')
            ->slug('original-author')
            ->data(['title' => 'Original Author', 'activitypub_id' => 'https://example.com/users/stranger-valid', 'is_internal' => false]);
        $originalAuthor->save();

        $note = Entry::make()
            ->collection('notes')
            ->slug('note-to-update')
            ->data([
                'title' => 'Original Content',
                'content' => 'Original Content',
                'activitypub_id' => 'https://example.com/users/stranger-valid/statuses/existing-note',
                'actor' => $originalAuthor->id(),
            ]);
        $note->save();

        // 2. Delete the Author to simulate "Actor missing but Note exists" (or ActorResolver returning new instance)
        $originalAuthor->delete();

        // 3. Create Ephemeral Actor (Same AP ID)
        $stranger = Entry::make()
            ->collection('actors')
            ->slug('original-author') // Re-use slug/details
            ->data(['title' => 'Stranger Update Valid', 'activitypub_id' => 'https://example.com/users/stranger-valid', 'is_internal' => false]);
        // DO NOT SAVE

        $payload = [
            'type' => 'Update',
            'actor' => 'https://example.com/users/stranger-valid',
            'object' => [
                'id' => 'https://example.com/users/stranger-valid/statuses/existing-note',
                'content' => 'Updated Content',
                'type' => 'Note'
            ],
            'id' => 'https://example.com/users/stranger-valid/activities/update-valid',
        ];

        \Statamic\Facades\Blink::flush();

        // Process
        $this->handler->handle($payload, $this->localActor, $stranger);

        \Statamic\Facades\Blink::flush();

        // Assert Note IS updated
        \Statamic\Facades\Stache::clear();
        $note = Entry::find($note->id());
        $this->assertEquals('Updated Content', $note->get('content'));

        // Assert Activity IS saved
        $activity = Entry::query()->where('collection', 'activities')->where('activitypub_id', 'https://example.com/users/stranger-valid/activities/update-valid')->first();
        $this->assertNotNull($activity, 'Valid Update activity should be saved');

        // Assert Actor (the new ephemeral one) IS SAVED (restored)
        // Because handle() saves it at the end
        $restoredActor = Entry::query()->where('collection', 'actors')->where('activitypub_id', 'https://example.com/users/stranger-valid')->first();
        $this->assertNotNull($restoredActor, 'Actor should be restored/saved');

        // Cleanup
        $note->delete();
        if ($activity)
            $activity->delete();
        if ($restoredActor)
            $restoredActor->delete();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_processes_delete_activity_from_followed_actor_even_if_object_not_found()
    {
        // Followed actor
        $friend = Entry::make()
            ->collection('actors')
            ->slug('friend')
            ->data(['title' => 'Friend', 'activitypub_id' => 'https://example.com/users/friend', 'is_internal' => false]);
        $friend->save();

        // Make local actor follow them
        $this->localActor->set('following_actors', [$friend->id()]);
        $this->localActor->save();

        $payload = [
            'type' => 'Delete',
            'actor' => 'https://example.com/users/friend',
            'object' => 'https://example.com/users/friend/statuses/unknown-note',
            'id' => 'https://example.com/users/friend/activities/delete-unknown',
        ];

        // Process
        $this->handler->handle($payload, $this->localActor, $friend);

        // Assert Activity IS saved (because we follow them, we keep their history/activites?)
        // The implementation plan logic says:
        // If Connected but Object Not Found: Process normally (maybe just save Activity for record).

        $activity = Entry::query()->where('collection', 'activities')->where('activitypub_id', 'https://example.com/users/friend/activities/delete-unknown')->first();
        $this->assertNotNull($activity, 'Delete activity from followed actor should be saved even if object unknown');

        $friend->delete();
        if ($activity)
            $activity->delete();
    }
}
