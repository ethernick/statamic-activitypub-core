<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Ethernick\ActivityPubCore\Jobs\InboxHandler;
use Statamic\Facades\Entry;
use Tests\TestCase;
use Ethernick\ActivityPubCore\Tests\Concerns\BackupsFiles;

class UpdateActivityTest extends TestCase
{
    use BackupsFiles;

    protected $handler;
    protected $localActor;
    protected $remoteActor;

    public function setUp(): void
    {
        parent::setUp();

        // Backup settings file before modifying it
        $this->backupFile('resources/settings/activitypub.yaml');

        // Create activitypub.yaml config with federated: true
        if (!file_exists(resource_path('settings'))) {
            mkdir(resource_path('settings'), 0755, true);
        }
        file_put_contents(
            resource_path('settings/activitypub.yaml'),
            "notes:\n  enabled: true\n  type: Note\n  federated: true\npolls:\n  enabled: true\n  type: Question\n  federated: true\nactivities:\n  enabled: true\n  type: Activity\n"
        );

        $this->handler = new InboxHandler();

        // Setup Helpers
        // Create Local Actor
        $this->localActor = Entry::make()
            ->collection('actors')
            ->slug('me')
            ->data(['title' => 'Me']);
        $this->localActor->save();

        // Create Remote Actor (Followed)
        $this->remoteActor = Entry::make()
            ->collection('actors')
            ->slug('remote')
            ->data([
                'title' => 'Remote',
                'activitypub_id' => 'https://remote.com/users/alice',
                'inbox_url' => 'https://remote.com/users/alice/inbox'
            ]);
        $this->remoteActor->save();

        // Follow them
        $this->localActor->set('following_actors', [$this->remoteActor->id()]);
        $this->localActor->save();
    }

    protected function tearDown(): void
    {
        if ($this->localActor)
            $this->localActor->delete();
        if ($this->remoteActor)
            $this->remoteActor->delete();
        // Only delete test notes (those with remote.com in activitypub_id)
        Entry::query()->where('collection', 'notes')->get()
            ->filter(fn($e) => str_contains($e->get('activitypub_id') ?? '', 'remote.com'))
            ->each->delete();

        // Restore activitypub.yaml from git to prevent test pollution
        // Restore backed up files
        $this->restoreBackedUpFiles();

        parent::tearDown();
    }

    #[Test]
    public function it_updates_correct_note_entity()
    {
        // 1. Create Note
        $noteId = 'https://remote.com/users/alice/status/1';
        $note = Entry::make()
            ->collection('notes')
            ->slug('note-1')
            ->data([
                'activitypub_id' => $noteId,
                'content' => 'Old Content',
                'summary' => 'Old Summary',
                'actor' => $this->remoteActor->id(),
            ]);
        $note->save();

        $count = Entry::query()->where('collection', 'notes')->where('activitypub_id', $noteId)->count();
        $found = Entry::query()->where('collection', 'notes')->where('activitypub_id', $noteId)->get();

        // 2. Incoming Update Activity
        $payload = [
            'type' => 'Update',
            'actor' => 'https://remote.com/users/alice',
            'object' => [
                'id' => $noteId,
                'type' => 'Note',
                'content' => 'New Content',
                'summary' => 'New Summary',
                'published' => now()->toIso8601String(),
            ]
        ];

        // 3. Process
        $this->handler->handle($payload, $this->localActor, $this->remoteActor);

        \Statamic\Facades\Blink::flush();
        \Statamic\Facades\Stache::clear();

        // 4. Verify
        $path = $note->path();

        $note = $note->fresh();
        $this->assertEquals('New Content', $note->get('content'));
        $this->assertEquals('New Summary', $note->get('summary'));
    }

    #[Test]
    public function it_supports_partial_updates_preserving_summary()
    {
        // 1. Create Note
        $noteId = 'https://remote.com/users/alice/status/2';
        $note = Entry::make()
            ->collection('notes')
            ->slug('note-2')
            ->data([
                'activitypub_id' => $noteId,
                'content' => 'Preserved Content',
                'summary' => 'Preserved Summary',
                'actor' => $this->remoteActor->id(),
            ]);
        $note->save();

        // 2. Incoming Update Activity (Partial - only updating content, summary missing)
        $payload = [
            'type' => 'Update',
            'actor' => 'https://remote.com/users/alice',
            'object' => [
                'id' => $noteId,
                'type' => 'Note',
                'content' => 'New Content',
                // 'summary' is MISSING
            ]
        ];

        // 3. Process
        $this->handler->handle($payload, $this->localActor, $this->remoteActor);

        \Statamic\Facades\Blink::flush();
        \Statamic\Facades\Stache::clear();

        // 4. Verify
        $note = $note->fresh();
        $this->assertEquals('New Content', $note->get('content'), 'Content should update');
        $this->assertEquals('Preserved Summary', $note->get('summary'), 'Summary should be preserved if missing in payload');
    }

    #[Test]
    public function it_updates_actor_profile_if_id_matches_actor_and_no_note_found()
    {
        // 1. Ensure no Note exists with Actor ID (unlikely but possible identifier collision check)

        // 2. Incoming Update for Actor
        $payload = [
            'type' => 'Update',
            'actor' => 'https://remote.com/users/alice',
            'object' => [
                'id' => 'https://remote.com/users/alice',
                'type' => 'Person',
                'name' => 'Alice Updated',
                'summary' => 'Updated Bio',
            ]
        ];

        // 3. Process
        $this->handler->handle($payload, $this->localActor, $this->remoteActor);

        \Statamic\Facades\Blink::flush();
        \Statamic\Facades\Stache::clear();

        // 4. Verify
        $remote = $this->remoteActor->fresh();
        $this->assertEquals('Alice Updated', $remote->get('title'));
        $this->assertEquals('Updated Bio', $remote->get('content'));
    }
}
