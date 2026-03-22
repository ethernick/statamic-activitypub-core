<?php

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Illuminate\Support\Facades\Queue;
use Statamic\Facades\File;
use Statamic\Facades\YAML;

class ActorUpdateMissingIdTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        // Ensure settings exist in sandbox
        File::put(
            \Ethernick\ActivityPubCore\Services\ActivityPubUtils::settingsPath(),
            YAML::dump([
                'actors' => ['enabled' => true, 'federated' => true, 'type' => 'Person'],
                'activities' => ['enabled' => true, 'federated' => true, 'type' => 'Activity'],
            ])
        );

        $this->setupCollections(['actors', 'activities']);

        \Statamic\Facades\Blink::forget('activitypub-settings');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_fails_to_generate_activity_if_actor_cannot_be_resolved()
    {
        // 1. Create an Actor WITHOUT a linked User session
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor-missing-id')
            ->data([
                'title' => 'Lonely Actor',
                'is_internal' => true,
            ]);
        $actor->save();

        // 2. Clear activities
        Entry::query()
            ->where('collection', 'activities')
            ->where('slug', 'like', 'activity-%')
            ->get()
            ->each->delete();

        // 3. Update the Actor (No user logged in)
        $actor->set('title', 'Lonely Actor Updated');
        $actor->save();

        // 4. Assert NO activity created (Current behavior - Bug?)
        // OR Assert Activity IS created (Desired behavior)

        $activity = Entry::query()
            ->where('collection', 'activities')
            ->orderBy('date', 'desc')
            ->first();

        // If my hypothesis is correct, this will be null
        $this->assertNotNull($activity, 'Activity should be generated even if user session is missing');
        $this->assertEquals('Update', $activity->get('type'));
        $this->assertEquals('Lonely Actor Updated updated their profile', $activity->get('content'));
    }
}
