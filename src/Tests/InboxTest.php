<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Statamic\Facades\User;
use Tests\TestCase;

class InboxTest extends TestCase
{
    public function test_inbox_page_loads()
    {
        $user = User::findByEmail('test@example.com');
        if (!$user) {
            $user = User::make()->id('test-id')->email('test@example.com')->makeSuper();
            $user->save();
        }
        $this->actingAs($user);

        $response = $this->get(cp_route('activitypub.inbox.index'));

        $response->assertOk();
        $response->assertViewIs('activitypub::inbox');
    }
}
