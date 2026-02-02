<?php

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Illuminate\Support\Facades\Queue;

class ActorUpdateMissingIdTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    #[Test]
    public function it_fails_to_generate_activity_if_actor_cannot_be_resolved()
    {
        // 1. Create an Actor WITHOUT a linked User session
        $actor = Entry::make()
            ->collection('actors')
            ->slug('lonely-actor')
            ->data([
                'title' => 'Lonely Actor',
                'is_internal' => true,
            ]);
        $actor->save();

        // 2. Clear activities
        $activities = Entry::query()->where('collection', 'activities')->get();
        foreach ($activities as $activity) {
            $activity->delete();
        }

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
