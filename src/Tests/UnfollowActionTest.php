<?php

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Illuminate\Support\Facades\Http;
use Ethernick\ActivityPubCore\Actions\UnfollowAction;

class UnfollowActionTest extends TestCase
{
    #[Test]
    public function it_is_visible_for_followed_actors()
    {
        $localActor = Entry::make()->collection('actors')->slug('me')->data(['is_internal' => true]);
        $localActor->save();
        $externalActor = Entry::make()->collection('actors')->slug('them')->data(['is_internal' => false]);
        $externalActor->save();

        $localActor->set('following_actors', [$externalActor->id()]);
        $localActor->save();

        $user = User::make()->email('test@test.com')->data(['actors' => [$localActor->id()]]);
        $user->save();
        $this->actingAs($user);

        $action = new UnfollowAction();
        $this->assertTrue($action->visibleTo($externalActor));
    }

    #[Test]
    public function it_is_visible_for_pending_actors()
    {
        $localActor = Entry::make()->collection('actors')->slug('me')->data(['is_internal' => true]);
        $localActor->save();
        $externalActor = Entry::make()->collection('actors')->slug('them')->data([
            'is_internal' => false,
            'activitypub_collections' => ['pending']
        ]);
        $externalActor->save();

        $user = User::make()->email('test@test.com')->data(['actors' => [$localActor->id()]]);
        $user->save();
        $this->actingAs($user);

        $action = new UnfollowAction();
        $this->assertTrue($action->visibleTo($externalActor));
    }

    #[Test]
    public function it_sends_undo_follow_on_run()
    {
        Http::fake();

        $localActor = Entry::make()->collection('actors')->slug('me')->data([
            'is_internal' => true,
            'activitypub_id' => 'https://me.com/actor',
            'private_key' => 'test-key',
            'following_actors' => ['them-id']
        ]);
        $localActor->save();

        $externalActor = Entry::make()->collection('actors')->slug('them')->id('them-id')->data([
            'is_internal' => false,
            'activitypub_id' => 'https://them.com/actor',
            'inbox_url' => 'https://them.com/inbox'
        ]);
        $externalActor->save();

        // Ensure relationship exists
        $localActor->set('following_actors', [$externalActor->id()]);
        $localActor->save();

        $user = User::make()->email('test@test.com')->data(['actors' => [$localActor->id()]]);
        $user->save();
        $this->actingAs($user);

        $action = new UnfollowAction();
        $action->run(collect([$externalActor]), []);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://them.com/inbox' &&
                $request['type'] === 'Undo' &&
                $request['object']['type'] === 'Follow';
        });

        // Verify removed from following
        $localActor = Entry::find($localActor->id());
        $this->assertFalse(in_array($externalActor->id(), $localActor->get('following_actors', [])));
    }
}
