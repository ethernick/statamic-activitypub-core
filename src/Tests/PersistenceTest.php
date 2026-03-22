<?php

namespace Ethernick\ActivityPubCore\Tests;

use Ethernick\ActivityPubCore\Jobs\InboxHandler;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Statamic\Facades\Entry;
use Mockery;

class PersistenceTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // Ensure settings exist in sandbox
        file_put_contents(
            \Ethernick\ActivityPubCore\Services\ActivityPubUtils::settingsPath(),
            "notes:\n  enabled: true\n  type: Note\n  federated: true\npolls:\n  enabled: true\n  type: Question\n  federated: true\nactivities:\n  enabled: true\n  type: Activity\n"
        );

        // Ensure collections exist in sandbox
        $this->setupCollections(['actors', 'activities', 'notes']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_does_not_persist_actor_if_create_activity_is_ignored()
    {
        // 1. Setup Local Actor
        $localActor = Entry::make()->collection('actors')->slug('me')->data(['title' => 'Me', 'username' => 'me', 'following_actors' => []]);
        $localActor->save();

        // 2. Setup External Actor (Ephemeral)
        $externalId = 'https://example.com/users/stranger';
        $resolver = new \Ethernick\ActivityPubCore\Services\ActorResolver();

        // Mock the resolved actor without saving it
        Http::fake([
            $externalId => Http::response([
                'id' => $externalId,
                'type' => 'Person',
                'name' => 'Stranger',
                'preferredUsername' => 'stranger',
                'inbox' => $externalId . '/inbox',
                'outbox' => $externalId . '/outbox',
                'publicKey' => ['publicKeyPem' => '...'],
            ])
        ]);

        $externalActor = $resolver->resolve($externalId, false);
        $this->assertNull($externalActor->id(), 'External actor should be ephemeral initially');

        // 3. Process CREATE activity (Should be Ignored)
        $this->setupCollections(['notes', 'actors', 'activities']);
        $payload = [
            'type' => 'Create',
            'actor' => $externalId,
            'id' => $externalId . '/activities/1',
            'object' => [
                'type' => 'Note',
                'id' => $externalId . '/notes/1',
                'content' => 'Hello World',
                'attributedTo' => $externalId,
            ]
        ];

        $handler = new InboxHandler();
        $handler->handle($payload, $localActor, $externalActor);

        // 4. Verification
        // Actor should NOT be in DB
        $savedActor = Entry::query()->where('collection', 'actors')->where('activitypub_id', $externalId)->first();
        $this->assertNull($savedActor, 'Stranger actor should NOT be saved to DB');

        // Activity should NOT be logged
        $savedActivity = Entry::query()->where('collection', 'activities')->where('activitypub_id', $payload['id'])->first();
        $this->assertNull($savedActivity, 'Ignored activity should NOT be logged');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_persists_actor_if_followed()
    {
        // 1. Setup Local Actor
        $localActor = Entry::make()->collection('actors')->slug('me')->data(['title' => 'Me', 'username' => 'me', 'following_actors' => []]);
        $localActor->save();

        // 2. Setup External Actor
        $externalId = 'https://example.com/users/friend';

        Http::fake([
            $externalId => Http::response([
                'id' => $externalId,
                'type' => 'Person',
                'name' => 'Friend',
                'preferredUsername' => 'friend',
                'inbox' => $externalId . '/inbox',
                'publicKey' => ['publicKeyPem' => '...'],
            ])
        ]);

        $resolver = new \Ethernick\ActivityPubCore\Services\ActorResolver();
        $externalActor = $resolver->resolve($externalId, false);

        // Make local follow this external actor (pretend we added ID to local list, though external handle doesn't have ID yet)
        // In reality, we follow by ID. So we need the external actor to have an ID if we are following them.
        // But here we are testing the "Create" flow. If we follow them, they MUST already exist in our DB.
        // So let's save them first, simulating a prior Follow.
        $externalActor->save();
        $this->assertNotNull($externalActor->id());

        $localActor->set('following_actors', [$externalActor->id()]);
        $localActor->save();

        // 3. Process CREATE activity
        // Ensure collections exist
        $this->setupCollections(['activities', 'notes']);
        $payload = [
            'type' => 'Create',
            'actor' => $externalId,
            'id' => $externalId . '/activities/2',
            'object' => [
                'type' => 'Note',
                'id' => $externalId . '/notes/2',
                'content' => 'Hello Friend',
                'attributedTo' => $externalId,
            ]
        ];

        $handler = new InboxHandler();
        $handler->handle($payload, $localActor, $externalActor);

        // 4. Verification
        // Note should be created
        $note = Entry::query()->where('collection', 'notes')->where('activitypub_id', $payload['object']['id'])->first();
        $this->assertNotNull($note, 'Note should be created for followed actor');

        // Activity should be logged
        $savedActivity = Entry::query()->where('collection', 'activities')->where('activitypub_id', $payload['id'])->first();
        $this->assertNotNull($savedActivity, 'Activity should be logged for followed actor');
    }
}
