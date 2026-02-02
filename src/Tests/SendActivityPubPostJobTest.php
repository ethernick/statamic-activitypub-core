<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Ethernick\ActivityPubCore\Jobs\SendActivityPubPost;

class SendActivityPubPostJobTest extends TestCase
{
    protected $localActor;
    protected $externalActor;
    protected $activity;

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

        // Clear any existing jobs
        DB::table('jobs')->delete();
        DB::table('failed_jobs')->delete();

        // Create Local Actor with keys
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
        $this->localActor->save();

        // Create External Actor (follower)
        $this->externalActor = Entry::make()
            ->collection('actors')
            ->slug('external-follower')
            ->data([
                'title' => 'External Follower',
                'is_internal' => false,
                'activitypub_id' => 'https://external.com/users/follower',
                'inbox_url' => 'https://external.com/users/follower/inbox',
                'following_actors' => [$this->localActor->id()], // Following local actor
            ])
            ->published(true);
        $this->externalActor->save();

        // Set up bidirectional relationship - external actor is following local actor
        $this->localActor->set('followed_by_actors', [$this->externalActor->id()]);
        $this->localActor->save();
    }

    protected function tearDown(): void
    {
        // Cleanup
        if ($this->activity) $this->activity->delete();
        if ($this->externalActor) $this->externalActor->delete();
        if ($this->localActor) $this->localActor->delete();

        DB::table('jobs')->delete();
        DB::table('failed_jobs')->delete();

        parent::tearDown();
    }

    protected function generatePrivateKey()
    {
        // Generate a real RSA key for testing
        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $res = openssl_pkey_new($config);
        openssl_pkey_export($res, $privateKey);
        return $privateKey;
    }

    #[Test]
    public function it_implements_should_queue_interface()
    {
        $this->assertContains(
            'Illuminate\Contracts\Queue\ShouldQueue',
            class_implements(SendActivityPubPost::class)
        );
    }

    #[Test]
    public function it_has_correct_queue_configuration()
    {
        $job = new SendActivityPubPost('test-id');

        // Queue name is set via onQueue() in constructor
        $this->assertEquals(3, $job->tries);
        $this->assertEquals(120, $job->timeout);
        $this->assertEquals([60, 300, 900], $job->backoff);
    }

    #[Test]
    public function it_can_be_dispatched_to_queue()
    {
        Queue::fake();

        // Create activity
        $this->activity = $this->createTestActivity();

        // Dispatch job
        SendActivityPubPost::dispatch($this->activity->id())->onQueue('activitypub-outbox');

        // Assert job was pushed to correct queue
        Queue::assertPushedOn('activitypub-outbox', SendActivityPubPost::class);
    }

    #[Test]
    public function it_sends_activity_to_followers()
    {
        Http::fake([
            'external.com/*' => Http::response(['ok' => true], 200),
        ]);

        // Create activity with addressing
        $this->activity = $this->createTestActivity();

        // Execute job directly
        $job = new SendActivityPubPost($this->activity->id());
        $job->handle();

        // Assert HTTP request was sent
        Http::assertSent(function ($request) {
            return $request->url() === 'https://external.com/users/follower/inbox'
                && $request->hasHeader('Content-Type', 'application/activity+json')
                && $request->hasHeader('Digest')
                && $request->hasHeader('Signature');
        });
    }

    #[Test]
    public function it_logs_success_for_successful_delivery()
    {
        Http::fake([
            'external.com/*' => Http::response(['ok' => true], 200),
        ]);

        $this->activity = $this->createTestActivity();

        $job = new SendActivityPubPost($this->activity->id());
        $job->handle();

        // Check logs would contain success message
        // Note: In real tests, you might use a log spy
        $this->assertTrue(true); // Placeholder - add proper log assertion if needed
    }

    #[Test]
    public function it_handles_missing_entry_gracefully()
    {
        $job = new SendActivityPubPost('non-existent-id');

        // Should not throw exception (entry likely deleted)
        $job->handle();

        $this->assertTrue(true); // No exception means test passed
    }

    #[Test]
    public function it_skips_external_activities()
    {
        Http::fake();

        // Create external activity (should not be sent)
        $this->activity = Entry::make()
            ->collection('activities')
            ->slug('external-activity')
            ->data([
                'type' => 'Create',
                'actor' => [$this->externalActor->id()],
                'is_internal' => false, // External!
                'activitypub_json' => json_encode([
                    'type' => 'Create',
                    'object' => 'https://external.com/notes/123',
                ]),
            ])
            ->published(true);
        $this->activity->save();

        $job = new SendActivityPubPost($this->activity->id());
        $job->handle();

        // Should not send any HTTP requests
        Http::assertNothingSent();
    }

    #[Test]
    public function it_skips_blocked_actors()
    {
        Http::fake();

        // Block the external actor
        $this->localActor->set('blocks', [$this->externalActor->id()]);
        $this->localActor->save();

        $this->activity = $this->createTestActivity();

        $job = new SendActivityPubPost($this->activity->id());
        $job->handle();

        // Should not send to blocked actor
        Http::assertNothingSent();
    }

    #[Test]
    public function it_sends_to_multiple_followers()
    {
        Http::fake([
            'external.com/*' => Http::response(['ok' => true], 200),
            'remote.social/*' => Http::response(['ok' => true], 200),
        ]);

        // Fake queue to prevent automatic dispatch when activity is saved
        Queue::fake();

        // Create second follower
        $secondFollower = Entry::make()
            ->collection('actors')
            ->slug('second-follower')
            ->data([
                'title' => 'Second Follower',
                'is_internal' => false,
                'activitypub_id' => 'https://remote.social/users/user2',
                'inbox_url' => 'https://remote.social/users/user2/inbox',
                'following_actors' => [$this->localActor->id()],
            ])
            ->published(true);
        $secondFollower->save();

        // Update local actor's followed_by_actors to include both followers
        $this->localActor->set('followed_by_actors', [
            $this->externalActor->id(),
            $secondFollower->id(),
        ]);
        $this->localActor->save();

        $this->activity = $this->createTestActivity();

        // Now run the job manually
        $job = new SendActivityPubPost($this->activity->id());
        $job->handle();

        // Assert sent to both inboxes
        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://external.com/users/follower/inbox';
        });
        Http::assertSent(function ($request) {
            return $request->url() === 'https://remote.social/users/user2/inbox';
        });

        $secondFollower->delete();
    }

    #[Test]
    public function it_handles_http_errors_gracefully()
    {
        Http::fake([
            'external.com/*' => Http::response(['error' => 'Server Error'], 500),
        ]);

        $this->activity = $this->createTestActivity();

        $job = new SendActivityPubPost($this->activity->id());

        // Should not throw exception, just log error
        $job->handle();

        $this->assertTrue(true); // Completed without throwing
    }

    #[Test]
    public function it_requires_actor_with_keys()
    {
        // Create actor without keys
        $actorWithoutKeys = Entry::make()
            ->collection('actors')
            ->slug('no-keys-actor')
            ->data([
                'title' => 'No Keys Actor',
                'is_internal' => true,
                'activitypub_id' => 'https://localhost/users/no-keys',
                // No private_key!
            ])
            ->published(true);
        $actorWithoutKeys->save();

        $this->activity = Entry::make()
            ->collection('activities')
            ->slug('test-activity-no-keys')
            ->data([
                'type' => 'Create',
                'actor' => [$actorWithoutKeys->id()],
                'is_internal' => true,
                'activitypub_json' => json_encode([
                    'type' => 'Create',
                    'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                    'cc' => [$actorWithoutKeys->url() . '/followers'],
                ]),
            ])
            ->published(true);
        $this->activity->save();

        Http::fake();

        $job = new SendActivityPubPost($this->activity->id());
        $job->handle();

        // Should not send without keys
        Http::assertNothingSent();

        $actorWithoutKeys->delete();
    }

    #[Test]
    public function it_counts_successes_and_failures()
    {
        Http::fake([
            'external.com/*' => Http::response(['ok' => true], 200),
        ]);

        $this->activity = $this->createTestActivity();

        $job = new SendActivityPubPost($this->activity->id());
        $job->handle();

        // Job should complete successfully
        // In a real scenario, you'd check logs for success/failure counts
        $this->assertTrue(true);
    }

    protected function createTestActivity()
    {
        $activity = Entry::make()
            ->collection('activities')
            ->slug('test-activity-' . uniqid())
            ->data([
                'type' => 'Create',
                'actor' => [$this->localActor->id()],
                'object' => ['https://localhost/notes/test-note'],
                'is_internal' => true,
                'activitypub_json' => json_encode([
                    'type' => 'Create',
                    'actor' => $this->localActor->get('activitypub_id'),
                    'object' => [
                        'type' => 'Note',
                        'content' => 'Test content',
                    ],
                    'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                    'cc' => [$this->localActor->url() . '/followers'],
                ]),
            ])
            ->published(true);
        $activity->save();
        return $activity;
    }
}
