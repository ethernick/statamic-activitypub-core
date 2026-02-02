<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Statamic\Facades\YAML;
use Statamic\Facades\Entry;
use Tests\TestCase;
use Carbon\Carbon;

class ActivityPubCleanTest extends TestCase
{
    // Use RefreshDatabase if Statamic uses DB, but usually Statamic Flat File doesn't need it.
    // However, if we are in a test environment tailored for Statamic, we might need to properly mock Entries.
    // For simplicity, we will assume standard Statamic Entry testing utils are available.

    protected $originalSettingsPath;
    protected $backupSettingsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalSettingsPath = resource_path('settings/activitypub.yaml');
        $this->backupSettingsPath = resource_path('settings/activitypub.yaml.bak');

        // Backup existing settings if they exist
        if (File::exists($this->originalSettingsPath)) {
            File::move($this->originalSettingsPath, $this->backupSettingsPath);
        }

        // Mock Settings
        // Create Collections
        \Statamic\Facades\Collection::make('activities')->title('Activities')->dated(true)->save();
        \Statamic\Facades\Collection::make('notes')->title('Notes')->dated(true)->save();

        $settings = [
            'enabled' => true,
            'retention_activities' => 2,
            'retention_entries' => 30,
            'notes' => ['enabled' => true, 'type' => 'Note'],
            'activities' => ['enabled' => true, 'type' => 'Activity']
        ];
        File::put($this->originalSettingsPath, YAML::dump($settings));
    }

    protected function tearDown(): void
    {
        // Delete test settings
        if (File::exists($this->originalSettingsPath)) {
            File::delete($this->originalSettingsPath);
        }

        // Restore original settings
        if (File::exists($this->backupSettingsPath)) {
            File::move($this->backupSettingsPath, $this->originalSettingsPath);
        }

        // Cleanup only test entries (those with MD5-looking slugs created by createEntry)
        Entry::query()->where('collection', 'activities')->get()
            ->filter(fn($e) => preg_match('/^[a-f0-9]{32}$/', $e->slug()))
            ->each->delete();
        Entry::query()->where('collection', 'notes')->get()
            ->filter(fn($e) => preg_match('/^[a-f0-9]{32}$/', $e->slug()))
            ->each->delete();

        parent::tearDown();
    }

    #[Test]
    public function it_cleans_old_external_activities()
    {
        // Create Old External Activity (Should be deleted)
        $oldExternal = $this->createEntry('activities', -3, false);

        // Create Newer External Activity (Should kept, 2 days retention)
        $newExternal = $this->createEntry('activities', -1, false);

        // Create Old Internal Activity (Should be kept)
        $oldInternal = $this->createEntry('activities', -5, true);

        $this->artisan('activitypub:clean')
            ->expectsOutput('ActivityPub cleanup completed.')
            ->assertExitCode(0);

        $this->assertNull(Entry::find($oldExternal->id()));
        $this->assertNotNull(Entry::find($newExternal->id()));
        $this->assertNotNull(Entry::find($oldInternal->id()));
    }

    #[Test]
    public function it_cleans_old_external_notes()
    {
        // Create Old External Note (Should be deleted, 30 days retention)
        $oldExternal = $this->createEntry('notes', -31, false);

        // Create Newer External Note (Should kept)
        $newExternal = $this->createEntry('notes', -29, false);

        // Create Old Internal Note (Should be kept)
        $oldInternal = $this->createEntry('notes', -40, true);

        $this->artisan('activitypub:clean')
            ->assertExitCode(0);

        $this->assertNull(Entry::find($oldExternal->id()));
        $this->assertNotNull(Entry::find($newExternal->id()));
        $this->assertNotNull(Entry::find($oldInternal->id()));
    }

    #[Test]
    public function it_respects_custom_retention_settings()
    {
        // Change settings to 10 days for activities
        $settings = YAML::parse(File::get($this->originalSettingsPath));
        $settings['retention_activities'] = 10;
        File::put($this->originalSettingsPath, YAML::dump($settings));

        // Create Activity 5 days old (Should be kept now)
        $activity = $this->createEntry('activities', -5, false);

        $this->artisan('activitypub:clean')
            ->assertExitCode(0);

        $this->assertNotNull(Entry::find($activity->id()));
    }

    protected function createEntry($collection, $daysAgo, $isInternal)
    {
        $id = md5(uniqid());
        $entry = Entry::make()
            ->collection($collection)
            ->id($id)
            ->slug($id)
            ->data([
                'title' => 'Test Entry',
                'is_internal' => $isInternal,
            ])
            ->date(Carbon::now()->addDays($daysAgo));
        $entry->save();
        return $entry;
    }
}
