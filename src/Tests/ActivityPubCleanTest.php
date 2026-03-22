<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\YAML;
use Statamic\Facades\Entry;
use Tests\TestCase;
use Carbon\Carbon;

class ActivityPubCleanTest extends TestCase
{
    // Use RefreshDatabase if Statamic uses DB, but usually Statamic Flat File doesn't need it.
    // However, if we are in a test environment tailored for Statamic, we might need to properly mock Entries.
    // For simplicity, we will assume standard Statamic Entry testing utils are available.

    protected function setUp(): void
    {
        parent::setUp();

        // Mock Settings in sandbox
        $settings = [
            'enabled' => true,
            'retention_activities' => 2,
            'retention_entries' => 30,
            'notes' => ['enabled' => true, 'type' => 'Note'],
            'activities' => ['enabled' => true, 'type' => 'Activity']
        ];
        File::put(\Ethernick\ActivityPubCore\Services\ActivityPubUtils::settingsPath(), YAML::dump($settings));

        // Ensure collections exist
        foreach (['activities', 'notes'] as $col) {
            if (!\Statamic\Facades\Collection::find($col)) {
                \Statamic\Facades\Collection::make($col)->dated(true)->save();
            }
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_respects_custom_retention_settings()
    {
        // Change settings to 10 days for activities
        $path = \Ethernick\ActivityPubCore\Services\ActivityPubUtils::settingsPath();
        $settings = YAML::parse(File::get($path));
        $settings['retention_activities'] = 10;
        File::put($path, YAML::dump($settings));

        // Create Activity 5 days old (Should be kept now)
        $activity = $this->createEntry('activities', -5, false);

        $this->artisan('activitypub:clean')
            ->assertExitCode(0);

        $this->assertNotNull(Entry::find($activity->id()));
    }

    protected function createEntry($collection, $daysAgo, $isInternal)
    {
        $id = 'test-clean-' . md5(uniqid());
        $entry = Entry::make()
            ->collection($collection)
            ->id(md5(uniqid())) // Give it a real UUID-like ID
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
