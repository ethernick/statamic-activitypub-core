<?php

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Ethernick\ActivityPubCore\Tests\Concerns\BackupsFiles;
use PHPUnit\Framework\Attributes\Test;

class InboxActivityTest extends TestCase
{
    use BackupsFiles;

    public function setUp(): void
    {
        parent::setUp();

        // Backup collection YAML files before tests modify them
        $this->backupFiles([
            'content/collections/actors.yaml',
            'content/collections/notes.yaml',
            'content/collections/activities.yaml',
            'content/collections/polls.yaml',
        ]);

        // Ensure collections exist with correct routes
        $this->ensureCollectionsExist();

        // Clean up test entries (not the entire directories to preserve YAML files)
        $this->cleanupTestEntries();

        // Clear caches to ensure fresh data
        \Statamic\Facades\Blink::flush();
        \Statamic\Facades\Stache::clear();

        \Statamic\Facades\Config::set('statamic.editions.pro', true);
    }

    protected function ensureCollectionsExist(): void
    {
        // Ensure actors collection exists with correct route
        if (!\Statamic\Facades\Collection::find('actors')) {
            $actors = \Statamic\Facades\Collection::make('actors');
            $actors->route('/actor/{slug}');
            $actors->save();
        }

        // Ensure other collections exist
        if (!\Statamic\Facades\Collection::find('notes')) {
            \Statamic\Facades\Collection::make('notes')->save();
        }
        if (!\Statamic\Facades\Collection::find('activities')) {
            \Statamic\Facades\Collection::make('activities')->save();
        }
        if (!\Statamic\Facades\Collection::find('polls')) {
            \Statamic\Facades\Collection::make('polls')->save();
        }
    }

    protected function cleanupTestEntries(): void
    {
        // Delete ALL entries from test collections to ensure clean slate
        // We preserve the YAML files but remove all content entries
        foreach (['notes', 'actors', 'activities', 'polls'] as $collection) {
            $entries = Entry::query()->where('collection', $collection)->get();
            foreach ($entries as $entry) {
                try {
                    $entry->delete();
                } catch (\Exception $e) {
                    // Ignore errors during cleanup
                }
            }
        }
    }

    protected function tearDown(): void
    {
        // Clean up test entries created during tests
        $this->cleanupTestEntries();

        // Restore backed up files
        $this->restoreBackedUpFiles();

        parent::tearDown();
    }

    #[Test]
    public function it_returns_generic_activities_in_inbox()
    {
        $this->actingAs(User::make()->id('admin')->makeSuper()->save());

        // Create Actor
        $actor = Entry::make()->collection('actors')->slug('test-user')->data(['title' => 'Test User']);
        $actor->save();

        // Create Note (should appear)
        $note = Entry::make()
            ->collection('notes')
            ->slug('note-1')
            ->date(\Carbon\Carbon::parse('2025-01-01 10:00:00'))
            ->data([
                'content' => 'Hello',
                'actor' => $actor->id(),
                'activitypub_id' => 'https://example.com/note/1'
            ]);
        $note->save();

        // Create "Create" Activity for a NOTE (should be FILTERED OUT if we have the Note)
        Entry::make()
            ->collection('activities')
            ->slug('create-note-1')
            ->date(\Carbon\Carbon::parse('2025-01-01 10:00:00'))
            ->data([
                'type' => 'Create',
                'actor' => $actor->id(),
                'object' => 'https://example.com/note/1', // Matches the Note above
                'activitypub_id' => 'https://example.com/create/1'
            ])
            ->save();

        // Create "Follow" Activity (should APPEAR)
        Entry::make()
            ->collection('activities')
            ->slug('follow-1')
            ->date(\Carbon\Carbon::parse('2025-01-02 10:00:00'))
            ->data([
                'type' => 'Follow',
                'actor' => $actor->id(),
                'object' => 'https://example.com/user/me',
                'activitypub_id' => 'https://example.com/follow/1'
            ])
            ->save();

        $response = $this->get('/cp/activitypub/inbox/api');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(2, $data); // Follow, Note. (Create activities are filtered)

        // Order check (descending by date)
        $this->assertEquals('activity', $data[0]['type']); // Follow (Jan 2)
        $this->assertStringContainsString('Follow', $data[0]['content']);

        $this->assertEquals('note', $data[1]['type']); // Note (Jan 1)
    }
    #[Test]
    public function it_filters_activities_only()
    {
        $this->withoutExceptionHandling();
        $this->actingAs(User::make()->id('admin')->makeSuper()->save());

        // Create external actor (is_internal = false) to prevent auto-generation of Update activities
        $actor = Entry::make()->collection('actors')->slug('test-user')->data(['title' => 'Test User', 'is_internal' => false]);
        $actor->save();

        // Note
        Entry::make()->collection('notes')->slug('note-1')->date(now())->data(['content' => 'Note', 'actor' => $actor->id(), 'activitypub_id' => 'x1'])->save();

        // Activity (Follow)
        Entry::make()->collection('activities')->slug('follow-1')->date(now())->data(['type' => 'Follow', 'actor' => $actor->id(), 'object' => 'x', 'activitypub_id' => 'x2'])->save();

        $response = $this->get('/cp/activitypub/inbox/api?filter=activities');
        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals('activity', $data[0]['type']);
        $this->assertStringContainsString('Follow', $data[0]['content']);
    }

