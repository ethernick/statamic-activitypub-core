<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Http;
use Statamic\Facades\Entry;
use Tests\TestCase;

class ActivityPubTest extends TestCase
{
    // We don't use RefreshDatabase because Statamic might use Stache (flat files) or SQLite.
    // Ideally we should adhere to the project's testing strategy.

    protected $originalQueue; // Backup Queue

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalQueue = \Illuminate\Support\Facades\Queue::getFacadeRoot();
        config(['statamic.editions.pro' => true]);
        \Statamic\Facades\Blink::flush();
        \Statamic\Facades\Stache::clear();

        // Cleanup Inbox Queue
        \Illuminate\Support\Facades\File::cleanDirectory(storage_path('app/activitypub/inbox'));

        // Cleanup Notes, Polls, Activities - but preserve real user data
        // Only delete entries that look like test data (have test- prefix, external.com, etc.)
        \Statamic\Facades\Entry::query()->whereIn('collection', ['notes', 'polls', 'activities'])->get()
            ->filter(function ($entry) {
                // Delete if it looks like test data
                $slug = $entry->slug() ?? '';
                $apId = $entry->get('activitypub_id') ?? '';
                return str_contains($slug, 'test-')
                    || str_contains($apId, 'external.com')
                    || str_contains($apId, 'test/')
                    || str_contains($slug, '-at-external-dot-com');
            })
            ->each->delete();

        // For actors, be very selective - only delete known test actors
        \Statamic\Facades\Entry::query()->where('collection', 'actors')->get()
            ->filter(function ($entry) {
                $slug = $entry->slug() ?? '';
                $apId = $entry->get('activitypub_id') ?? '';
                // Only delete actors that are clearly test data
                return str_contains($slug, 'test-')
                    || str_contains($apId, 'external.com')
                    || $slug === 'fan-at-external-dot-com'
                    || $slug === 'poster-at-external-dot-com'
                    || $slug === 'updater-at-external-dot-com'
                    || $slug === 'liker'
                    || $slug === 'quoter'
                    || $slug === 'replier'
                    || $slug === 'sender'
                    || $slug === 'recipient'
                    || $slug === 'mentioner';
            })
            ->each->delete();

        \Illuminate\Support\Facades\Queue::swap(\Illuminate\Support\Facades\Queue::getFacadeRoot()); // Reset Queue fake if any?

        // Create Collections
        if (!\Statamic\Facades\Collection::find('notes')) {
            \Statamic\Facades\Collection::make('notes')->save();
        }
        if (!\Statamic\Facades\Collection::find('activities')) {
            \Statamic\Facades\Collection::make('activities')->save();
        }
        if (!\Statamic\Facades\Collection::find('actors')) {
            \Statamic\Facades\Collection::make('actors')->save();
        }
        if (!\Statamic\Facades\Collection::find('polls')) {
            \Statamic\Facades\Collection::make('polls')->save();
        }

        if (!Entry::query()->where('collection', 'actors')->where('slug', 'ethernick')->first()) {
            Entry::make()->collection('actors')->slug('ethernick')->data(['title' => 'Nick', 'is_internal' => true])->published(true)->save();
        }

        // Clean queues
        \Illuminate\Support\Facades\Storage::disk('local')->deleteDirectory('activitypub/inbox');
        \Illuminate\Support\Facades\Storage::disk('local')->makeDirectory('activitypub/inbox');

