<?php

namespace Ethernick\ActivityPubCore\Tests;

use Illuminate\Support\Facades\Http;
use Statamic\Facades\Entry;
use Tests\TestCase;
use Ethernick\ActivityPubCore\Tests\Concerns\BackupsFiles;

class IdAccessibilityTest extends TestCase
{
    use BackupsFiles;

    protected $actorId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['statamic.editions.pro' => true]);
        \Statamic\Facades\Blink::flush();

        // Backup files before modifying them
        $this->backupFiles([
            'resources/blueprints/collections/notes/notes.yaml',
            'resources/blueprints/collections/activities/activities.yaml',
            'resources/settings/activitypub.yaml',
        ]);

        // Create Blueprints to ensure actor:slug resolves
        $blueprint = \Statamic\Facades\Blueprint::make()->setHandle('notes')->setNamespace('collections.notes')->setContents([
            'fields' => [
                [
                    'handle' => 'actor',
                    'field' => ['type' => 'entries', 'max_items' => 1]
                ],
                [
                    'handle' => 'content',
                    'field' => ['type' => 'markdown']
                ]
            ]
        ]);
        $blueprint->save();

        $blueprintActivity = \Statamic\Facades\Blueprint::make()->setHandle('activities')->setNamespace('collections.activities')->setContents([
            'fields' => [
                [
                    'handle' => 'actor',
                    'field' => ['type' => 'entries', 'max_items' => 1]
                ]
            ]
        ]);
        $blueprintActivity->save();

        // Force Routes on Collections for Test Environment
        // Simulating standard user configuration (Dynamic)
        $notes = \Statamic\Facades\Collection::find('notes') ?: \Statamic\Facades\Collection::make('notes');
        $notes->route('/notes/{slug}');
        $notes->save();

        $activities = \Statamic\Facades\Collection::find('activities') ?: \Statamic\Facades\Collection::make('activities');
        $activities->route('/activity/{slug}');
        $activities->save();

        \Statamic\Facades\Blink::flush();

        // Ensure Actor Exists and capture it
        $actor = Entry::query()->where('collection', 'actors')->where('slug', 'ethernick')->first();
        if (!$actor) {
            $actor = Entry::make()->collection('actors')->slug('ethernick')->data(['title' => 'Nick', 'is_internal' => true])->published(true);
            $actor->save();
        }
        // Create activitypub.yaml config
        if (!file_exists(resource_path('settings'))) {
            mkdir(resource_path('settings'), 0755, true);
        }
        file_put_contents(
            resource_path('settings/activitypub.yaml'),
            "notes:\n  enabled: true\n  type: Note\n  federated: true\nactivities:\n  enabled: true\n  type: Activity\n  federated: true\n"
        );

        \Statamic\Facades\Blink::flush(); // Flush again to ensure listener picks up config
    }

    protected function tearDown(): void
    {
        // Cleanup
        $collections = ['notes', 'activities', 'actors'];
        foreach ($collections as $col) {
            $entries = Entry::query()->where('collection', $col)->get();
            foreach ($entries as $entry) {
                if (str_contains($entry->slug(), 'test-')) {
                    $entry->delete();
                }
            }
        }

        // Restore backed up files
        $this->restoreBackedUpFiles();

        parent::tearDown();
    }

    public function test_note_url_returns_activitypub_json()
    {
        // 1. Create Internal Note
        $note = Entry::make()
            ->collection('notes')
            ->slug('test-note-json')
            ->data([
                'content' => 'Testing JSON Response',
                'actor' => [$this->actorId],
                'is_internal' => true,
                'published' => true,
            ]);
        $note->save();

        // Hack: Re-save to ensure JSON is regenerated with correct route if cache was stale
        \Statamic\Facades\Blink::flush();
        $note->save();

        // 2. Generate JSON (happens on save via listener)
        // Verify JSON exists first
        $note = Entry::find($note->id());

        // Testing simplified route /notes/{id}
        // This validates middleware logic independent of complex routing parameters
        $url = '/notes/' . $note->id();

        $this->assertNotEmpty($note->get('activitypub_json'));

        // 3. Request URL with Accept Header
        // Manually construct URL to avoid test-env blueprint resolution issues
        // Route: /@{actor:slug}/notes/{id}
        // If URL generation failed, this manual URL won't match the entry's generated URL, validation fails.
        // We MUST verify $note->url() is correct.

        $url = $note->url();
        if (!$url) {
            $url = '/notes/' . $note->slug();
        }

        $response = $this->get($url, [
            'Accept' => 'application/activity+json'
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/activity+json');

        $json = $response->json();
        $this->assertEquals($note->absoluteUrl(), $json['id']);
        $this->assertEquals('Note', $json['type']);
    }

    public function test_activity_url_returns_activitypub_json()
    {
        // 1. Create Internal Activity
        $activity = Entry::make()
            ->collection('activities')
            ->slug('test-activity-json')
            ->data([
                'type' => 'Create',
                'actor' => [$this->actorId],
                'object' => 'https://example.com/note/1',
                'is_internal' => true,
                'published' => true,
            ]);
        $activity->save();

        // Hack: Re-save to ensure JSON is regenerated with correct route if cache was stale
        \Statamic\Facades\Blink::flush();
        $activity->save();

        // 2. Request URL
        $url = $activity->url();
        if (!$url) {
            // Fallback if routing isn't fully binding in test env
            $url = '/activity/' . $activity->slug();
        }

        $response = $this->get($url, [
            'Accept' => 'application/activity+json'
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/activity+json');

        $json = $response->json();
        $this->assertEquals($activity->absoluteUrl(), $json['id']);
        $this->assertEquals('Create', $json['type']);
    }
}
