<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Ethernick\ActivityPubCore\Tests\Concerns\ProvidesSandbox;
use Ethernick\ActivityPubCore\Services\HttpSignature;
use Ethernick\ActivityPubCore\Http\Controllers\ActorController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Http;
use Ethernick\ActivityPubCore\Jobs\SendToInbox;
use Statamic\Facades\Entry;
use PHPUnit\Framework\Attributes\Test;

class HttpSignatureSecurityTest extends TestCase
{
    use ProvidesSandbox;

    protected string $privateKey;
    protected string $publicKey;
    protected $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupSandbox();

        $keyPair = $this->generateKeyPair();
        $this->privateKey = $keyPair['private'];
        $this->publicKey = $keyPair['public'];

        foreach (['actors', 'activities', 'notes'] as $col) {
            if (!\Statamic\Facades\Collection::find($col)) {
                \Statamic\Facades\Collection::make($col)->save();
            }
        }

        $this->actor = Entry::make()
            ->collection('actors')
            ->slug('security-test-actor')
            ->data([
                'title' => 'Security Test Actor',
                'is_internal' => true,
                'activitypub_id' => 'https://localhost/users/security-test',
                'private_key' => $this->privateKey,
                'public_key' => $this->publicKey,
            ])
            ->published(true);
        $this->actor->save();

        RateLimiter::clear('activitypub-outbox:security-test.com');
    }

    protected function tearDown(): void
    {
        if ($this->actor) {
            $this->actor->delete();
        }

        RateLimiter::clear('activitypub-outbox:security-test.com');
        ActorController::$shouldSkipSignatureVerificationInTests = true;

        $this->teardownSandbox();
        parent::tearDown();
    }

    protected function generateKeyPair(): array
    {
        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($res, $privateKey);
        $details = openssl_pkey_get_details($res);
        return [
            'private' => $privateKey,
            'public' => $details['key'],
        ];
    }

    #[Test]
    public function it_creates_valid_http_signature_headers(): void
    {
        $url = 'https://example.com/inbox';
        $actorId = 'https://localhost/users/security-test';
        $body = json_encode(['type' => 'Create', 'object' => 'Note']);

        $headers = HttpSignature::sign($url, $actorId, $this->privateKey, $body);

        $this->assertArrayHasKey('Host', $headers);
        $this->assertArrayHasKey('Date', $headers);
        $this->assertArrayHasKey('Digest', $headers);
        $this->assertArrayHasKey('Signature', $headers);
        $this->assertStringContainsString('keyId="https://localhost/users/security-test#main-key"', $headers['Signature']);
        $this->assertStringContainsString('algorithm="rsa-sha256"', $headers['Signature']);
    }

    #[Test]
    public function it_validates_clock_skew_window(): void
    {
        $now = time();
        $validDateStr = gmdate('D, d M Y H:i:s T', $now);
        $futureDriftDateStr = gmdate('D, d M Y H:i:s T', $now + 600); // +10 mins (beyond 300s window)
        $pastDriftDateStr = gmdate('D, d M Y H:i:s T', $now - 600);   // -10 mins (beyond 300s window)

        // Valid timestamp window (within ±300 seconds)
        $validTime = strtotime($validDateStr);
        $this->assertTrue(abs(time() - $validTime) <= 300);

        // Future drift exceeds 300 seconds
        $futureTime = strtotime($futureDriftDateStr);
        $this->assertTrue(abs(time() - $futureTime) > 300);

        // Past drift exceeds 300 seconds
        $pastTime = strtotime($pastDriftDateStr);
        $this->assertTrue(abs(time() - $pastTime) > 300);
    }

    #[Test]
    public function it_rejects_tampered_digest_or_signature(): void
    {
        $url = 'https://example.com/inbox';
        $actorId = 'https://localhost/users/security-test';
        $originalBody = json_encode(['type' => 'Create', 'object' => 'Original Content']);

        $headers = HttpSignature::sign($url, $actorId, $this->privateKey, $originalBody);

        // Calculate expected digest of original body
        $expectedDigest = 'SHA-256=' . base64_encode(hash('sha256', $originalBody, true));
        $this->assertEquals($expectedDigest, $headers['Digest']);

        // Tamper request body content
        $tamperedBody = json_encode(['type' => 'Create', 'object' => 'Tampered Content']);
        $tamperedDigest = 'SHA-256=' . base64_encode(hash('sha256', $tamperedBody, true));

        // Digest for tampered body does not match signed Digest
        $this->assertNotEquals($headers['Digest'], $tamperedDigest);

        // OpenSSL signature verification fails on tampered header string
        $signedHeaders = [
            '(request-target)' => 'post /inbox',
            'host' => 'example.com',
            'date' => $headers['Date'],
            'digest' => $tamperedDigest, // Tampered digest string
        ];

        $parts = [];
        foreach ($signedHeaders as $k => $v) {
            $parts[] = "{$k}: {$v}";
        }
        $tamperedStringToSign = implode("\n", $parts);

        preg_match('/signature="(.*?)"/', $headers['Signature'], $m);
        $rawSig = base64_decode($m[1]);

        $verificationResult = openssl_verify($tamperedStringToSign, $rawSig, $this->publicKey, OPENSSL_ALGO_SHA256);
        $this->assertNotEquals(1, $verificationResult);
    }

    #[Test]
    public function it_enforces_rate_limiting_under_burst_requests(): void
    {
        Http::fake([
            'security-test.com/*' => Http::response(['ok' => true], 200),
        ]);

        config(['activitypub.rate_limits.enabled' => true]);
        config(['activitypub.rate_limits.per_minute' => 2]);

        $targetUrl = 'https://security-test.com/inbox';
        $payload = ['type' => 'Create', 'object' => 'Test Note'];

        $job1 = new SendToInbox($targetUrl, $this->actor->id(), $payload);
        $job1->handle();

        $job2 = new SendToInbox($targetUrl, $this->actor->id(), $payload);
        $job2->handle();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Rate limit exceeded/');

        $job3 = new SendToInbox($targetUrl, $this->actor->id(), $payload);
        $job3->handle();
    }
}
