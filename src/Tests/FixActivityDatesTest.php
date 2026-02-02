<?php

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Illuminate\Support\Carbon;
use Ethernick\ActivityPubCore\Tests\Concerns\BackupsFiles;

class FixActivityDatesTest extends TestCase
{
    use BackupsFiles;

    public function setUp(): void
    {
        parent::setUp();

        // Backup blueprint before modifying it
        $this->backupFile('resources/blueprints/collections/activities/activities.yaml');

        \Statamic\Facades\Collection::make('activities')->dated(true)->save();

        $blueprint = \Statamic\Facades\Blueprint::make()->setHandle('activities')->setNamespace('collections.activities')->setContents([
            'fields' => [
                [
                    'handle' => 'date',
                    'field' => ['type' => 'date', 'time_enabled' => true]
                ]
            ]
        ]);
        $blueprint->save();

        \Statamic\Facades\Collection::make('notes')->dated(true)->save();
        // Clean up test data only - preserve real user data
        Entry::query()->whereIn('collection', ['activities', 'notes'])->get()
            ->filter(fn($e) => str_contains($e->slug() ?? '', 'test-'))
            ->each->delete();
    }

    protected function tearDown(): void
    {
        // Restore backed up files
        $this->restoreBackedUpFiles();

        parent::tearDown();
    }

    #[Test]
    public function it_fixes_activity_date_based_on_payload()
    {
        \Illuminate\Support\Facades\Event::fake(); // Prevent listeners from modifying our test data

        $this->actingAs(User::make()->id('admin')->makeSuper()->save());

        $publishedDate = '2023-05-15T10:00:00Z';
        $wrongDate = now();

        // Create Activity with wrong date
        $entry = Entry::make()
            ->collection('activities')
            ->slug('test-activity')
            ->date($wrongDate)
            ->data([
                'activitypub_json' => json_encode([
                    'published' => $publishedDate
                ])
            ]);
        $entry->save();

        // Run command
        \Illuminate\Support\Facades\Artisan::call('activitypub:maintenance');
        $output = \Illuminate\Support\Facades\Artisan::output();
        dump($output);

        // $this->artisan('activitypub:fix-dates')
        //     ->expectsOutputToContain('Date correction complete')
        //     ->assertExitCode(0);

        // Assert date is fixed
        $fixedEntry = Entry::find($entry->id());
        $expectedTimestamp = Carbon::parse($publishedDate)->timestamp;

        $this->assertEquals($expectedTimestamp, $fixedEntry->date()->timestamp);

        // --- Verify Note Fix ---
        $notePublishedDate = '2023-06-20T15:30:00Z';
        $wrongNoteDate = now();

        $noteEntry = Entry::make()
            ->collection('notes')
            ->slug('test-note')
            ->date($wrongNoteDate)
            ->data([
                'is_internal' => false,
                'activitypub_json' => json_encode([
                    'published' => $notePublishedDate,
                    'type' => 'Note'
                ])
            ]);
        $noteEntry->save();

        \Illuminate\Support\Facades\Artisan::call('activitypub:maintenance');

        $fixedNote = Entry::find($noteEntry->id());
        $expectedNoteTimestamp = Carbon::parse($notePublishedDate)->timestamp;

        $this->assertEquals($expectedNoteTimestamp, $fixedNote->date()->timestamp);

        // --- Verify Nested Date Fix (Article inside Create) ---
        $nestedPublishedDate = '2023-04-10T09:00:00Z';
        $wrongNestedDate = now();

        $activityEntry = Entry::make()
            ->collection('activities')
            ->slug('test-nested-activity')
            ->date($wrongNestedDate)
            ->data([
                'is_internal' => false,
                'activitypub_json' => json_encode([
                    'type' => 'Create',
                    // Top level published missing
                    'object' => [
                        'type' => 'Article',
                        'published' => $nestedPublishedDate
                    ]
                ])
            ]);
        $activityEntry->save();

        \Illuminate\Support\Facades\Artisan::call('activitypub:maintenance');

        $fixedActivity = Entry::find($activityEntry->id());
        $expectedNestedTimestamp = Carbon::parse($nestedPublishedDate)->timestamp;

        $this->assertEquals($expectedNestedTimestamp, $fixedActivity->date()->timestamp);
    }
}
