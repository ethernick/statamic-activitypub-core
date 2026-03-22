<?php

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Statamic\Facades\Collection;
use Statamic\Facades\Blink;
class ActivityGenerationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }


    #[\PHPUnit\Framework\Attributes\Test]
    public function it_generates_an_activity_when_a_note_is_created()
    {
        // 1. Create an Actor entry
        $actorEntry = Entry::make()
            ->collection('actors')
            ->slug('test-actor')
            ->data(['title' => 'Test Actor', 'is_internal' => true]);

        $actorEntry->save();
        // dd($actorEntry->path());

        // 2. Create a user to be the actor and associate the actor profile
        $user = User::make()
            ->email('test@example.com')
            ->set('name', 'Test User')
            ->set('actors', [$actorEntry->id()])
            ->save();

        // 2. Simulate logged in user if necessary, or just rely on the fallback logic
        // The listener tries to find an actor from User::current() if not explicitly set on entry
        $this->actingAs($user);

        // 3. Create a Note Entry
        // Ensure 'notes' collection exists (it should in a real app, but in tests we might need to rely on existing state or mock)
        // For an integration test on an existing repo, we assume 'notes' collection is defined in content/collections

        $note = Entry::make()
            ->collection('notes')
            ->slug('test-my-first-note')
            ->data([
                    'title' => 'My First Note',
                    'content' => 'Hello World',
                    'is_internal' => true, // Ensure it's treated as internal so listener processes it
                ]);

        $note->save();

        // 4. Assert an Activity was created
        // The listener creates an entry in 'activities' collection

        $activities = Entry::query()
            ->where('collection', 'activities')
            ->where('type', 'Create') // The activity type
            ->get();

        $activity = $activities->first(function ($entry) use ($note) {
            $object = $entry->get('object');
            return is_array($object) && in_array($note->id(), $object);
        });

        $this->assertNotNull($activity, 'Activity was not created for the new note.');
        
        $this->assertStringStartsWith('Create ', $activity->get('title'));
        
        $objectValue = $activity->value('object');
        $objectId = is_array($objectValue) ? ($objectValue[0] ?? null) : $objectValue;
        $this->assertEquals($note->id(), $objectId);
    }
}
