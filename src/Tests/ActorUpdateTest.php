<?php

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Illuminate\Support\Facades\Queue;
use Ethernick\ActivityPubCore\Jobs\FileQueue;
use Illuminate\Support\Facades\Event;
use Statamic\Events\EntrySaved;

class ActorUpdateTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    #[Test]
    public function it_generates_update_activity_when_actor_is_saved()
    {
        // 1. Create a User with an Actor
        $user = User::make()
            ->email('actorupdate@test.com')
            ->data(['name' => 'Original Name'])
            ->save();

        $actor = Entry::make()
            ->collection('actors')
            ->slug('create-test-actor')
            ->data([
                'title' => 'Original Name',
                'is_internal' => true,
            ]);
        $actor->save();

        $user->set('actors', [$actor->id()])->save();
        $this->actingAs($user);

        // 2. Clear any activities generated during setup
        $activities = Entry::query()->where('collection', 'activities')->get();
        foreach ($activities as $activity) {
            $activity->delete();
        }

        // 3. Update the Actor
        $actor->set('title', 'Updated Name');
        $actor->save();

        // 4. Verification
        // Check if an activity was created
        $activity = Entry::query()
            ->where('collection', 'activities')
            ->orderBy('date', 'desc')
            ->first();

        $this->assertNotNull($activity, 'No activity generated for actor update');
        $this->assertEquals('Update', $activity->get('type'));
        $this->assertEquals('Updated Name updated their profile', $activity->get('content'));

        // Assert that the object of the activity is the actor
        $this->assertEquals([$actor->id()], $activity->get('object'));
    }
}
