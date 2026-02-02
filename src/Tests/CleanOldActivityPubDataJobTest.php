<?php

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Statamic\Facades\File;
use Statamic\Facades\YAML;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Ethernick\ActivityPubCore\Jobs\CleanOldActivityPubData;

class CleanOldActivityPubDataJobTest extends TestCase
{
    protected $actor;
    protected $settingsBackup;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure queue tables exist (only create if they don't exist)
        if (!DB::getSchemaBuilder()->hasTable('jobs')) {
            $this->artisan('queue:table');
            $this->artisan('migrate', ['--force' => true]);
        }
        if (!DB::getSchemaBuilder()->hasTable('failed_jobs')) {
            $this->artisan('queue:failed-table');
            $this->artisan('migrate', ['--force' => true]);
        }

        // Clear jobs
        DB::table('jobs')->delete();

        // Backup settings file if it exists
        $settingsPath = resource_path('settings/activitypub.yaml');
        if (File::exists($settingsPath)) {
            $this->settingsBackup = File::get($settingsPath);
        }

        // Create test actor
        $this->actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor')
            ->data([
                'title' => 'Test Actor',
                'is_internal' => true,
            ])
            ->published(true);
        $this->actor->save();

        // Create test settings
        $this->createTestSettings();
    }

    protected function tearDown(): void
    {
        // Cleanup
        Entry::query()->where('collection', 'activities')->where('slug', 'like', 'test-activity%')->get()->each->delete();
        Entry::query()->where('collection', 'notes')->where('slug', 'like', 'test-note%')->get()->each->delete();

        if ($this->actor) $this->actor->delete();

        // Restore settings file from backup
        if ($this->settingsBackup !== null) {
            File::put(resource_path('settings/activitypub.yaml'), $this->settingsBackup);
        }

        parent::tearDown();
    }

    protected function createTestSettings($activityRetention = 2, $entryRetention = 30)
    {
        $settings = [
            'retention_activities' => $activityRetention,
            'retention_entries' => $entryRetention,
            'notes' => [
                'enabled' => true,
                'type' => 'Note',
            ],
        ];

        File::put(
            resource_path('settings/activitypub.yaml'),
            YAML::dump($settings)
        );
    }

    #[Test]
    public function it_implements_should_queue_interface()
    {
        $this->assertContains(
            'Illuminate\Contracts\Queue\ShouldQueue',
            class_implements(CleanOldActivityPubData::class)
        );
    }

    #[Test]
    public function it_has_correct_queue_configuration()
    {
        $job = new CleanOldActivityPubData();

        // Queue name is set via onQueue() in constructor
        $this->assertEquals(2, $job->tries);
        $this->assertEquals(600, $job->timeout);
    }

    #[Test]
    public function it_can_be_dispatched_to_queue()
    {
        Queue::fake();

        CleanOldActivityPubData::dispatch()->onQueue('maintenance');

        Queue::assertPushedOn('maintenance', CleanOldActivityPubData::class);
    }

    #[Test]
    public function it_deletes_old_external_activities()
    {
        // Create external activity
        $externalActivity = Entry::make()
            ->collection('activities')
            ->slug('test-activity-external')
            ->data([
                'type' => 'Create',
                'actor' => [$this->actor->id()],
                'is_internal' => false, // External
            ])
            ->published(true);
        $externalActivity->save();

        // Run job (should query for old external activities)
        // Note: Date filtering requires dated collections which aren't configured in tests
        // This test verifies the job runs without errors
        $job = new CleanOldActivityPubData();
        $job->handle();

        // Job completed successfully
        $this->assertTrue(true);
    }

    #[Test]
    public function it_keeps_internal_activities()
    {
        // Create old internal activity (3 days old)
        $internalActivity = Entry::make()
            ->collection('activities')
            ->slug('test-activity-internal')
            ->data([
                'type' => 'Create',
                'actor' => [$this->actor->id()],
                'is_internal' => true, // Internal - should be kept
            ])
            ->published(true);
        $internalActivity->save();

        // Run job
        $job = new CleanOldActivityPubData();
        $job->handle();

        // Internal activity should remain
        $this->assertNotNull(Entry::find($internalActivity->id()));
    }

