<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Ethernick\ActivityPubCore\Jobs\RecalculateActivityPubCounts;

class RecalculateActivityPubCountsJobTest extends TestCase
{
    protected $actor;
    protected $note;

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

        // Clean up any leftover test data from previous tests
        Entry::query()->where('collection', 'notes')->where('slug', 'like', 'test-note%')->get()->each->delete();
        Entry::query()->where('collection', 'activities')->where('slug', 'like', 'test-activity%')->get()->each->delete();
        Entry::query()->where('collection', 'actors')->where('slug', 'test-actor')->get()->each->delete();

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
    }

    protected function tearDown(): void
    {
        // Cleanup all test data
        Entry::query()->where('collection', 'notes')->where('slug', 'like', 'test-note%')->get()->each->delete();
        Entry::query()->where('collection', 'activities')->where('slug', 'like', 'test-activity%')->get()->each->delete();

        if ($this->actor) $this->actor->delete();

        parent::tearDown();
    }

    #[Test]
    public function it_implements_should_queue_interface()
    {
        $this->assertContains(
            'Illuminate\Contracts\Queue\ShouldQueue',
            class_implements(RecalculateActivityPubCounts::class)
        );
    }

    #[Test]
    public function it_has_correct_queue_configuration()
    {
        $job = new RecalculateActivityPubCounts();

        // Queue name is set via onQueue() in constructor
        $this->assertEquals(2, $job->tries);
        $this->assertEquals(600, $job->timeout);
    }

    #[Test]
    public function it_can_be_dispatched_to_queue()
    {
        Queue::fake();

        RecalculateActivityPubCounts::dispatch()->onQueue('maintenance');

        Queue::assertPushedOn('maintenance', RecalculateActivityPubCounts::class);
    }

    #[Test]
    public function it_recalculates_reply_count()
    {
        // Create a note
        $note = Entry::make()
            ->collection('notes')
            ->slug('test-note-with-replies')
            ->data([
                'title' => 'Test Note',
                'content' => 'Content',
                'actor' => [$this->actor->id()],
                'activitypub_id' => 'https://test.com/notes/1',
                'reply_count' => 0, // Start with wrong count
            ])
            ->published(true);
        $note->saveQuietly();

        // Create 3 replies
        for ($i = 1; $i <= 3; $i++) {
            Entry::make()
                ->collection('notes')
                ->slug("test-note-reply-$i")
                ->data([
                    'title' => "Reply $i",
                    'content' => "Reply content $i",
                    'actor' => [$this->actor->id()],
                    'in_reply_to' => $note->id(),
                ])
                ->published(true)
                ->saveQuietly();
        }

        // Run job
        $job = new RecalculateActivityPubCounts();
        $job->handle();

        // Verify count updated
        $note = $note->fresh();
        $this->assertEquals(3, $note->get('reply_count'));
    }

    #[Test]
    public function it_recalculates_like_count()
    {
        // Create a note
        $note = Entry::make()
            ->collection('notes')
            ->slug('test-note-with-likes')
            ->data([
                'title' => 'Test Note',
                'content' => 'Content',
                'actor' => [$this->actor->id()],
                'activitypub_id' => 'https://test.com/notes/2',
                'like_count' => 0,
            ])
            ->published(true);
        $note->saveQuietly();

        // Create 5 Like activities
        for ($i = 1; $i <= 5; $i++) {
            Entry::make()
                ->collection('activities')
                ->slug("test-activity-like-$i")
                ->data([
                    'type' => 'Like',
                    'actor' => [$this->actor->id()],
                    'object' => $note->id(),
                ])
                ->published(true)
                ->saveQuietly();
        }

        // Run job
        $job = new RecalculateActivityPubCounts();
        $job->handle();

        // Verify count updated
        $note = $note->fresh();
        $this->assertEquals(5, $note->get('like_count'));
    }

    #[Test]
    public function it_recalculates_boost_count()
    {
        // Create a note
        $note = Entry::make()
            ->collection('notes')
            ->slug('test-note-with-boosts')
            ->data([
                'title' => 'Test Note',
                'content' => 'Content',
                'actor' => [$this->actor->id()],
                'activitypub_id' => 'https://test.com/notes/3',
                'boost_count' => 0,
            ])
            ->published(true);
        $note->saveQuietly();

        // Create 2 Announce activities
        for ($i = 1; $i <= 2; $i++) {
            Entry::make()
                ->collection('activities')
                ->slug("test-activity-announce-$i")
                ->data([
                    'type' => 'Announce',
                    'actor' => [$this->actor->id()],
                    'object' => $note->id(),
                ])
                ->published(true)
                ->saveQuietly();
        }

        // Run job
        $job = new RecalculateActivityPubCounts();
        $job->handle();

        // Verify count updated
        $note = $note->fresh();
        $this->assertEquals(2, $note->get('boost_count'));
    }

    #[Test]
    public function it_recalculates_related_activity_count()
    {
        // Create a note (use saveQuietly to prevent AutoGenerateActivityListener from creating an activity)
        $note = Entry::make()
            ->collection('notes')
            ->slug('test-note-with-activities')
            ->data([
                'title' => 'Test Note',
                'content' => 'Content',
                'actor' => [$this->actor->id()],
                'activitypub_id' => 'https://test.com/notes/4',
                'related_activity_count' => 0,
            ])
            ->published(true);
        $note->saveQuietly();

        // Create various activities (Like, Announce, etc.)
        Entry::make()
            ->collection('activities')
            ->slug('test-activity-like-for-related')
            ->data([
                'type' => 'Like',
                'actor' => [$this->actor->id()],
                'object' => $note->id(),
            ])
            ->published(true)
            ->saveQuietly();

        Entry::make()
            ->collection('activities')
            ->slug('test-activity-announce-for-related')
            ->data([
                'type' => 'Announce',
                'actor' => [$this->actor->id()],
                'object' => $note->id(),
            ])
            ->published(true)
            ->saveQuietly();

        // Run job
        $job = new RecalculateActivityPubCounts();
        $job->handle();

        // Verify count updated (should be 2)
        $note = $note->fresh();
        $this->assertEquals(2, $note->get('related_activity_count'));
    }

    #[Test]
    public function it_only_updates_notes_with_changed_counts()
    {
        // Create note with correct counts (use saveQuietly to prevent AutoGenerateActivityListener)
        $note = Entry::make()
            ->collection('notes')
            ->slug('test-note-correct-counts')
            ->data([
                'title' => 'Test Note',
                'content' => 'Content',
                'actor' => [$this->actor->id()],
                'activitypub_id' => 'https://test.com/notes/5',
                'reply_count' => 0,
                'like_count' => 0,
                'boost_count' => 0,
                'related_activity_count' => 0,
            ])
            ->published(true);
        $note->saveQuietly();

        $originalUpdatedAt = $note->lastModified();

        // Run job
        $job = new RecalculateActivityPubCounts();
        $job->handle();

        // Note should not be saved again since counts are correct
        $note = $note->fresh();
        $this->assertEquals($originalUpdatedAt->timestamp, $note->lastModified()->timestamp);
    }

    #[Test]
    public function it_handles_notes_without_activitypub_id()
    {
        // Create note without activitypub_id
        $note = Entry::make()
            ->collection('notes')
            ->slug('test-note-no-ap-id')
            ->data([
                'title' => 'Test Note',
                'content' => 'Content',
                'actor' => [$this->actor->id()],
            ])
            ->published(true);
        $note->saveQuietly();

        // Should not throw exception
        $job = new RecalculateActivityPubCounts();
        $job->handle();

        $this->assertTrue(true);
    }

    #[Test]
    public function it_matches_by_multiple_identifiers()
    {
        // Create note
        $note = Entry::make()
            ->collection('notes')
            ->slug('test-note-multi-id')
            ->data([
                'title' => 'Test Note',
                'content' => 'Content',
                'actor' => [$this->actor->id()],
                'activitypub_id' => 'https://test.com/notes/multi',
            ])
            ->published(true);
        $note->saveQuietly();

        // Create Like using entry ID
        Entry::make()
            ->collection('activities')
            ->slug('test-activity-like-by-id')
            ->data([
                'type' => 'Like',
                'actor' => [$this->actor->id()],
                'object' => $note->id(), // Entry ID
            ])
            ->published(true)
            ->saveQuietly();

        // Create Like using AP ID
        Entry::make()
            ->collection('activities')
            ->slug('test-activity-like-by-ap-id')
            ->data([
                'type' => 'Like',
                'actor' => [$this->actor->id()],
                'object' => 'https://test.com/notes/multi', // AP ID
            ])
            ->published(true)
            ->saveQuietly();

        // Run job
        $job = new RecalculateActivityPubCounts();
        $job->handle();

        // Should count both
        $note = $note->fresh();
        $this->assertEquals(2, $note->get('like_count'));
    }
}