    #[Test]
    public function it_filters_mentions_only()
    {
        $this->withoutExceptionHandling();
        $user = User::make()->id('admin_men')->data(['actors' => []])->makeSuper();
        $user->save();
        $this->actingAs($user);

        // User's internal actor
        $myActor = Entry::make()->collection('actors')->slug('me')->data(['title' => 'Me', 'is_internal' => true]);
        $myActor->save();

        $user->set('actors', [$myActor->id()])->save();

        // External actor
        $otherActor = Entry::make()->collection('actors')->slug('other')->data(['title' => 'Other']);
        $otherActor->save();

        // Note NOT mentioning me
        Entry::make()->collection('notes')->slug('note-1')->date(now())->data(['content' => 'Hello world', 'actor' => $otherActor->id(), 'activitypub_id' => 'x1'])->save();

        // Note mentioning me by Handle (@me@host) - Controller uses slug . @ . host
        $host = request()->getHost();
        $handle = "@me@{$host}";
        Entry::make()->collection('notes')->slug('note-2')->date(now())->data([
            'content' => "Hello {$handle}",
            'actor' => $otherActor->id(),
            'activitypub_id' => 'x2',
            'mentioned_urls' => [$myActor->absoluteUrl()] // Add structured mention
        ])->save();

        // Note mentioning me by URL
        Entry::make()->collection('notes')->slug('note-3')->date(now())->data([
            'content' => "Hello check out {$myActor->absoluteUrl()}",
            'actor' => $otherActor->id(),
            'activitypub_id' => 'x3',
            'mentioned_urls' => [$myActor->absoluteUrl()] // Add structured mention
        ])->save();

        $response = $this->get('/cp/activitypub/inbox/api?filter=mentions');
        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(2, $data);
        // Note 2 and 3 should be present
        $ids = collect($data)->pluck('id')->sort()->values();
        // Since we don't have IDs easily, let's check content matches
        $contents = collect($data)->pluck('content')->implode(' ');
        $this->assertStringContainsString($handle, $contents);
        $this->assertStringContainsString($myActor->absoluteUrl(), $contents);
        $this->assertStringNotContainsString('Hello world', $contents); // note-1 shouldn't be here
    }

    #[Test]
    public function it_passes_inbox_data_to_view()
    {
        $this->actingAs(User::make()->id('admin')->makeSuper()->save());

        $response = $this->get(cp_route('activitypub.inbox.index'));
        $response->assertOk();
        $response->assertViewIs('activitypub::inbox');
        $response->assertViewHas('createNoteUrl');
    }

    #[Test]
    public function it_returns_sensitive_fields_in_api()
    {
        $this->actingAs(User::make()->id('admin')->makeSuper()->save());

        $actor = Entry::make()->collection('actors')->slug('test-user')->data(['title' => 'Test User']);
        $actor->save();

        $note = Entry::make()
            ->collection('notes')
            ->slug('sensitive-note')
            ->date(now())
            ->data([
                'content' => 'Hidden',
                'actor' => $actor->id(),
                'activitypub_id' => 'https://example.com/note/s',
                'sensitive' => true,
                'summary' => 'Spoiler Alert'
            ]);
        $note->save();

        $response = $this->get('/cp/activitypub/inbox/api');
        $response->assertOk();

        $data = $response->json('data');
        $item = collect($data)->firstWhere('id', $note->id());

        $this->assertNotNull($item);
        $this->assertTrue($item['sensitive']);
        $this->assertEquals('Spoiler Alert', $item['summary']);
    }
}
