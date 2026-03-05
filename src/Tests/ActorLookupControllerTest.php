<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\User;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Entry;
use Statamic\Facades\Collection;

class ActorLookupControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!Collection::find('actors')) {
            Collection::make('actors')->save();
        }
    }

    #[Test]
    public function it_requires_authentication(): void
    {
        $this->get(cp_route('activitypub.actor-lookup.index'))
            ->assertRedirect(cp_route('login'));
    }

    #[Test]
    public function an_authenticated_super_user_can_view_actor_lookup_page(): void
    {
        $user = User::make()->email('admin@example.com')->makeSuper()->save();

        $this->actingAs($user)
            ->get(cp_route('activitypub.actor-lookup.index'))
            ->assertOk()
            ->assertSee('Actor Lookup')
            ->assertSee('activity-pub-actor-lookup');
    }

    #[Test]
    public function it_can_perform_webfinger_lookup(): void
    {
        $user = User::make()->email('admin@example.com')->makeSuper()->save();

        Http::fake([
            'https://mastodon.social/.well-known/webfinger?resource=acct:ethernick@mastodon.social' => Http::response([
                'subject' => 'acct:ethernick@mastodon.social',
                'links' => [
                    [
                        'rel' => 'self',
                        'type' => 'application/activity+json',
                        'href' => 'https://mastodon.social/users/ethernick'
                    ]
                ]
            ])
        ]);

        $this->actingAs($user)
            ->postJson(cp_route('activitypub.actor-lookup.lookup'), [
                'handle' => '@ethernick@mastodon.social',
                'type' => 'webfinger'
            ])
            ->assertOk()
            ->assertJsonPath('subject', 'acct:ethernick@mastodon.social');
    }

    #[Test]
    public function it_can_perform_actor_lookup_via_webfinger(): void
    {
        $user = User::make()->email('admin@example.com')->makeSuper()->save();

        Http::fake([
            'https://mastodon.social/.well-known/webfinger?resource=acct:ethernick@mastodon.social' => Http::response([
                'subject' => 'acct:ethernick@mastodon.social',
                'links' => [
                    [
                        'rel' => 'self',
                        'type' => 'application/activity+json',
                        'href' => 'https://mastodon.social/users/ethernick'
                    ]
                ]
            ]),
            'https://mastodon.social/users/ethernick' => Http::response([
                'id' => 'https://mastodon.social/users/ethernick',
                'type' => 'Person',
                'preferredUsername' => 'ethernick',
                'name' => 'Nick',
                'inbox' => 'https://mastodon.social/users/ethernick/inbox'
            ])
        ]);

        $response = $this->actingAs($user)
            ->postJson(cp_route('activitypub.actor-lookup.lookup'), [
                'handle' => '@ethernick@mastodon.social',
                'type' => 'actor'
            ]);

        $response->assertOk();

        // Assertions based on augmented array structure
        $this->assertEquals('https://mastodon.social/users/ethernick', $response->json('activitypub_id'));
        $this->assertEquals('ethernick', $response->json('username'));
    }

    #[Test]
    public function it_handles_invalid_handle_format(): void
    {
        $user = User::make()->email('admin@example.com')->makeSuper()->save();

        $this->actingAs($user)
            ->postJson(cp_route('activitypub.actor-lookup.lookup'), [
                'handle' => 'invalid-handle',
                'type' => 'actor'
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invalid handle format. Use @user@domain.');
    }
}
