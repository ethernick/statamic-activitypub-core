<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Ethernick\ActivityPubCore\Jobs\SendToInbox;
use Ethernick\ActivityPubCore\Jobs\SendActivityPubPost;

class RateLimitingTest extends TestCase
{
    protected $actor;

    protected function setUp(): void
    {
        parent::setUp();

        // Create actor with keys
        $this->actor = Entry::make()
            ->collection('actors')
            ->slug('test-rate-limit-actor')
            ->data([
                'title' => 'Rate Limit Test Actor',
                'is_internal' => true,
                'activitypub_id' => 'https://localhost/users/test',
                'private_key' => $this->generatePrivateKey(),
                'public_key' => 'dummy-public-key',
            ])
            ->published(true);
        $this->actor->save();

        // Clear rate limiters before each test
        RateLimiter::clear('activitypub-outbox:example.com');
    }

    protected function tearDown(): void
    {
        if ($this->actor) {
            $this->actor->delete();
        }

        RateLimiter::clear('activitypub-outbox:example.com');

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
    public function it_respects_rate_limits_in_send_to_inbox()
    {
        Http::fake([
            'example.com/*' => Http::response(['ok' => true], 200),
        ]);

        // Set a low rate limit for testing
        config(['activitypub.rate_limits.per_minute' => 2]);

        $targetUrl = 'https://example.com/inbox';
        $payload = ['type' => 'Create', 'object' => 'test'];

        // First request should succeed
        $job1 = new SendToInbox($targetUrl, $this->actor->id(), $payload);
        $job1->handle();

        // Second request should succeed
        $job2 = new SendToInbox($targetUrl, $this->actor->id(), $payload);
        $job2->handle();

        // Third request should hit rate limit and throw exception
        $job3 = new SendToInbox($targetUrl, $this->actor->id(), $payload);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Rate limit exceeded/');

        $job3->handle();
    }

    #[Test]
    public function it_can_disable_rate_limiting()
    {
        Http::fake([
            'example.com/*' => Http::response(['ok' => true], 200),
        ]);

        // Disable rate limiting
        config(['activitypub.rate_limits.enabled' => false]);
        config(['activitypub.rate_limits.per_minute' => 1]);

        $targetUrl = 'https://example.com/inbox';
        $payload = ['type' => 'Create', 'object' => 'test'];

        // All requests should succeed even though limit is 1/min
        $job1 = new SendToInbox($targetUrl, $this->actor->id(), $payload);
        $job1->handle();

        $job2 = new SendToInbox($targetUrl, $this->actor->id(), $payload);
        $job2->handle();

        $job3 = new SendToInbox($targetUrl, $this->actor->id(), $payload);
        $job3->handle();

        // No exception should be thrown
        $this->assertTrue(true);
    }

    #[Test]
    public function it_applies_rate_limits_per_domain()
    {
        Http::fake([
            'example.com/*' => Http::response(['ok' => true], 200),
            'another.com/*' => Http::response(['ok' => true], 200),
        ]);

        config(['activitypub.rate_limits.per_minute' => 1]);

        // First domain hits rate limit
        $job1 = new SendToInbox('https://example.com/inbox', $this->actor->id(), ['type' => 'Create']);
        $job1->handle();

        // Second request to same domain should fail
        $job2 = new SendToInbox('https://example.com/inbox', $this->actor->id(), ['type' => 'Create']);
        $this->expectException(\RuntimeException::class);
        $job2->handle();
    }

    #[Test]
    public function it_skips_rate_limited_requests_in_concurrent_sending()
    {
        Http::fake([
            'example.com/*' => Http::response(['ok' => true], 200),
        ]);

        // Set very low rate limit
        config(['activitypub.rate_limits.per_minute' => 2]);
        config(['activitypub.http.max_concurrent' => 10]);

        // Create multiple followers on same domain
        $followers = [];
        for ($i = 1; $i <= 5; $i++) {
            $follower = Entry::make()
                ->collection('actors')
                ->slug("follower-{$i}")
                ->data([
                    'title' => "Follower {$i}",
                    'is_internal' => false,
                    'activitypub_id' => "https://example.com/users/follower{$i}",
                    'inbox_url' => "https://example.com/users/follower{$i}/inbox",
                    'following_actors' => [$this->actor->id()],
                ])
                ->published(true);
            $follower->save();
            $followers[] = $follower;
        }

        // Update actor with followers
        $this->actor->set('followed_by_actors', array_map(fn($f) => $f->id(), $followers));
        $this->actor->save();

        // Create activity
        $activity = Entry::make()
            ->collection('activities')
            ->slug('test-rate-limit-activity')
            ->data([
                'type' => 'Create',
                'actor' => [$this->actor->id()],
                'object' => ['type' => 'Note', 'content' => 'Test'],
                'is_internal' => true,
                'activitypub_json' => json_encode([
                    'type' => 'Create',
                    'actor' => $this->actor->get('activitypub_id'),
                    'object' => ['type' => 'Note', 'content' => 'Test'],
                    'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                    'cc' => [$this->actor->get('activitypub_id') . '/followers'],
                ]),
            ])
            ->published(true);
        $activity->save();

        // Send should complete without throwing, but some requests will be skipped
        $job = new SendActivityPubPost($activity->id());
        $job->handle();

        // Cleanup
        $activity->delete();
        foreach ($followers as $follower) {
            $follower->delete();
        }

        // Job should complete successfully
        $this->assertTrue(true);
    }
}
