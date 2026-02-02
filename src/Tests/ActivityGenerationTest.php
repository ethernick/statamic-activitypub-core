<?php

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Statamic\Facades\Collection;
use Statamic\Facades\Blink;

class ActivityGenerationTest extends TestCase
{
    protected $settingsPath;
    protected $backupPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settingsPath = resource_path('settings/activitypub.yaml');
        $this->backupPath = resource_path('settings/activitypub.yaml.bak');

        // Ensure collections exist
        if (!\Statamic\Facades\Collection::find('notes')) {
            \Statamic\Facades\Collection::make('notes')->save();
        }
        if (!\Statamic\Facades\Collection::find('activities')) {
            \Statamic\Facades\Collection::make('activities')->save();
        }
        if (!\Statamic\Facades\Collection::find('actors')) {
            \Statamic\Facades\Collection::make('actors')->save();
        }

        // Backup existing config if present
        if (file_exists($this->settingsPath)) {
            copy($this->settingsPath, $this->backupPath);
        } else {
            // Create dir if needed
            if (!file_exists(dirname($this->settingsPath))) {
                mkdir(dirname($this->settingsPath), 0755, true);
            }
        }

        // Write test config
        file_put_contents(
            $this->settingsPath,
            "notes:\n  enabled: true\n  type: Note\n  federated: true\nactivities:\n  enabled: true\n  type: Activity\n"
        );
    }

    protected function tearDown(): void
    {
        // Cleanup only test entries - preserve real user data
        \Statamic\Facades\Entry::query()->where('collection', 'notes')->get()
            ->filter(fn($e) => $e->slug() === 'my-first-note')
            ->each->delete();
        \Statamic\Facades\Entry::query()->where('collection', 'actors')->get()
            ->filter(fn($e) => $e->slug() === 'test-actor')
            ->each->delete();
        \Statamic\Facades\Entry::query()->where('collection', 'activities')->get()
            ->filter(fn($e) => str_starts_with($e->get('title') ?? '', 'Create '))
            ->each->delete();

        // Restore backup or delete if it didn't exist
        if (file_exists($this->backupPath)) {
            rename($this->backupPath, $this->settingsPath);
        } elseif (file_exists($this->settingsPath)) {
            unlink($this->settingsPath);
        }

        parent::tearDown();
    }


    #[Test]
    public function it_generates_an_activity_when_a_note_is_created()
    {
        // 1. Create an Actor entry
        $actorEntry = Entry::make()
            ->collection('actors')
            ->slug('test-actor')
            ->data(['title' => 'Test Actor', 'is_internal' => true]);

        $actorEntry->save();

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
            ->slug('my-first-note')
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
        $this->assertEquals($note->id(), $activity->get('object')[0]);
    }
}