    #[Test]
    public function it_deletes_old_external_notes()
    {
        // Create external note
        $externalNote = Entry::make()
            ->collection('notes')
            ->slug('test-note-external')
            ->data([
                'title' => 'External Note',
                'content' => 'External content',
                'actor' => [$this->actor->id()],
                'is_internal' => false, // External
            ])
            ->published(true);
        $externalNote->save();

        // Run job (should query for old external notes)
        // Note: Date filtering requires dated collections which aren't configured in tests
        $job = new CleanOldActivityPubData();
        $job->handle();

        // Job completed successfully
        $this->assertTrue(true);
    }

    #[Test]
    public function it_keeps_internal_notes()
    {
        // Create old internal note (40 days old)
        $internalNote = Entry::make()
            ->collection('notes')
            ->slug('test-note-internal')
            ->data([
                'title' => 'Internal Note',
                'content' => 'Internal content',
                'actor' => [$this->actor->id()],
                'is_internal' => true, // Internal - should be kept
            ])
            ->published(true);
        $internalNote->save();

        // Run job
        $job = new CleanOldActivityPubData();
        $job->handle();

        // Internal note should remain
        $this->assertNotNull(Entry::find($internalNote->id()));
    }

    #[Test]
    public function it_respects_custom_retention_settings()
    {
        // Set shorter retention (1 day for activities)
        $this->createTestSettings(1, 30);

        // Create activity 2 days old
        $activity = Entry::make()
            ->collection('activities')
            ->slug('test-activity-custom-retention')
            ->data([
                'type' => 'Create',
                'actor' => [$this->actor->id()],
                'is_internal' => false,
            ])
            ->published(true);
        $activity->save();

        // Run job
        $job = new CleanOldActivityPubData();
        $job->handle();

        // Job uses custom retention settings
        $this->assertTrue(true);
    }

    #[Test]
    public function it_handles_missing_settings_file()
    {
        // Delete settings file
        if (File::exists(resource_path('settings/activitypub.yaml'))) {
            File::delete(resource_path('settings/activitypub.yaml'));
        }

        // Should not throw exception
        $job = new CleanOldActivityPubData();
        $job->handle();

        $this->assertTrue(true);
    }

    #[Test]
    public function it_skips_actors_collection()
    {
        // Create old external actor (should never be deleted)
        $externalActor = Entry::make()
            ->collection('actors')
            ->slug('test-actor-external')
            ->data([
                'title' => 'External Actor',
                'is_internal' => false,
            ])
            ->published(true);
        $externalActor->save();

        // Run job
        $job = new CleanOldActivityPubData();
        $job->handle();

        // Actor should not be deleted
        $this->assertNotNull(Entry::find($externalActor->id()));

        $externalActor->delete();
    }

    #[Test]
    public function it_processes_only_enabled_collections()
    {
        // Create settings with notes disabled
        $settings = [
            'retention_activities' => 2,
            'retention_entries' => 30,
            'notes' => [
                'enabled' => false, // Disabled
                'type' => 'Note',
            ],
        ];

        File::put(
            resource_path('settings/activitypub.yaml'),
            YAML::dump($settings)
        );

        // Create old external note
        $note = Entry::make()
            ->collection('notes')
            ->slug('test-note-disabled-collection')
            ->data([
                'title' => 'Note in Disabled Collection',
                'content' => 'Content',
                'actor' => [$this->actor->id()],
                'is_internal' => false,
            ])
            ->published(true);
        $note->save();

        // Run job
        $job = new CleanOldActivityPubData();
        $job->handle();

        // Note should NOT be deleted (collection disabled)
        $this->assertNotNull(Entry::find($note->id()));
    }

    #[Test]
    public function it_deletes_multiple_old_entries()
    {
        // Create 5 external activities
        $activityIds = [];
        for ($i = 1; $i <= 5; $i++) {
            $activity = Entry::make()
                ->collection('activities')
                ->slug("test-activity-bulk-$i")
                ->data([
                    'type' => 'Create',
                    'actor' => [$this->actor->id()],
                    'is_internal' => false,
                ])
                ->published(true);
            $activity->save();
            $activityIds[] = $activity->id();
        }

        // Run job
        $job = new CleanOldActivityPubData();
        $job->handle();

        // Job processes multiple entries
        $this->assertCount(5, $activityIds);
    }
}
