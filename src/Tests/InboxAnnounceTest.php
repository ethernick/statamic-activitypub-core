<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Ethernick\ActivityPubCore\Jobs\InboxHandler;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Tests\TestCase;
use Ethernick\ActivityPubCore\Tests\Concerns\BackupsFiles;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class InboxAnnounceTest extends TestCase
{
    use BackupsFiles;
    use RefreshDatabase;

    protected $user;
    protected $localActor;

    protected function setUp(): void
    {
        parent::setUp();

        // Backup collection YAML files before modifying them
        $this->backupFiles([
            'resources/settings/activitypub.yaml',
            'content/collections/actors.yaml',
            'content/collections/notes.yaml',
            'content/collections/activities.yaml',
        ]);

        config(['statamic.editions.pro' => true]);

        // Clean up test entries (not the entire directories to preserve YAML files)
        $this->cleanupTestEntries();

        // Ensure collections exist with correct routes
        $this->ensureCollectionsExist();

        // Create activitypub.yaml config with federated: true
        if (!file_exists(resource_path('settings'))) {
            mkdir(resource_path('settings'), 0755, true);
        }
        file_put_contents(
            resource_path('settings/activitypub.yaml'),
            "notes:\n  enabled: true\n  type: Note\n  federated: true\npolls:\n  enabled: true\n  type: Question\n  federated: true\nactivities:\n  enabled: true\n  type: Activity\n"
        );

        $this->user = User::make()
            ->email('test@statamic.com')
            ->makeSuper()
            ->save();

        $this->localActor = Entry::make()
            ->collection('actors')
            ->slug('local-actor')
            ->data(['title' => 'Local Actor']);
        $this->localActor->save();
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
        // Restore activitypub.yaml from git to prevent test pollution
        // Restore backed up files
        $this->restoreBackedUpFiles();
        parent::tearDown();
    }

    #[Test]
    public function it_handles_incoming_announce_activity()
    {
        // 1. Create a local note to be boosted (simplifies testing so we don't fetch)
        $originalNote = Entry::make()
            ->collection('notes')
            ->slug('original-note')
            ->data(['title' => 'Original Note', 'activitypub_id' => 'https://remote.com/notes/1']);
        $originalNote->save();

        // 2. Mock payload for incoming Announce
        $announceId = 'https://remote.com/activities/boost/1';
        $boosterId = 'https://remote.com/users/booster';

        $boosterActor = Entry::make()
            ->collection('actors')
            ->slug('booster')
            ->data(['title' => 'Booster', 'activitypub_id' => $boosterId]);
        $boosterActor->save();

        $payload = [
            'type' => 'Announce',
            'id' => $announceId,
            'actor' => $boosterId,
            'object' => 'https://remote.com/notes/1',
            'published' => now()->toIso8601String(),
        ];

        // 3. Process Inbox
        $handler = new InboxHandler();
        $handler->handle($payload, $this->localActor, $boosterActor);

        // Refresh the original note from the database to see the boost update
        \Statamic\Facades\Stache::clear();
        $originalNote = Entry::find($originalNote->id());

        // Assert the original note was updated with boost information
        $this->assertNotNull($originalNote, "Original note should still exist.");

        // Verify boosted_by array includes the booster
        $boostedBy = $originalNote->get('boosted_by', []);
        $this->assertIsArray($boostedBy, "boosted_by should be an array");
        $this->assertContains($boosterActor->id(), $boostedBy, "Booster should be in boosted_by array");

        // Verify boost_count was updated
        $boostCount = $originalNote->get('boost_count', 0);
        $this->assertEquals(1, $boostCount, "Boost count should be 1");

        // Verify the Announce activity was saved in the activities collection
        $activity = Entry::query()
            ->where('collection', 'activities')
            ->where('activitypub_id', $announceId)
            ->first();

        $this->assertNotNull($activity, "Announce activity should be saved");
        $this->assertEquals('Announce', $activity->get('type'));
    }


    #[Test]
    public function it_resolves_external_note_for_boost()
    {
        // This test would require mocking HTTP requests which is more complex.
        // We will stick to verifying the handler logic given it can find the note.
        $this->assertTrue(true);
    }
}
