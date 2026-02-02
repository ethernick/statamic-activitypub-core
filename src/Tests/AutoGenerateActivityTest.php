<?php

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Statamic\Facades\Config;
use Illuminate\Support\Facades\Event;

class AutoGenerateActivityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure Pro is on for collection features
        config(['statamic.editions.pro' => true]);

        // Clean up any previous test artifacts
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    protected function cleanup()
    {
        Entry::query()
            ->whereIn('collection', ['notes', 'activities', 'actors'])
            ->get()
            ->filter(fn($entry) => str_contains($entry->slug(), 'test-auto-gen'))
            ->each->delete();
    }

    public function test_creating_internal_note_generates_create_activity()
    {
        // 1. Create a local actor
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-auto-gen-actor')
            ->data([
                'title' => 'Test Actor',
                'is_internal' => true,
            ]);
        $actor->save();

        // 2. Create a Note
        // This should trigger AutoGenerateActivityListener via EntrySaved event
        $note = Entry::make()
            ->collection('notes')
            ->slug('test-auto-gen-note')
            ->data([
                'content' => 'This is a test note to check activity generation.',
                'actor' => $actor->id(),
                'is_internal' => true,
                'published' => true,
            ]);

        $note->save();

        // 3. Assert Activity Created
        $activity = Entry::query()
            ->where('collection', 'activities')
            ->where('slug', 'like', 'activity-%') // The listener uses uniqid('activity-')
            ->get()
            ->first(function ($entry) use ($note) {
                // Check if this activity is for our note
                $object = $entry->get('object');
                return $object && in_array($note->id(), $object);
            });

        $this->assertNotNull($activity, 'An activity should have been generated for the new note.');
        $this->assertEquals('Create', $activity->get('type'));
        $this->assertEquals([$actor->id()], $activity->get('actor'));
        $this->assertContains('outbox', $activity->get('activitypub_collections'));
    }

    public function test_updating_note_generates_update_activity()
    {
        // 1. Create Actor & Note (Initial Save = Create Activity)
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-auto-gen-actor-update')
            ->data(['title' => 'Test Actor', 'is_internal' => true]);
        $actor->save();

        $note = Entry::make()
            ->collection('notes')
            ->slug('test-auto-gen-note-update')
            ->data([
                'content' => 'Original Content',
                'actor' => $actor->id(),
                'is_internal' => true,
                'published' => true,
            ]);
        $note->save();

        // Clear only test activities from creation (those for test-auto-gen notes)
        Entry::query()->where('collection', 'activities')->get()
            ->filter(function ($entry) use ($note) {
                $object = $entry->get('object');
                if (!$object) {
                    return false;
                }
                // Handle both string and array formats
                if (is_string($object)) {
                    return $object === $note->id();
                }
                return is_array($object) && in_array($note->id(), $object);
            })
            ->each->delete();

        // Reload entry to clear 'is_new' supplement from previous save
        $note = Entry::find($note->id());

        // 2. Update the Note
        $note->set('content', 'Updated Content');
        $note->save();

        // 3. Assert Update Activity
        $activity = Entry::query()
            ->where('collection', 'activities')
            ->get()
            ->first(function ($entry) use ($note) {
                $object = $entry->get('object');
                if (!$object) {
                    return false;
                }
                // Handle both string and array formats
                if (is_string($object)) {
                    return $object === $note->id();
                }
                return is_array($object) && in_array($note->id(), $object);
            });

        $this->assertNotNull($activity, 'An Update activity should have been generated.');
        $this->assertEquals('Update', $activity->get('type'));
    }
}