        // Create activitypub.yaml config with federated: true for notes/polls
        if (!file_exists(resource_path('settings'))) {
            mkdir(resource_path('settings'), 0755, true);
        }
        file_put_contents(
            resource_path('settings/activitypub.yaml'),
            "notes:\n  enabled: true\n  type: Note\n  federated: true\npolls:\n  enabled: true\n  type: Question\n  federated: true\narticles:\n  enabled: true\n  type: Article\n  federated: true\nactivities:\n  enabled: true\n  type: Activity\n"
        );
    }

    protected function tearDown(): void
    {
        // Cleanup entries created during tests
        $collections = ['notes', 'actors', 'activities', 'polls'];
        foreach ($collections as $col) {
            $entries = Entry::query()->where('collection', $col)->get();
            foreach ($entries as $entry) {
                $apId = $entry->get('activitypub_id');
                if (
                    str_contains($entry->slug(), 'test-') ||
                    ($apId && str_contains($apId, 'external.com')) ||
                    $entry->slug() === 'fan-at-external-dot-com' ||
                    $entry->slug() === 'poster-at-external-dot-com' ||
                    $entry->slug() === 'updater-at-external-dot-com' ||
                    $entry->slug() === 'liker'
                ) {
                    $entry->delete();
                }
            }
        }

        if ($this->originalQueue) {
            \Illuminate\Support\Facades\Queue::swap($this->originalQueue);
        }

        parent::tearDown();
    }

    public function test_incoming_announce_boost_creates_entries()
    {
        Http::fake([
            'https://external.com/notes/original' => Http::response([
                'id' => 'https://external.com/notes/original',
                'type' => 'Note',
                'content' => 'Original Content',
                'attributedTo' => 'https://external.com/users/author',
                'published' => now()->toIso8601String(),
            ], 200),
            'https://external.com/users/author' => Http::response([
                'id' => 'https://external.com/users/author',
                'type' => 'Person',
                'preferredUsername' => 'author',
                'inbox' => 'https://external.com/users/author/inbox',
            ], 200),
            'https://external.com/users/booster' => Http::response([
                'id' => 'https://external.com/users/booster',
                'type' => 'Person',
                'preferredUsername' => 'booster',
                'inbox' => 'https://external.com/users/booster/inbox',
            ], 200),
        ]);

        $announceId = 'https://external.com/activities/announce-1';
        $payload = [
            'id' => $announceId,
            'type' => 'Announce',
            'actor' => 'https://external.com/users/booster',
            'object' => 'https://external.com/notes/original',
        ];

        // Send to Inbox. Correct route is /@{handle}/inbox
        $response = $this->postJson('/@ethernick/inbox', $payload);

        $response->assertStatus(202);

        // Process Queue
        \Illuminate\Support\Facades\Artisan::call('activitypub:process-inbox');

        \Statamic\Facades\Blink::flush();
        \Statamic\Facades\Stache::clear();

        // Debug specific entry if needed
        $entries = \Statamic\Facades\Entry::query()->where('collection', 'notes')->get();
        // dump("Total Notes: " . $entries->count());

        // Assert Original Note Created
        $original = Entry::query()->where('activitypub_id', 'https://external.com/notes/original')->first();
        $this->assertNotNull($original, 'Original note should be created');
        $this->assertEquals('Original Content', $original->get('content'));

        // Assert Boost Entry Created (In Activities Collection)
        $boost = Entry::query()->where('collection', 'activities')->where('activitypub_id', $announceId)->first();
        $this->assertNotNull($boost, 'Boost activity should be created');
        $this->assertEquals('Announce', $boost->get('type'));
        $this->assertEquals($original->get('activitypub_id'), $boost->get('object'));

        // Assert Original Note has boosted_by (Expect UUID)
        $original = Entry::find($original->id()); // Refresh
        $booster = Entry::query()->where('activitypub_id', 'https://external.com/users/booster')->first();
        $this->assertNotNull($booster);
        $this->assertContains($booster->id(), $original->get('boosted_by', []));
    }

    public function test_incoming_quote_creates_entries()
    {
        Http::fake([
            'https://external.com/notes/quoted' => Http::response([
                'id' => 'https://external.com/notes/quoted',
                'type' => 'Note',
                'content' => 'Quoted Content',
                'attributedTo' => 'https://external.com/users/quoted_author',
                'published' => now()->toIso8601String(),
            ], 200),
            'https://external.com/users/quoted_author' => Http::response([
                'id' => 'https://external.com/users/quoted_author',
                'type' => 'Person',
                'preferredUsername' => 'quoted',
            ], 200),
            'https://external.com/users/quoter' => Http::response([
                'id' => 'https://external.com/users/quoter',
                'type' => 'Person',
                'preferredUsername' => 'quoter',
            ], 200),
        ]);

        // Create Quoter Actor first to get UUID
        $quoter = Entry::make()->collection('actors')->slug('quoter')->data([
            'activitypub_id' => 'https://external.com/users/quoter',
            'title' => 'Quoter'
        ]);
        $quoter->save();

        // Make Ethernick follow Quoter to bypass spam filter
        $ethernick = Entry::query()->where('collection', 'actors')->where('slug', 'ethernick')->first();
        if ($ethernick) {
            $ethernick->set('following_actors', [$quoter->id()]);
            $ethernick->save();
        }

        \Statamic\Facades\Blink::flush();

        $noteId = 'https://external.com/notes/quoter-note';
        $payload = [
            'id' => $noteId,
            'type' => 'Create',
            'actor' => 'https://external.com/users/quoter',
            'object' => [
                'id' => $noteId,
                'type' => 'Note',
                'content' => 'Check this out!',
                'attributedTo' => 'https://external.com/users/quoter',
                'quoteUrl' => 'https://external.com/notes/quoted',
            ]
        ];

        $response = $this->postJson('/@ethernick/inbox', $payload);
        $response->assertStatus(202);

        // Process Queue
        \Illuminate\Support\Facades\Artisan::call('activitypub:process-inbox');

        \Statamic\Facades\Blink::flush();
        \Statamic\Facades\Stache::clear();

        // Assert Quoted Note Created
        $quoted = Entry::query()->where('activitypub_id', 'https://external.com/notes/quoted')->first();
        $this->assertNotNull($quoted, 'Quoted note should be created');

        // Assert Main Note Created
        $main = Entry::query()->where('collection', 'notes')->where('activitypub_id', $noteId)->first();
        $this->assertNotNull($main, 'Main note should be created');


        // Fix Assertion: quote_of is array of IDs, not object yet unless augmented
        $quoteOf = $main->get('quote_of');
        if (is_array($quoteOf)) {
            $this->assertContains($quoted->id(), $quoteOf);
        } else {
            // If augmented, it might be object, but usually get() is raw
            $this->assertEquals($quoted->id(), $quoteOf);
        }
    }

    public function test_inbox_api_returns_correct_structure()
    {
        // Create User and Authenticate
        $actor = Entry::query()->where('collection', 'actors')->where('slug', 'ethernick')->first();
        $user = \Statamic\Facades\User::make()
            ->email('api-test@example.com')
            ->data(['name' => 'API Tester', 'actors' => [$actor->id()]])
            ->save();
        $this->actingAs($user);

        // 1. Create Mock Entries
        // Quoted Note
        $quoted = Entry::make()->collection('notes')->slug('test-quoted')->published(true)->data([
            'content' => 'I am quoted',
            'activitypub_id' => 'https://test/quoted',
            'actor' => ['actors/ethernick'],
            'is_internal' => false,
            'date' => now()->subMinutes(4)->toDateTimeString(),
        ]);
        $quoted->save();

        // Main Note quoting above
        $quoter = Entry::make()->collection('notes')->slug('test-quoter')->published(true)->data([
            'content' => 'I am quoting',
            'activitypub_id' => 'https://test/quoter',
            'quote_of' => [$quoted->id()],
            'is_internal' => false,
            'actor' => ['actors/ethernick'],
            'date' => now()->subMinutes(3)->toDateTimeString(),
        ]);
        $quoter->save();

        // Boost Entry - using modern boost system with boosted_by array
        $boosterActor = Entry::make()->collection('actors')->slug('test-booster-actor')->published(true)->data([
            'title' => 'Booster Actor',
            'activitypub_id' => 'https://test/booster-actor',
            'is_internal' => false,
        ]);
        $boosterActor->save();

        $boosted = Entry::make()->collection('notes')->slug('test-boosted')->published(true)->data([
            'content' => 'I am boosted',
            'activitypub_id' => 'https://test/boosted',
            'actor' => ['actors/ethernick'],
            'is_internal' => false,
            'boosted_by' => [$boosterActor->id()], // Modern boost system
            'boost_count' => 1,
            'date' => now()->subMinutes(2)->toDateTimeString(),
        ]);
        $boosted->save();

        // 2. Call API
        config(['statamic.editions.pro' => true]);

        $user = \Statamic\Facades\User::all()->first();
        if (!$user) {
            $user = \Statamic\Facades\User::make()->email('test@test.com')->makeSuper()->save();
        }
        $response = $this->withoutMiddleware()->actingAs($user)->getJson(cp_route('activitypub.inbox.api') . '?per_page=100');

        $response->assertStatus(200);
        $response->assertStatus(200);
        $json = $response->json();

        // The variable $noteId is not defined in this test.
        // To make the code syntactically correct and runnable, this line is commented out.
        // If $noteId was intended to be one of the created entry IDs, it should be defined.
        // $this->assertTrue(in_array($noteId, $ids));

        $data = $response->json('data');
        // var_dump($data); 
        if (collect($data)->isEmpty()) {
            dump('API Data Empty. Entries count: ' . \Statamic\Facades\Entry::query()->count());
            dump(\Statamic\Facades\Entry::all()->map(fn($e) => $e->id() . ' ' . $e->collection()->handle() . ' int:' . $e->get('is_internal'))->all());
        }

        // Find our entries
        // Note: query orders by date desc. paginate 20. should be there.
        // InboxController swaps boost with original note. So we look for original note ID (boosted->id()) 
        // but check if it has boosted_by info
        $boostItem = collect($data)->firstWhere('id', $boosted->id());
        $quoteItem = collect($data)->firstWhere('id', $quoter->id());

        // Assert Boost Data
        if (!$boostItem) {
            dump('Boost Item Missing. Looking for: ' . $boosted->id());
            dump('Data Names/IDs:', collect($data)->map(fn($i) => $i['id'] . ' ' . $i['type'])->all());
        }
        $this->assertNotNull($boostItem, 'Boost item not found in API response. Count: ' . count($data));
        $this->assertTrue($boostItem['is_boost'] ?? false);
        // Content should be from original
        $this->assertStringContainsString('I am boosted', $boostItem['content']);

        // Assert Quote Data
        $this->assertNotNull($quoteItem, 'Quote item not found in API response');
        $this->assertNotNull($quoteItem['quote'] ?? null, 'Quote object missing');
        $this->assertEquals('https://test/quoted', $quoteItem['quote']['url'] ?? '');
        $this->assertStringContainsString('I am quoted', $quoteItem['quote']['content'] ?? '');
    }

    public function test_incoming_follow_adds_follower()
    {
        Http::fake([
            'https://external.com/users/fan' => Http::response([
                'id' => 'https://external.com/users/fan',
                'type' => 'Person',
                'preferredUsername' => 'fan',
                'inbox' => 'https://external.com/users/fan/inbox',
            ], 200),
        ]);

        $payload = [
            'id' => 'https://external.com/activities/follow-1',
            'type' => 'Follow',
            'actor' => 'https://external.com/users/fan',
            'object' => 'https://yourdomain.com/users/ethernick', // Local actor ID roughly
        ];

        $response = $this->postJson('/@ethernick/inbox', $payload);
        $response->assertStatus(202); // 202 Accepted - queued for processing

        // Manually process the queued inbox job
        $localActor = Entry::query()->where('collection', 'actors')->where('slug', 'ethernick')->first();
        $this->assertNotNull($localActor, 'Local actor ethernick should exist');

        // Create ephemeral external actor for processing
        $externalActor = Entry::make()
            ->collection('actors')
            ->slug('fan-at-external-dot-com')
            ->data([
                'title' => 'Fan',
                'activitypub_id' => 'https://external.com/users/fan',
                'is_internal' => false
            ]);

        $job = new \Ethernick\ActivityPubCore\Jobs\InboxHandler();
        $job->handle($payload, $localActor, $externalActor);

        // Assert External Actor Created and Updated
        $fan = Entry::query()->where('activitypub_id', 'https://external.com/users/fan')->first();
        $this->assertNotNull($fan, 'Fan actor should be created');

        // Assert 'followers' collection tag added (or logic ensuring they are a follower)
        // Implementation check: ActorController adds 'followers' to 'activitypub_collections'
        $collections = $fan->get('activitypub_collections', []);
        $this->assertContains('followers', $collections);

        // Also checks 'following_actors' on external actor? 
        // No, 'following_actors' on external means EXTERNAL follows [LOCAL].
        $following = $fan->get('following_actors', []);
        // Local actor ID resolution might be complex in test, but let's check basic array presence
        $this->assertNotEmpty($following);
    }



    public function test_incoming_undo_follow_removes_follower()
    {
        Http::fake([
            'https://external.com/users/fan' => Http::response([
                'id' => 'https://external.com/users/fan',
                'type' => 'Person',
                'preferredUsername' => 'fan',
                'inbox' => 'https://external.com/users/fan/inbox',
            ], 200),
        ]);

        // Cleanup any stale generic test actor first just in case
        $stale = Entry::query()->where('collection', 'actors')->where('activitypub_id', 'https://external.com/users/fan')->first();
        if ($stale)
            $stale->delete();

        // 1. Setup: Create a fan who is already following
        $fan = Entry::make()->collection('actors')->slug('fan-at-external-dot-com')->published(true)->data([
            'title' => 'Fan',
            'activitypub_id' => 'https://external.com/users/fan',
            'activitypub_collections' => ['followers'], // Already following
        ]);
        $fan->save();

        $payload = [
            'id' => 'https://external.com/activities/undo-follow-1',
            'type' => 'Undo',
            'actor' => 'https://external.com/users/fan',
            'object' => [
                'type' => 'Follow',
                'actor' => 'https://external.com/users/fan',
                'object' => 'https://yourdomain.com/users/ethernick',
            ]
        ];

        $response = $this->postJson('/@ethernick/inbox', $payload);
        $response->assertStatus(202);

        // Process Queue
        \Illuminate\Support\Facades\Artisan::call('activitypub:process-inbox');

        \Statamic\Facades\Blink::flush();
        \Statamic\Facades\Stache::clear();

        // Assert Like Count Incremented
        \Statamic\Facades\Blink::flush();
        $fan = \Statamic\Facades\Entry::find($fan->id());
        $collections = $fan->get('activitypub_collections', []);
        $this->assertNotContains('followers', $collections);
    }

    public function test_incoming_create_note()
    {
        Http::fake([
            'https://external.com/users/poster' => Http::response([
                'id' => 'https://external.com/users/poster',
                'type' => 'Person',
                'preferredUsername' => 'poster',
                'inbox' => 'https://external.com/users/poster/inbox',
            ], 200),
        ]);

        // Prerequisite: We must FOLLOW the poster for Create Note to be accepted (usually)
        // Check ActorController logic: if (in_array('following', $collections)) ...

        // So we create the actor first and mark as 'following' (we follow them)
        $poster = Entry::make()->collection('actors')->slug('poster-at-external-dot-com')->published(true)->data([
            'title' => 'Poster',
            'activitypub_id' => 'https://external.com/users/poster',
            'title' => 'Poster',
            'activitypub_id' => 'https://external.com/users/poster',
            // 'activitypub_collections' => ['following'], // We follow them - OLD LOGIC
        ]);
        $poster->save();

        // Update Local Actor to follow Poster
        $local = Entry::query()->where('collection', 'actors')->where('slug', 'ethernick')->first();
        if ($local) {
            $local->set('following_actors', array_merge($local->get('following_actors', []) ?: [], [$poster->id()]));
            $local->save();
        }

        $noteId = 'https://external.com/notes/new-note-1';
        $payload = [
            'id' => 'https://external.com/activities/create-1',
            'type' => 'Create',
            'actor' => 'https://external.com/users/poster',
            'object' => [
                'id' => $noteId,
                'type' => 'Note',
                'content' => 'Hello World',
                'attributedTo' => 'https://external.com/users/poster',
                'published' => now()->toIso8601String(),
            ]
        ];

        $response = $this->postJson('/@ethernick/inbox', $payload);
        $response->assertStatus(202);

        // Process Queue
        \Illuminate\Support\Facades\Artisan::call('activitypub:process-inbox');

        \Statamic\Facades\Blink::flush();


        $note = Entry::query()->where('activitypub_id', $noteId)->first();
        $this->assertNotNull($note);
        $this->assertEquals('Hello World', $note->get('content'));
    }

    public function test_incoming_update_modifies_entry()
    {
        // Prerequisite: Existing Note and Actor (followed)
        $poster = Entry::make()->collection('actors')->slug('updater-at-external-dot-com')->published(true)->data([
            'title' => 'Updater',
            'activitypub_id' => 'https://external.com/users/updater',
            'title' => 'Updater',
            'activitypub_id' => 'https://external.com/users/updater',
            // 'activitypub_collections' => ['following'],
        ]);
        $poster->save(); // $poster is actually updater

        // Update Local Actor to follow Updater
        $local = Entry::query()->where('collection', 'actors')->where('slug', 'ethernick')->first();
        if ($local) {
            $local->set('following_actors', array_merge($local->get('following_actors', []) ?: [], [$poster->id()]));
            $local->save();
        }

        $noteId = 'https://external.com/notes/update-note-1';
        $note = Entry::make()->collection('notes')->published(true)->data([
            'activitypub_id' => $noteId,
            'actor' => $poster->id(),
            'content' => 'Old Content',
        ]);
        $note->save();

        $payload = [
            'id' => 'https://external.com/activities/update-1',
            'type' => 'Update',
            'actor' => 'https://external.com/users/updater',
            'object' => [
                'id' => $noteId,
                'type' => 'Note',
                'content' => 'New Content',
                'attributedTo' => 'https://external.com/users/updater',
                'published' => now()->toIso8601String(),
            ]
        ];

        $response = $this->postJson('/@ethernick/inbox', $payload);
        $response->assertStatus(202);

        // Process Queue
        \Illuminate\Support\Facades\Artisan::call('activitypub:process-inbox');

        $response->assertStatus(202);

        \Statamic\Facades\Blink::flush();
        $note = \Statamic\Facades\Entry::find($note->id());
        $this->assertEquals('New Content', $note->get('content'));
    }

    public function test_inbox_reply_creates_note()
    {
        \Statamic\Facades\Blink::flush();
        // Only delete test actors, not real users
        \Statamic\Facades\Entry::query()->where('collection', 'actors')->get()
            ->filter(fn($e) => $e->slug() === 'replier')
            ->each->delete();
        \Illuminate\Support\Facades\Queue::fake();
        $this->withoutExceptionHandling();
        // Create a local actor
        $actor = \Statamic\Facades\Entry::make()
            ->collection('actors')
            ->slug('replier')
            ->id('a3d2a452-eaf3-43cb-bb83-a932f9395eca')
            ->data(['title' => 'Replier', 'is_internal' => true]);
        $actor->save();

        $originalNoteId = 'https://external.com/note/123';

        $payload = [
            'content' => 'This is a reply',
            'actor' => $actor->id(),
            'in_reply_to' => $originalNoteId,
        ];

        $user = \Statamic\Facades\User::make()->email('test@example.com')->makeSuper()->save();

        $response = $this->actingAs($user)
            ->postJson(cp_route('activitypub.inbox.reply'), $payload);

        $response->assertStatus(201);
        $response->assertJson(['success' => true]);

        // Verify entry creation
        $note = \Statamic\Facades\Entry::query()
            ->where('collection', 'notes')
            ->where('in_reply_to', $originalNoteId)
            ->first();

        $this->assertNotNull($note);
        $this->assertEquals('This is a reply', $note->get('content'));
        $this->assertEquals([$actor->id()], $note->get('actor'));
        $this->assertTrue($note->published());
    }

    public function test_mention_generation_in_json()
    {
        // 1. Create a local actor
        $actor = \Statamic\Facades\Entry::make()
            ->collection('actors')
            ->slug('mentioner')
            ->data(['title' => 'Mentioner', 'is_internal' => true]);
        $actor->save();

        // 2. Create a note with a mention
        // Simulate how InboxController stores it: content is markdown text with link
        // Actually ActivityPubListener parses markdown.
        // Let's assume content is stored as markdown: "Hello [@mentioned](https://remote.com/u/mentioned)"

        $note = \Statamic\Facades\Entry::make()
            ->collection('notes')
            ->slug('note-with-mention')
            ->data([
                'content' => 'Hello [@mentioned](https://remote.com/u/mentioned)',
                'actor' => $actor->id(),
                'published' => true,
                'is_internal' => true,
            ]);
        $note->save();

        // 3. Trigger JSON generation (handled by listener on save)
        // Retrieve the entry to check activitypub_json
        $note = \Statamic\Facades\Entry::find($note->id());
        $json = json_decode($note->get('activitypub_json'), true);

        // 4. Assertions
        $this->assertArrayHasKey('tag', $json);
        $this->assertCount(1, $json['tag']);
        $this->assertEquals('Mention', $json['tag'][0]['type']);
        $this->assertEquals('https://remote.com/u/mentioned', $json['tag'][0]['href']);
        $this->assertEquals('@mentioned', $json['tag'][0]['name']);

        $this->assertContains('https://remote.com/u/mentioned', $json['cc']);
    }

    public function test_activity_delivery_to_mentioned_actor()
    {
        \Illuminate\Support\Facades\Http::fake();

        // 1. Create Sender with Keys
        $res = openssl_pkey_new([
            'digest_alg' => 'sha256',
            'private_key_bits' => 1024,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($res, $privateKey);
        $publicKey = openssl_pkey_get_details($res)['key'];

        $sender = \Statamic\Facades\Entry::make()
            ->collection('actors')
            ->slug('sender')
            ->data([
                'title' => 'Sender',
                'is_internal' => true,
                'private_key' => $privateKey,
                'public_key' => $publicKey,
                'activitypub_id' => 'https://example.com/u/sender',
            ]);
        $sender->save();

        // 2. Create Recipient (Local for resolution)
        $recipient = \Statamic\Facades\Entry::make()
            ->collection('actors')
            ->slug('recipient')
            ->data([
                'title' => 'Recipient',
                'is_internal' => true,
                'url' => 'https://example.com/u/recipient', // AP ID
                'activitypub_id' => 'https://example.com/u/recipient', // Indexed field
                'inbox_url' => 'https://example.com/u/recipient/inbox'
            ]);
        $recipient->save();

        // 3. Create Note with mention
        $note = \Statamic\Facades\Entry::make()
            ->collection('notes')
            ->slug('mention-note')
            ->data([
                'content' => 'Hey [@recipient](https://example.com/u/recipient)',
                'actor' => $sender->id(),
                'published' => true,
                'is_internal' => true,
            ]);
        $note->save();

        // 4. Create Activity (Simulate AutoGenerateActivityListener)
        // We do this manually to ensure it exists and has the right linkage
        $activity = \Statamic\Facades\Entry::make()
            ->collection('activities')
            ->slug('create-mention-note')
            ->data([
                'type' => 'Create',
                'actor' => [$sender->id()],
                'object' => [$note->id()],
                'published' => true,
                'is_internal' => true,
            ]);
        $activity->save();

        // Force ActivityPubListener to generate JSON for the *Activity*
        // This normally happens on save, so $activity has updated 'activitypub_json' now.
        // Let's verify propagation first
        \Statamic\Facades\Blink::flush();
        $activity = \Statamic\Facades\Entry::find($activity->id());
        $json = json_decode($activity->get('activitypub_json'), true);

        $this->assertContains('https://example.com/u/recipient', $json['cc']);

        // 5. Run Job
        \Statamic\Facades\Blink::flush();
        $job = new \Ethernick\ActivityPubCore\Jobs\SendActivityPubPost($activity->id());
        $job->handle();
    }

    public function test_incoming_like_increments_count()
    {
        Http::fake([
            'https://external.com/users/liker' => Http::response([
                'id' => 'https://external.com/users/liker',
                'type' => 'Person',
                'preferredUsername' => 'liker',
                'inbox' => 'https://external.com/users/liker/inbox',
            ], 200),
        ]);

        // Delete any existing note with this slug from previous test runs
        $existing = Entry::query()->where('collection', 'notes')->where('slug', 'test-liked-note')->first();
        if ($existing) {
            $existing->delete();
        }
        \Statamic\Facades\Stache::clear();

        // 1. Create Local Note
        $note = Entry::make()
            ->collection('notes')
            ->slug('test-liked-note')
            ->data([
                'content' => 'I will be liked',
                'activitypub_id' => 'https://ethernick.com/notes/test-liked-note',
                'is_internal' => true,
                'actor' => ['actors/ethernick'],
                'liked_by' => [],
                'like_count' => 0,
            ]);
        $note->save();

        // 2. Send Like Activity
        $payload = [
            'type' => 'Like',
            'id' => 'https://external.com/activities/like-1',
            'actor' => 'https://external.com/users/liker',
            'object' => 'https://ethernick.com/notes/test-liked-note',
        ];

        // Get local actor
        $localActor = Entry::query()->where('collection', 'actors')->where('slug', 'ethernick')->first();
        $this->assertNotNull($localActor, 'Local actor ethernick should exist');

        // Create external actor entry for processing
        $externalActor = Entry::make()
            ->collection('actors')
            ->slug('liker-external')
            ->data([
                'title' => 'Liker',
                'activitypub_id' => 'https://external.com/users/liker',
                'is_internal' => false
            ]);

        // Process Like directly via InboxHandler
        $job = new \Ethernick\ActivityPubCore\Jobs\InboxHandler();
        $job->handle($payload, $localActor, $externalActor);

        // 3. Verify
        \Statamic\Facades\Blink::flush();
        $note = Entry::find($note->id());

        $this->assertEquals(1, $note->get('like_count'));
        $this->assertContains('https://external.com/users/liker', $note->get('liked_by'));
    }
}

