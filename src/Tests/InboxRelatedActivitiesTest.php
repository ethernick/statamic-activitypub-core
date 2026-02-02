<?php

namespace Ethernick\ActivityPubCore\Tests;

use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Tests\TestCase;
use Ethernick\ActivityPubCore\Tests\Concerns\BackupsFiles;
use PHPUnit\Framework\Attributes\Test;

class InboxRelatedActivitiesTest extends TestCase
{
    use BackupsFiles;

    public function setUp(): void
    {
        parent::setUp();

        // Backup collection YAML files before modifying them
        $this->backupFiles([
            'resources/blueprints/collections/activities/activities.yaml',
            'content/collections/actors.yaml',
            'content/collections/notes.yaml',
            'content/collections/activities.yaml',
        ]);

        // Clean up test entries (not the entire directories to preserve YAML files)
        $this->cleanupTestEntries();

        // Ensure collections exist with correct routes
        $this->ensureCollectionsExist();

        // Ensure blueprints exist
        if (!\Statamic\Facades\Blueprint::find('collections/notes/note')) {
            \Statamic\Facades\Blueprint::make('note')->setNamespace('collections.notes')->save();
        }
        if (!\Statamic\Facades\Blueprint::find('collections/activities/activity')) {
            \Statamic\Facades\Blueprint::make('activity')->setNamespace('collections.activities')->save();
        }

        // Clear caches
        \Statamic\Facades\Blink::flush();
        \Statamic\Facades\Stache::clear();
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
    }

    protected function cleanupTestEntries(): void
    {
        // Delete ALL entries from test collections to ensure clean slate
        foreach (['notes', 'actors', 'activities'] as $collection) {
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
        // Restore backed up files
        $this->restoreBackedUpFiles();

        parent::tearDown();
    }

    #[Test]
    public function test_activities_endpoint_returns_related_activities()
    {
        $user = User::make()->id('test-admin')->email('admin@example.com')->makeSuper();
        $user->save();
        $this->actingAs($user);

        // 1. Create a Note (mark as external to prevent auto-generation of Create activity)
        $noteId = 'http://example.com/notes/123';
        $note = Entry::make()
            ->collection('notes')
            ->slug('test-note')
            ->data([
                'content' => 'Original Content',
                'activitypub_id' => $noteId,
                'actor' => ['test-actor'],
                'is_internal' => false // Prevent auto-generation
            ])
            ->published(true);
        $note->save();

        // 2. Create a RELATED Activity (Update activity for the note)
        $relatedActivity = Entry::make()
            ->collection('activities')
            ->slug('test-related-activity')
            ->data([
                'type' => 'Update',
                'object' => $noteId, // Matching the Note's AP ID
                'activitypub_id' => 'http://example.com/activities/888',
                'actor' => ['test-actor']
            ])
            ->published(true);
        $relatedActivity->save();

        // --- Verify Activities Endpoint returns related activities ---
        $activitiesResponse = $this->get(cp_route('activitypub.inbox.activities', ['id' => $note->id()]));
        $activitiesResponse->assertOk();
        $activitiesData = $activitiesResponse->json('data');

        // Should contain the Update activity
        $this->assertCount(1, $activitiesData);
        $this->assertEquals('Update', $activitiesData[0]['type']);
    }
}
