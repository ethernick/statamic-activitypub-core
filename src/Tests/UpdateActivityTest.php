<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Ethernick\ActivityPubCore\Jobs\InboxHandler;
use Statamic\Facades\Entry;
use Tests\TestCase;

class UpdateActivityTest extends TestCase
{
    protected $handler;
    protected $localActor;
    protected $remoteActor;

    public function setUp(): void
    {
        parent::setUp();

        // Create activitypub.yaml config in sandbox
        file_put_contents(
            \Ethernick\ActivityPubCore\Services\ActivityPubUtils::settingsPath(),
            "notes:\n  enabled: true\n  type: Note\n  federated: true\npolls:\n  enabled: true\n  type: Question\n  federated: true\nactivities:\n  enabled: true\n  type: Activity\n"
        );

        $this->setupCollections(['actors', 'notes', 'activities', 'polls']);

        $this->handler = new InboxHandler();

        // Create Local Actor in sandbox
        $this->localActor = Entry::make()
            ->collection('actors')
            ->slug('test-update-me')
            ->data(['title' => 'Me']);
        $this->localActor->save();

        // Create Remote Actor in sandbox
        $this->remoteActor = Entry::make()
            ->collection('actors')
            ->slug('test-update-remote')
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
        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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
