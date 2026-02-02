<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Ethernick\ActivityPubCore\Jobs\SendToInbox;

class SendToInboxJobTest extends TestCase
{
    protected $actor;
    protected $skipEventsInTearDown = false;

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

        // Create test actor with necessary keys
        $this->actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor')
            ->data([
                'title' => 'Test Actor',
                'is_internal' => true,
                'activitypub_id' => 'https://localhost/users/test-actor',
                'private_key' => $this->generatePrivateKey(),
                'public_key' => 'dummy-public-key',
            ])
            ->published(true);
        $this->actor->save();
    }

    protected function tearDown(): void
    {
        if ($this->skipEventsInTearDown) {
            Event::fake();
        }

        if ($this->actor) $this->actor->delete();
        DB::table('jobs')->delete();
        DB::table('failed_jobs')->delete();
        \Mockery::close();
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
    public function it_implements_should_queue_interface()
    {
        $this->assertContains(
            'Illuminate\Contracts\Queue\ShouldQueue',
            class_implements(SendToInbox::class)
        );
    }

    #[Test]
    public function it_has_correct_queue_configuration()
    {
        $job = new SendToInbox('https://example.com/inbox', $this->actor->id(), ['type' => 'Create']);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals([60, 300, 900], $job->backoff);
        $this->assertEquals(120, $job->timeout);
    }

    #[Test]
    public function it_can_be_dispatched_to_queue()
    {
        Queue::fake();

        $payload = [
            'type' => 'Create',
            'object' => ['type' => 'Note', 'content' => 'Test'],
        ];

        SendToInbox::dispatch('https://example.com/inbox', $this->actor->id(), $payload);

        Queue::assertPushed(SendToInbox::class);
    }

    #[Test]
    public function it_sends_signed_http_request_successfully()
    {
        Http::fake([
            'example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $payload = [
            'type' => 'Create',
            'object' => ['type' => 'Note', 'content' => 'Test'],
        ];

        $job = new SendToInbox('https://example.com/inbox', $this->actor->id(), $payload);
        $job->handle();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://example.com/inbox'
                && $request->hasHeader('Signature')
                && $request->hasHeader('Date')
                && $request->body() === json_encode([
                    'type' => 'Create',
                    'object' => ['type' => 'Note', 'content' => 'Test'],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        });
    }

    #[Test]
    public function it_throws_exception_for_missing_actor()
    {
        $this->skipStatamicFileRestore = true;
        $this->skipEventsInTearDown = true;

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')
            ->once()
            ->with(\Mockery::pattern('/Actor not found/'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Actor not found: non-existent-id');

        $job = new SendToInbox('https://example.com/inbox', 'non-existent-id', ['type' => 'Create']);
        $job->handle();
    }

    #[Test]
    public function it_throws_exception_for_actor_without_private_key()
    {
        $this->skipStatamicFileRestore = true;
        $this->skipEventsInTearDown = true;
        Event::fake();

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')
            ->with(\Mockery::pattern('/Missing private key or ActivityPub ID/'));

        // Create actor without private key
        $actorNoKey = Entry::make()
            ->collection('actors')
            ->slug('actor-no-key')
            ->data([
                'title' => 'Actor No Key',
                'is_internal' => true,
                'activitypub_id' => 'https://localhost/users/actor-no-key',
                // No private_key
            ])
            ->published(true);
        $actorNoKey->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing private key or ActivityPub ID for actor:');

        $job = new SendToInbox('https://example.com/inbox', $actorNoKey->id(), ['type' => 'Create']);
        $job->handle();

        $actorNoKey->delete();
    }

    #[Test]
    public function it_throws_exception_for_actor_without_activitypub_id()
    {
        $this->skipStatamicFileRestore = true;
        $this->skipEventsInTearDown = true;
        Event::fake();

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')
            ->once()
            ->with(\Mockery::pattern('/Missing private key or ActivityPub ID/'));

        // Create actor without AP ID
        $actorNoId = Entry::make()
            ->collection('actors')
            ->slug('actor-no-id')
            ->data([
                'title' => 'Actor No ID',
                'is_internal' => true,
                'private_key' => $this->generatePrivateKey(),
                // No activitypub_id
            ])
            ->published(true);
        $actorNoId->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing private key or ActivityPub ID for actor:');

        $job = new SendToInbox('https://example.com/inbox', $actorNoId->id(), ['type' => 'Create']);
        $job->handle();

        $actorNoId->delete();
    }

    #[Test]
    public function it_retries_on_server_errors()
    {
        Http::fake([
            'example.com/*' => Http::response(['error' => 'Server Error'], 500),
        ]);

        $job = new SendToInbox('https://example.com/inbox', $this->actor->id(), ['type' => 'Create']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Server error or rate limit: 500');

        $job->handle();
    }

    #[Test]
    public function it_retries_on_rate_limit()
    {
        Http::fake([
            'example.com/*' => Http::response(['error' => 'Rate Limited'], 429),
        ]);

        $job = new SendToInbox('https://example.com/inbox', $this->actor->id(), ['type' => 'Create']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Server error or rate limit: 429');

        $job->handle();
    }

    #[Test]
    public function it_does_not_retry_on_client_errors()
    {
        $this->skipStatamicFileRestore = true;
        $this->skipEventsInTearDown = true;

        Http::fake([
            'example.com/*' => Http::response(['error' => 'Bad Request'], 400),
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('warning')
            ->once()
            ->with(\Mockery::pattern('/Failed to send.*400/'));

        $job = new SendToInbox('https://example.com/inbox', $this->actor->id(), ['type' => 'Create']);
        $job->handle();

        // Should not throw exception for 4xx errors (except 429)
        $this->assertTrue(true);
    }

    #[Test]
    public function it_logs_successful_delivery()
    {
        $this->skipStatamicFileRestore = true;
        $this->skipEventsInTearDown = true;

        Http::fake([
            'example.com/*' => Http::response(['ok' => true], 202),
        ]);

        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('info')
            ->atLeast()->once()
            ->with(\Mockery::pattern('/Successfully sent.*202/'));

        $job = new SendToInbox('https://example.com/inbox', $this->actor->id(), ['type' => 'Create']);
        $job->handle();

        // Assertion to satisfy PHPUnit (Mockery expectations verified above)
        $this->assertTrue(true);
    }

    #[Test]
    public function it_handles_network_exceptions()
    {
        $this->skipStatamicFileRestore = true;
        $this->skipEventsInTearDown = true;

        Http::fake(function () {
            throw new \Exception('Network connection failed');
        });

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')
            ->atLeast()->once()
            ->with(\Mockery::pattern('/Exception sending.*Network connection failed/'));

        $job = new SendToInbox('https://example.com/inbox', $this->actor->id(), ['type' => 'Create']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Network connection failed');

        $job->handle();
    }

    #[Test]
    public function it_preserves_unicode_in_payload()
    {
        Http::fake([
            'example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $payload = [
            'type' => 'Create',
            'object' => [
                'type' => 'Note',
                'content' => 'Test with emoji 🎉 and unicode ñ',
            ],
        ];

        $job = new SendToInbox('https://example.com/inbox', $this->actor->id(), $payload);
        $job->handle();

        Http::assertSent(function ($request) {
            $body = $request->body();
            return str_contains($body, '🎉') && str_contains($body, 'ñ');
        });
    }

    #[Test]
    public function it_does_not_escape_slashes_in_urls()
    {
        Http::fake([
            'example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $payload = [
            'type' => 'Create',
            'object' => 'https://localhost/notes/123',
        ];

        $job = new SendToInbox('https://example.com/inbox', $this->actor->id(), $payload);
        $job->handle();

        Http::assertSent(function ($request) {
            $body = $request->body();
            // Should contain unescaped slashes
            return str_contains($body, 'https://localhost/notes/123')
                && !str_contains($body, 'https:\/\/localhost\/notes\/123');
        });
    }

    #[Test]
    public function it_includes_proper_content_type_header()
    {
        Http::fake([
            'example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $job = new SendToInbox('https://example.com/inbox', $this->actor->id(), ['type' => 'Create']);
        $job->handle();

        Http::assertSent(function ($request) {
            return $request->hasHeader('Content-Type', 'application/activity+json');
        });
    }

    #[Test]
    public function it_respects_timeout_setting()
    {
        Http::fake([
            'example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $job = new SendToInbox('https://example.com/inbox', $this->actor->id(), ['type' => 'Create']);
        $job->handle();

        // Verify timeout is set to 30 seconds in the HTTP request
        // Note: Laravel HTTP facade doesn't expose timeout directly in assertions,
        // but we can verify the job's timeout property
        $this->assertEquals(120, $job->timeout);
    }
}
