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
        $this->artisan('migrate');
        Entry::query()->where('collection', 'actors')->get()->each->delete();
    }


    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_null_for_suspended_actor_flag_and_blocks_them()
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
        $this->assertTrue(\Ethernick\ActivityPubCore\Services\BlockList::isBlocked($actorUrl), 'Suspended actor should be automatically blocked');
        $this->assertDatabaseHas('activity_pub_auto_blocks', ['identifier' => $actorUrl]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_null_for_toot_suspended_actor_flag_and_blocks_them()
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
        $this->assertTrue(\Ethernick\ActivityPubCore\Services\BlockList::isBlocked($actorUrl), 'Suspended actor should be automatically blocked');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_blocks_and_logs_410_gone_actors()
    {
        $actorUrl = 'https://example.com/users/deleted_user';

        Http::fake([
            $actorUrl => Http::response([], 410)
        ]);

        $resolver = new ActorResolver();
        $actor = $resolver->resolve($actorUrl, false);

        $this->assertNull($actor, 'Resolver should return null for 410 Gone');
        $this->assertTrue(\Ethernick\ActivityPubCore\Services\BlockList::isBlocked($actorUrl), 'Deleted (410) actor should be automatically blocked');
        $this->assertDatabaseHas('activity_pub_auto_blocks', [
            'identifier' => $actorUrl,
            'reason' => 'HTTP 410 Gone'
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
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
