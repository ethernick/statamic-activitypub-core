<?php

namespace Ethernick\ActivityPubCore\Tests;

use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Tests\TestCase;

class InboxApiTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // Ensure notes collection exists
        if (!\Statamic\Facades\Collection::find('notes')) {
            \Statamic\Facades\Collection::make('notes')->save();
        }
    }

    public function test_inbox_api_includes_internal_items()
    {
        $user = User::make()->id('test-id')->email('test-api@example.com')->makeSuper();
        $user->save();
        $this->actingAs($user);

        // Create an internal note
        $note = Entry::make()
            ->collection('notes')
            ->slug('internal-note-test')
            ->data(['title' => 'Internal Note', 'content' => 'This is internal', 'actor' => ['test-actor'], 'date' => now()->format('Y-m-d H:i')])
            ->published(true);
        $note->set('is_internal', true);
        $note->save();

        $response = $this->get(cp_route('activitypub.inbox.api'));

        $response->assertOk();
        $data = $response->json('data');
        $this->assertTrue(collect($data)->contains('id', $note->id()));
        $apiNote = collect($data)->firstWhere('id', $note->id());
        $this->assertTrue($apiNote['is_internal']);
        $this->assertEquals('This is internal', $apiNote['content_raw']);
    }

    public function test_update_internal_note()
    {
        $user = User::make()->id('test-id-2')->email('test-api2@example.com')->makeSuper();
        $user->save();
        $this->actingAs($user);

        $note = Entry::make()
            ->collection('notes')
            ->slug('note-to-update')
            ->data(['content' => 'Old Content', 'is_internal' => true])
            ->published(true);
        $note->save();

        $response = $this->put(cp_route('activitypub.inbox.update-note', ['id' => $note->id()]), [
            'id' => $note->id(),
            'content' => 'New Content',
            'content_warning' => 'Updated Warning'
        ]);

        $response->assertOk();
        $this->assertEquals('New Content', $note->fresh()->get('content'));
        $this->assertEquals('Updated Warning', $note->fresh()->get('summary'));
    }

    public function test_cannot_update_external_note()
    {
        $user = User::make()->id('test-id-3')->email('test-api3@example.com')->makeSuper();
        $user->save();
        $this->actingAs($user);

        $note = Entry::make()
            ->collection('notes')
            ->slug('external-note')
            ->data(['content' => 'External', 'is_internal' => false]) // explicitly false
            ->published(true);
        $note->save();

        $response = $this->put(cp_route('activitypub.inbox.update-note', ['id' => $note->id()]), [
            'id' => $note->id(),
            'content' => 'Updated content',
        ]);

        $response->assertStatus(403);
        $this->assertEquals('External', $note->fresh()->get('content'));
    }

    public function test_inbox_api_search_by_keyword()
    {
        $user = User::make()->id('test-id-search-kw')->email('test-kw@example.com')->makeSuper();
        $user->save();
        $this->actingAs($user);

        $note1 = Entry::make()
            ->collection('notes')
            ->slug('note-kw-1')
            ->data(['content' => 'Vancouver Meetup talking about Statamic', 'date' => now()->format('Y-m-d H:i')])
            ->published(true);
        $note1->save();

        $note2 = Entry::make()
            ->collection('notes')
            ->slug('note-kw-2')
            ->data(['content' => 'Watering the plants today in garden', 'date' => now()->subHour()->format('Y-m-d H:i')])
            ->published(true);
        $note2->save();

        $response = $this->get(cp_route('activitypub.inbox.api', ['search' => 'Vancouver']));
        $response->assertOk();
        $data = $response->json('data');
        $this->assertTrue(collect($data)->contains('id', $note1->id()));
        $this->assertFalse(collect($data)->contains('id', $note2->id()));

        // Also test query via 'q'
        $responseQ = $this->get(cp_route('activitypub.inbox.api', ['q' => 'plants']));
        $responseQ->assertOk();
        $dataQ = $responseQ->json('data');
        $this->assertFalse(collect($dataQ)->contains('id', $note1->id()));
        $this->assertTrue(collect($dataQ)->contains('id', $note2->id()));
    }

    public function test_inbox_api_search_by_author()
    {
        if (!\Statamic\Facades\Collection::find('actors')) {
            \Statamic\Facades\Collection::make('actors')->save();
        }

        $user = User::make()->id('test-id-search-author')->email('test-author@example.com')->makeSuper();
        $user->save();
        $this->actingAs($user);

        $actor1 = Entry::make()
            ->collection('actors')
            ->slug('alice-at-example-dot-com')
            ->data(['title' => 'Alice Wonder', 'handle' => '@alice@example.com'])
            ->published(true);
        $actor1->save();

        $actor2 = Entry::make()
            ->collection('actors')
            ->slug('bob-at-example-dot-com')
            ->data(['title' => 'Bob Builder', 'handle' => '@bob@example.com'])
            ->published(true);
        $actor2->save();

        $note1 = Entry::make()
            ->collection('notes')
            ->slug('note-author-1')
            ->data(['content' => 'From Alice', 'actor' => [$actor1->id()], 'date' => now()->format('Y-m-d H:i')])
            ->published(true);
        $note1->save();

        $note2 = Entry::make()
            ->collection('notes')
            ->slug('note-author-2')
            ->data(['content' => 'From Bob', 'actor' => [$actor2->id()], 'date' => now()->subHour()->format('Y-m-d H:i')])
            ->published(true);
        $note2->save();

        $response = $this->get(cp_route('activitypub.inbox.api', ['search' => '@alice']));
        $response->assertOk();
        $data = $response->json('data');
        $this->assertTrue(collect($data)->contains('id', $note1->id()));
        $this->assertFalse(collect($data)->contains('id', $note2->id()));
    }

    public function test_inbox_api_search_by_hashtag()
    {
        $user = User::make()->id('test-id-search-tag')->email('test-tag@example.com')->makeSuper();
        $user->save();
        $this->actingAs($user);

        $note1 = Entry::make()
            ->collection('notes')
            ->slug('note-tag-1')
            ->data(['content' => 'Exciting news #fediverse today', 'tags' => ['fediverse'], 'date' => now()->format('Y-m-d H:i')])
            ->published(true);
        $note1->save();

        $note2 = Entry::make()
            ->collection('notes')
            ->slug('note-tag-2')
            ->data(['content' => 'Regular post #laravel', 'tags' => ['laravel'], 'date' => now()->subHour()->format('Y-m-d H:i')])
            ->published(true);
        $note2->save();

        $response = $this->get(cp_route('activitypub.inbox.api', ['search' => '#fediverse']));
        $response->assertOk();
        $data = $response->json('data');
        $this->assertTrue(collect($data)->contains('id', $note1->id()));
        $this->assertFalse(collect($data)->contains('id', $note2->id()));
    }

    public function test_inbox_api_filter_mentions()
    {
        if (!\Statamic\Facades\Collection::find('actors')) {
            \Statamic\Facades\Collection::make('actors')->save();
        }

        $userActor = Entry::make()
            ->collection('actors')
            ->slug('my-actor')
            ->data(['title' => 'My Actor', 'activitypub_id' => 'https://example.com/actors/my-actor', 'is_internal' => true])
            ->published(true);
        $userActor->save();

        $user = User::make()
            ->id('test-id-mentions-user')
            ->email('test-mentions@example.com')
            ->data(['actors' => [$userActor->id()]])
            ->makeSuper();
        $user->save();
        $this->actingAs($user);

        // Note 1 mentions user actor
        $note1 = Entry::make()
            ->collection('notes')
            ->slug('note-mention-1')
            ->data([
                'content' => 'Hello @my-actor what do you think?',
                'date' => now()->format('Y-m-d H:i'),
            ])
            ->published(true);
        $note1->save();

        // Note 2 does not mention user actor
        $note2 = Entry::make()
            ->collection('notes')
            ->slug('note-mention-2')
            ->data([
                'content' => 'Just talking to the void',
                'date' => now()->subHour()->format('Y-m-d H:i'),
            ])
            ->published(true);
        $note2->save();

        $response = $this->get(cp_route('activitypub.inbox.api', ['filter' => 'mentions']));
        $response->assertOk();
        $data = $response->json('data');

        $this->assertTrue(collect($data)->contains('id', $note1->id()));
        $this->assertFalse(collect($data)->contains('id', $note2->id()));
    }

    public function test_inbox_api_search_token_indexing()
    {
        $user = User::make()->id('test-id-search-tokens')->email('test-tokens@example.com')->makeSuper();
        $user->save();
        $this->actingAs($user);

        $note = Entry::make()
            ->collection('notes')
            ->slug('note-tokens-1')
            ->data([
                'title' => 'Product Announcement',
                'content' => 'Excited to release the new federation features today!',
                'summary' => 'Federation updates',
                'date' => now()->format('Y-m-d H:i'),
            ])
            ->published(true);
        $note->save();

        // Verify search_tokens were automatically extracted on save
        $fresh = $note->fresh();
        $tokens = $fresh->get('search_tokens');
        $this->assertIsArray($tokens);
        $this->assertContains('federation', $tokens);
        $this->assertContains('features', $tokens);
        $this->assertContains('updates', $tokens);

        // Verify querying by token works
        $response = $this->get(cp_route('activitypub.inbox.api', ['search' => 'federation']));
        $response->assertOk();
        $data = $response->json('data');
        $this->assertTrue(collect($data)->contains('id', $note->id()));
    }

    public function test_inbox_api_search_empty_symbols_returns_empty()
    {
        $user = User::make()->id('test-id-search-empty-sym')->email('test-empty-sym@example.com')->makeSuper();
        $user->save();
        $this->actingAs($user);

        // Lone '#' must not return all entries
        $responseHash = $this->get(cp_route('activitypub.inbox.api', ['search' => '#']));
        $responseHash->assertOk();
        $this->assertCount(0, $responseHash->json('data'));

        // Lone '@' must not return all entries
        $responseAt = $this->get(cp_route('activitypub.inbox.api', ['search' => '@']));
        $responseAt->assertOk();
        $this->assertCount(0, $responseAt->json('data'));
    }

    public function test_inbox_api_search_by_actor_handle()
    {
        if (!\Statamic\Facades\Collection::find('actors')) {
            \Statamic\Facades\Collection::make('actors')->save();
        }

        $actor = Entry::make()
            ->collection('actors')
            ->slug('alice')
            ->data(['title' => 'Alice Allison', 'is_internal' => true])
            ->published(true);
        $actor->save();

        $user = User::make()
            ->id('test-id-search-user-alice')
            ->email('alice@example.com')
            ->makeSuper();
        $user->save();
        $this->actingAs($user);

        $note = Entry::make()
            ->collection('notes')
            ->slug('alice-note-1')
            ->data([
                'title' => 'Alice Post',
                'content' => 'First message here',
                'actor' => [$actor->id()],
                'date' => now()->format('Y-m-d H:i'),
            ])
            ->published(true);
        $note->save();

        \Ethernick\ActivityPubCore\Search\ActorSearchIndex::clear();

        // 1. Search with short handle @alice
        $resAt = $this->get(cp_route('activitypub.inbox.api', ['search' => '@alice']));
        $resAt->assertOk();
        $this->assertTrue(collect($resAt->json('data'))->contains('id', $note->id()));

        // 2. Search plain handle "alice"
        $resPlain = $this->get(cp_route('activitypub.inbox.api', ['search' => 'alice']));
        $resPlain->assertOk();
        $this->assertTrue(collect($resPlain->json('data'))->contains('id', $note->id()));

        // 3. Search with full federated handle @alice@localhost (or whatever current test host is)
        $host = request()->getHost();
        $resFull = $this->get(cp_route('activitypub.inbox.api', ['search' => "@alice@{$host}"]));
        $resFull->assertOk();
        $this->assertTrue(collect($resFull->json('data'))->contains('id', $note->id()));
    }

    public function test_inbox_api_search_by_external_actor_handle()
    {
        if (!\Statamic\Facades\Collection::find('actors')) {
            \Statamic\Facades\Collection::make('actors')->save();
        }

        // External actor (e.g., evan@cosocial.ca)
        $externalActor = Entry::make()
            ->collection('actors')
            ->slug('evan-at-cosocial-dot-ca')
            ->data([
                'title' => 'Evan Prodromou',
                'username' => 'evan',
                'activitypub_id' => 'https://cosocial.ca/users/evan',
                'is_internal' => false,
            ])
            ->published(true);
        $externalActor->save();

        $user = User::make()
            ->id('test-id-search-user-evan')
            ->email('admin-evan-test@example.com')
            ->makeSuper();
        $user->save();
        $this->actingAs($user);

        $externalNote = Entry::make()
            ->collection('notes')
            ->slug('evan-note-1')
            ->data([
                'title' => 'Federation note from Evan',
                'content' => 'Hello fediverse from Evan',
                'actor' => $externalActor->id(), // stored as string ID
                'date' => now()->format('Y-m-d H:i'),
            ])
            ->published(true);
        $externalNote->save();

        \Ethernick\ActivityPubCore\Search\ActorSearchIndex::clear();

        // 1. Search full fediverse handle with leading @
        $resFull = $this->get(cp_route('activitypub.inbox.api', ['search' => '@evan@cosocial.ca']));
        $resFull->assertOk();
        $this->assertTrue(collect($resFull->json('data'))->contains('id', $externalNote->id()));

        // 2. Search without leading @
        $resNoAt = $this->get(cp_route('activitypub.inbox.api', ['search' => 'evan@cosocial.ca']));
        $resNoAt->assertOk();
        $this->assertTrue(collect($resNoAt->json('data'))->contains('id', $externalNote->id()));

        // 3. Search short username @evan
        $resShort = $this->get(cp_route('activitypub.inbox.api', ['search' => '@evan']));
        $resShort->assertOk();
        $this->assertTrue(collect($resShort->json('data'))->contains('id', $externalNote->id()));
    }
}

