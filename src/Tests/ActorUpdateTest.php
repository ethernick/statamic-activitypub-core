<?php

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Illuminate\Support\Facades\Queue;
use Ethernick\ActivityPubCore\Jobs\FileQueue;
use Illuminate\Support\Facades\Event;
use Statamic\Events\EntrySaved;
use Statamic\Facades\File;
use Statamic\Facades\YAML;

class ActorUpdateTest extends TestCase
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
    public function it_generates_update_activity_when_actor_is_saved()
    {
        // 1. Create a User with an Actor
        $user = User::make()
            ->email('test-actor-update@test.com')
            ->data(['name' => 'Original Name'])
            ->save();

        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor-update')
            ->data([
                'title' => 'Original Name',
                'is_internal' => true,
            ]);
        $actor->save();

        $user->set('actors', [$actor->id()])->save();
        $this->actingAs($user);

        // 2. Clear any activities generated during setup
        Entry::query()
            ->where('collection', 'activities')
            ->where('slug', 'like', 'activity-%')
            ->get()
            ->each->delete();

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
