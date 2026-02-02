<?php

namespace Ethernick\ActivityPubCore\Tests;

use Ethernick\ActivityPubCore\Services\ActorResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Statamic\Facades\Entry;

class SuspendedActorTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Entry::query()->where('collection', 'actors')->get()->each->delete();
    }

    #[Test]
    public function it_returns_null_for_suspended_actor_flag()
    {
        $actorUrl = 'https://example.com/users/suspended_user';

        Http::fake([
            $actorUrl => Http::response([
                'id' => $actorUrl,
                'type' => 'Person',
                'name' => 'Bad Actor',
                'suspended' => true,
            ])
        ]);

        $resolver = new ActorResolver();
        $actor = $resolver->resolve($actorUrl, false);

        $this->assertNull($actor, 'Resolver should return null for actor with suspended: true');
    }

    #[Test]
    public function it_returns_null_for_toot_suspended_actor_flag()
    {
        $actorUrl = 'https://example.com/users/toot_suspended_user';

        Http::fake([
            $actorUrl => Http::response([
                'id' => $actorUrl,
                'type' => 'Person',
                'name' => 'Bad Actor 2',
                'toot:suspended' => true,
            ])
        ]);

        $resolver = new ActorResolver();
        $actor = $resolver->resolve($actorUrl, false);

        $this->assertNull($actor, 'Resolver should return null for actor with toot:suspended: true');
    }

    #[Test]
    public function it_returns_actor_if_not_suspended()
    {
        $actorUrl = 'https://example.com/users/good_user';

        Http::fake([
            $actorUrl => Http::response([
                'id' => $actorUrl,
                'type' => 'Person',
                'name' => 'Good Actor',
            ])
        ]);

        $resolver = new ActorResolver();
        $actor = $resolver->resolve($actorUrl, false);

        $this->assertNotNull($actor);
        $this->assertEquals('Good Actor', $actor->get('title'));
    }
}
