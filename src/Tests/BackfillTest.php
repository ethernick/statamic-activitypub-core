<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Ethernick\ActivityPubCore\Jobs\FileQueue;
use Ethernick\ActivityPubCore\Jobs\BackfillActorOutbox;

class BackfillTest extends TestCase
{
    protected $localActor;
    protected $externalActor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['statamic.editions.pro' => true]);

        // Cleanup queues
        $this->cleanupQueues();

        // Create Local Actor
        $this->localActor = Entry::query()->where('collection', 'actors')->where('slug', 'ethernick')->first();
        if (!$this->localActor) {
            $this->localActor = Entry::make()->collection('actors')->slug('ethernick')->data(['title' => 'Nick', 'is_internal' => true])->published(true);
            $this->localActor->save();
        }

        // Create External Actor
        $this->externalActor = Entry::make()->collection('actors')->slug('external-backfill')->data([
            'title' => 'Backfill User',
            'activitypub_id' => 'https://external.com/users/backfill',
            'outbox_url' => 'https://external.com/users/backfill/outbox',
        ])->published(true);
        $this->externalActor->save();
    }

    protected function tearDown(): void
    {
        $this->cleanupQueues();
        if ($this->externalActor)
            $this->externalActor->delete();
        parent::tearDown();
    }

    protected function cleanupQueues()
    {
        Storage::disk('local')->deleteDirectory('activitypub/inbox');
        Storage::disk('local')->makeDirectory('activitypub/inbox');
    }

    public function test_backfill_queues_outbox_items()
    {
        // Mock Outbox
        Http::fake([
            'https://external.com/users/backfill/outbox' => Http::response([
                'type' => 'OrderedCollection',
                'totalItems' => 2,
                'first' => 'https://external.com/users/backfill/outbox?page=true',
            ], 200),
            'https://external.com/users/backfill/outbox?page=true' => Http::response([
                'type' => 'OrderedCollectionPage',
                'orderedItems' => [
                    [
                        'id' => 'https://external.com/activities/create-1',
                        'type' => 'Create',
                        'actor' => 'https://external.com/users/backfill',
                        'object' => [
                            'id' => 'https://external.com/notes/note-1',
                            'type' => 'Note',
                            'content' => 'Note 1 Content',
                        ]
                    ],
                    [
                        'id' => 'https://external.com/activities/create-2',
                        'type' => 'Create',
                        'actor' => 'https://external.com/users/backfill',
                        'object' => [
                            'id' => 'https://external.com/notes/note-2',
                            'type' => 'Note',
                            'content' => 'Note 2 Content',
                        ]
                    ]
                ]
            ], 200),
        ]);

        // Run Job
        $job = new BackfillActorOutbox($this->localActor->id(), $this->externalActor->id());
        $job->handle();

        // Assert items in queue
        $queue = new FileQueue();
        $files = $queue->list('inbox');
        $this->assertCount(2, $files);

        // Verify content of first file
        $data = $queue->get($files[0]); // Sorted, but might be random UUID? FileQueue sorts by name (timestamp prefix).
        // Since we pushed in loop, order should be preserved roughly by timestamp (maybe same second though).

        $this->assertEquals($this->localActor->id(), $data['local_actor_id']);
        $this->assertEquals($this->externalActor->id(), $data['external_actor_id']);

        $payloads = [];
        foreach ($files as $f) {
            $d = $queue->get($f);
            $payloads[] = $d['payload']['object']['content'];
        }

        sort($payloads); // Sorting content strings just to ignore file order
        $this->assertEquals(['Note 1 Content', 'Note 2 Content'], $payloads);
    }
}
