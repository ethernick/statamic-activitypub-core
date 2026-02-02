<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Illuminate\Support\Facades\Http;
use Ethernick\ActivityPubCore\Actions\FollowAction;
use Illuminate\Foundation\Testing\RefreshDatabase;

class FollowActionTest extends TestCase
{
    // use RefreshDatabase; // Statamic usually handles this differently or needs explicit setup. 
    // Assuming configured test environment.

    public function setUp(): void
    {
        parent::setUp();
        // Setup user and actors if needed
    }

    #[Test]
    public function it_is_visible_for_external_unfollowed_actors()
    {
        // 1. Create Local User Actor
        $localActor = Entry::make()->collection('actors')->slug('me')->data(['is_internal' => true]);
        $localActor->save();
        $user = User::make()->email('test@test.com')->data(['actors' => [$localActor->id()]]);
        $user->save();
        $this->actingAs($user);

        // 2. Create External Actor
        $externalActor = Entry::make()->collection('actors')->slug('them')->data(['is_internal' => false]);
        $externalActor->save();

        // 3. Check Visibility
        $action = new FollowAction();
        $this->assertTrue($action->visibleTo($externalActor));
    }

    #[Test]
    public function it_is_not_visible_for_internal_actors()
    {
        $localActor = Entry::make()->collection('actors')->slug('me')->data(['is_internal' => true]);
        $localActor->save();
        $action = new FollowAction();
        $this->assertFalse($action->visibleTo($localActor));
    }

    #[Test]
    public function it_is_not_visible_if_already_following()
    {
        // 1. Create Actors
        $localActor = Entry::make()->collection('actors')->slug('me')->data(['is_internal' => true]);
        $localActor->save();

        $externalActor = Entry::make()->collection('actors')->slug('them')->data(['is_internal' => false]);
        $externalActor->save();

        // 2. Setup Following
        $localActor->set('following_actors', [$externalActor->id()]);
        $localActor->save();

        $user = User::make()->email('test@test.com')->data(['actors' => [$localActor->id()]]);
        $user->save();
        $this->actingAs($user);

        // 3. Check Visibility
        $action = new FollowAction();
        $this->assertFalse($action->visibleTo($externalActor));
    }

    #[Test]
    public function it_sends_follow_activity_on_run()
    {
        Http::fake();

        // 1. Create Actors
        $localActor = Entry::make()->collection('actors')->slug('me')->data([
            'is_internal' => true,
            'activitypub_id' => 'https://me.com/actor',
            'private_key' => 'test-key'
        ]);
        $localActor->save();

        $externalActor = Entry::make()->collection('actors')->slug('them')->data([
            'is_internal' => false,
            'activitypub_id' => 'https://them.com/actor',
            'inbox_url' => 'https://them.com/inbox'
        ]);
        $externalActor->save();

        $user = User::make()->email('test@test.com')->data(['actors' => [$localActor->id()]]);
        $user->save();
        $this->actingAs($user);

        // 2. Run Action
        $action = new FollowAction();
        $action->run(collect([$externalActor]), []);

        // 3. Verify Http Request
        Http::assertSent(function ($request) {
            return $request->url() === 'https://them.com/inbox' &&
                $request['type'] === 'Follow';
        });

        // 4. Verify Pending Status
        $externalActor = Entry::find($externalActor->id()); // Reload
        $this->assertTrue(in_array('pending', $externalActor->get('activitypub_collections', [])));
    }
}
