<?php

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Ethernick\ActivityPubCore\Jobs\SendActivityPubPost;
use Ethernick\ActivityPubCore\Jobs\RecalculateActivityPubCounts;
use Ethernick\ActivityPubCore\Jobs\CleanOldActivityPubData;

class QueueIntegrationTest extends TestCase
{
    protected $localActor;
    protected $externalActor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);

        // Ensure queue tables exist (only create if they don't exist)
        if (!DB::getSchemaBuilder()->hasTable('jobs')) {
            $this->artisan('queue:table');
            $this->artisan('migrate', ['--force' => true]);
        }
        if (!DB::getSchemaBuilder()->hasTable('failed_jobs')) {
            $this->artisan('queue:failed-table');
            $this->artisan('migrate', ['--force' => true]);
        }

        // Clear queue tables
        DB::table('jobs')->delete();
        DB::table('failed_jobs')->delete();

        // Clean up any leftover test data
        Entry::query()->where('collection', 'activities')->where('slug', 'like', 'test-%')->get()->each->delete();
        Entry::query()->where('collection', 'notes')->where('slug', 'like', 'test-%')->get()->each->delete();

        // Create actors
        $this->localActor = Entry::make()
            ->collection('actors')
            ->slug('local-user')
            ->data([
                'title' => 'Local User',
                'is_internal' => true,
                'activitypub_id' => 'https://localhost/users/local-user',
                'private_key' => $this->generatePrivateKey(),
                'public_key' => 'dummy-public-key',
                'followed_by_actors' => [], // Will be set after external actor is created
            ])
            ->published(true);
        $this->localActor->saveQuietly();

        $this->externalActor = Entry::make()
            ->collection('actors')
            ->slug('external-user')
            ->data([
                'title' => 'External User',
                'is_internal' => false,
                'activitypub_id' => 'https://external.com/users/user',
                'inbox_url' => 'https://external.com/users/user/inbox',
                'following_actors' => [$this->localActor->id()],
            ])
            ->published(true);
        $this->externalActor->saveQuietly();

        // Set up bidirectional relationship - external actor is following local actor
        $this->localActor->set('followed_by_actors', [$this->externalActor->id()]);
        $this->localActor->saveQuietly();

        // Clear any jobs that might have been created during setup
        DB::table('jobs')->delete();
    }

    protected function tearDown(): void
    {
        // Cleanup
        Entry::query()->where('collection', 'activities')->where('slug', 'like', 'test-%')->get()->each->delete();
        Entry::query()->where('collection', 'notes')->where('slug', 'like', 'test-%')->get()->each->delete();

        if ($this->externalActor) $this->externalActor->delete();
        if ($this->localActor) $this->localActor->delete();

        DB::table('jobs')->delete();
        DB::table('failed_jobs')->delete();

        parent::tearDown();
    }

    protected function generatePrivateKey()
    {
        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $res = openssl_pkey_new($config);
        openssl_pkey_export($res, $privateKey);
        return $privateKey;
    }

    #[Test]
    public function it_processes_full_activity_publishing_workflow()
    {
        Http::fake([
            'external.com/*' => Http::response(['ok' => true], 200),
        ]);

        // 1. Create an activity (simulates user creating a note/post)
        $activity = Entry::make()
            ->collection('activities')
            ->slug('test-create-activity')
            ->data([
                'type' => 'Create',
                'actor' => [$this->localActor->id()],
                'object' => ['https://localhost/notes/test'],
                'is_internal' => true,
                'activitypub_json' => json_encode([
                    'type' => 'Create',
                    'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                    'cc' => [$this->localActor->url() . '/followers'],
                ]),
            ])
            ->published(true);
        $activity->save();

        // 2. Verify job was queued
        $this->assertGreaterThan(0, DB::table('jobs')->where('queue', 'activitypub-outbox')->count());

        // 3. Process the queue manually to keep HTTP fake in scope
        // Get the queued job and process it directly
        $job = DB::table('jobs')->where('queue', 'activitypub-outbox')->first();
        if ($job) {
            $payload = json_decode($job->payload, true);
            $command = unserialize($payload['data']['command']);
            $command->handle();
            // Delete the job after processing
            DB::table('jobs')->where('id', $job->id)->delete();
        }

        // 4. Verify job was processed
        $this->assertEquals(0, DB::table('jobs')->where('queue', 'activitypub-outbox')->count());

        // 5. Verify HTTP request was sent
        Http::assertSent(function ($request) {
            return $request->url() === 'https://external.com/users/user/inbox';
        });

        $activity->delete();
    }

    #[Test]
    public function it_handles_failed_jobs_with_retry()
    {
        // Simulate server error that will retry
        Http::fake([
            'external.com/*' => Http::response(['error' => 'Server Error'], 500),
        ]);

        $activity = Entry::make()
            ->collection('activities')
            ->slug('test-retry-activity')
            ->data([
                'type' => 'Create',
                'actor' => [$this->localActor->id()],
                'is_internal' => true,
                'activitypub_json' => json_encode([
                    'type' => 'Create',
                    'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                    'cc' => [$this->localActor->url() . '/followers'],
                ]),
            ])
            ->published(true);
        $activity->save();

        // Job should be in queue
        $this->assertGreaterThan(0, DB::table('jobs')->where('queue', 'activitypub-outbox')->count());

        // Process once - manually to keep HTTP fake in scope
        $job = DB::table('jobs')->where('queue', 'activitypub-outbox')->first();
        if ($job) {
            $payload = json_decode($job->payload, true);
            $command = unserialize($payload['data']['command']);
            try {
                $command->handle();
            } catch (\Exception $e) {
                // Expected to fail with 500 error, but HTTP request should still be recorded
            }
        }

        // Job should have been attempted
        Http::assertSentCount(1);

        $activity->delete();
    }

    #[Test]
    public function it_processes_maintenance_queue()
    {
        // Create a note that needs count recalculation
        $note = Entry::make()
            ->collection('notes')
            ->slug('test-maintenance-note')
            ->data([
                'title' => 'Test Note',
                'content' => 'Content',
                'actor' => [$this->localActor->id()],
                'activitypub_id' => 'https://localhost/notes/maintenance',
                'like_count' => 0,
            ])
            ->published(true);
        $note->save();

        // Create likes
        Entry::make()
            ->collection('activities')
            ->slug('test-like-for-maintenance')
            ->data([
                'type' => 'Like',
                'actor' => [$this->localActor->id()],
                'object' => $note->id(),
            ])
            ->published(true)
            ->save();

        // Dispatch maintenance job
        RecalculateActivityPubCounts::dispatch()->onQueue('maintenance');

        // Verify job queued
        $this->assertGreaterThan(0, DB::table('jobs')->where('queue', 'maintenance')->count());

        // Process maintenance queue
        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'maintenance',
            '--stop-when-empty' => true,
        ]);

        // Verify counts updated
        $note = $note->fresh();
        $this->assertEquals(1, $note->get('like_count'));
    }

    #[Test]
    public function it_handles_multiple_queues_independently()
    {
        Http::fake([
            'external.com/*' => Http::response(['ok' => true], 200),
        ]);

        // Queue jobs to different queues
        $activity = Entry::make()
            ->collection('activities')
            ->slug('test-multi-queue-activity')
            ->data([
                'type' => 'Create',
                'actor' => [$this->localActor->id()],
                'is_internal' => true,
                'activitypub_json' => json_encode([
                    'type' => 'Create',
                    'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                    'cc' => [$this->localActor->url() . '/followers'],
                ]),
            ])
            ->published(true);
        $activity->save();

        RecalculateActivityPubCounts::dispatch()->onQueue('maintenance');

        // Verify jobs in different queues
        $this->assertGreaterThan(0, DB::table('jobs')->where('queue', 'activitypub-outbox')->count());
        $this->assertGreaterThan(0, DB::table('jobs')->where('queue', 'maintenance')->count());

        // Process only outbox queue
        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'activitypub-outbox',
            '--stop-when-empty' => true,
        ]);

        // Outbox should be empty, maintenance still has jobs
        $this->assertEquals(0, DB::table('jobs')->where('queue', 'activitypub-outbox')->count());
        $this->assertGreaterThan(0, DB::table('jobs')->where('queue', 'maintenance')->count());

        $activity->delete();
    }

    #[Test]
    public function it_respects_max_jobs_limit()
    {
        Http::fake([
            'external.com/*' => Http::response(['ok' => true], 200),
        ]);

        // Create multiple activities (use save() to trigger ActivityPubListener and queue jobs)
        for ($i = 1; $i <= 5; $i++) {
            $activity = Entry::make()
                ->collection('activities')
                ->slug("test-max-jobs-activity-$i")
                ->data([
                    'type' => 'Create',
                    'actor' => [$this->localActor->id()],
                    'is_internal' => true,
                    'activitypub_json' => json_encode([
                        'type' => 'Create',
                        'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                        'cc' => [$this->localActor->url() . '/followers'],
                    ]),
                ])
                ->published(true);
            $activity->save();
        }

        // Should have 5 jobs
        $this->assertEquals(5, DB::table('jobs')->where('queue', 'activitypub-outbox')->count());

        // Process with max-jobs=2
        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'activitypub-outbox',
            '--max-jobs' => 2,
        ]);

        // Should have 3 jobs remaining
        $this->assertEquals(3, DB::table('jobs')->where('queue', 'activitypub-outbox')->count());
    }

    #[Test]
    public function it_can_monitor_queue_status()
    {
        // Queue various jobs (don't call onQueue since constructor already does it)
        RecalculateActivityPubCounts::dispatch();
        CleanOldActivityPubData::dispatch();

        $activity = Entry::make()
            ->collection('activities')
            ->slug('test-monitor-activity')
            ->data([
                'type' => 'Create',
                'actor' => [$this->localActor->id()],
                'is_internal' => true,
                'activitypub_json' => json_encode([
                    'type' => 'Create',
                    'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                    'cc' => [$this->localActor->url() . '/followers'],
                ]),
            ])
            ->published(true);
        $activity->save();

        // Check queue depths for ActivityPub jobs only
        // (Statamic creates search index jobs on default queue which we should ignore)
        $outboxCount = DB::table('jobs')->where('queue', 'activitypub-outbox')->count();
        $maintenanceCount = DB::table('jobs')->where('queue', 'maintenance')->count();

        $this->assertEquals(1, $outboxCount);
        $this->assertEquals(2, $maintenanceCount);

        $activity->delete();
    }
}
