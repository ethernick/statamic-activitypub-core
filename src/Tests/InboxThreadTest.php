<?php

namespace Ethernick\ActivityPubCore\Tests;

use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Tests\TestCase;

class InboxThreadTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!\Statamic\Facades\Collection::find('notes')) {
            \Statamic\Facades\Collection::make('notes')->save();
        }
    }

    public function test_api_returns_parent_context_for_replies()
    {
        $user = User::make()->id('test-user')->email('test-thread@example.com')->makeSuper();
        $user->save();
        $this->actingAs($user);

        // 1. Create Parent Note
        $parent = Entry::make()
            ->collection('notes')
            ->slug('parent-note')
            ->data([
                'content' => 'I am the parent',
                'actor' => ['test-actor-id'],
                'date' => now()->subHour()->format('Y-m-d H:i')
            ]);
        $parent->save();

        // 2. Create Reply Note
        $reply = Entry::make()
            ->collection('notes')
            ->slug('reply-note')
            ->data([
                'content' => 'I am the reply',
                'in_reply_to' => $parent->id(),
                'date' => now()->format('Y-m-d H:i')
            ]);
        $reply->save();

        // 3. Call API
        $response = $this->get(cp_route('activitypub.inbox.api'));

        // 4. Assert
        $response->assertOk();
        $data = $response->json('data');

        // Find the reply
        $replyItem = collect($data)->firstWhere('id', $reply->id());
        $this->assertNotNull($replyItem, 'Reply note not found in API response');

        // Check parent
        $this->assertArrayHasKey('parent', $replyItem);
        $this->assertEquals($parent->id(), $replyItem['parent']['id']);
        $this->assertStringContainsString('I am the parent', $replyItem['parent']['content']);
    }

    public function test_thread_endpoint_returns_ancestors_and_descendants()
    {
        $user = User::make()->id('test-user-2')->email('test-thread-2@example.com')->makeSuper();
        $user->save();
        $this->actingAs($user);

        // Hierarchy: A1 -> A2 -> Focus -> C1, C2

        $a1 = $this->createNote('Ancestor 1', null, now()->subHours(4));
        $a2 = $this->createNote('Ancestor 2', $a1->id(), now()->subHours(3));
        $focus = $this->createNote('Focus Note', $a2->id(), now()->subHours(2));
        $c1 = $this->createNote('Child 1', $focus->id(), now()->subHour());
        $c2 = $this->createNote('Child 2', $focus->id(), now()->subMinutes(30));

        // Call Thread Endpoint on Focus
        // Route is activitypub.thread defined in cp.php
        $response = $this->get(route('statamic.cp.activitypub.thread', ['id' => $focus->id()]));

        $response->assertOk();
        $data = $response->json('data');

        // Expect 5 items
        $this->assertCount(5, $data);

        $ids = collect($data)->pluck('id')->all();
        $expectedIds = [$a1->id(), $a2->id(), $focus->id(), $c1->id(), $c2->id()];

        $this->assertEquals($expectedIds, $ids);
    }

    private function createNote($content, $replyToId = null, $date = null)
    {
        $entry = Entry::make()
            ->collection('notes')
            ->slug(\Illuminate\Support\Str::slug($content))
            ->data([
                'content' => $content,
                'in_reply_to' => $replyToId,
                'date' => $date ? $date->format('Y-m-d H:i') : now()->format('Y-m-d H:i')
            ]);
        $entry->save();
        return $entry;
    }
}
