<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Statamic\Facades\Entry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class SendToInbox implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;
    public array $backoff;
    public int $timeout;

    protected string $targetUrl;
    protected string $actorId;
    protected array $payload;

    /**
     * Create a new job instance.
     */
    public function __construct(string $targetUrl, string $actorId, array $payload)
    {
        $this->targetUrl = $targetUrl;
        $this->actorId = $actorId;
        $this->payload = $payload;

        // Load configuration values
        $this->tries = config('activitypub.queue.outbox.tries', 3);
        $this->backoff = config('activitypub.queue.outbox.backoff', [60, 300, 900]);
        $this->timeout = config('activitypub.queue.outbox.timeout', 120);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $actor = Entry::find($this->actorId);

        if (!$actor) {
            Log::error("SendToInbox: Actor not found: {$this->actorId}");
            throw new \RuntimeException("Actor not found: {$this->actorId}");
        }

        $privateKey = $actor->get('private_key');
        $actorUrl = $actor->get('activitypub_id');

        if (!$privateKey || !$actorUrl) {
            Log::error("SendToInbox: Missing private key or ActivityPub ID for actor: {$this->actorId}");
            throw new \RuntimeException("Missing private key or ActivityPub ID for actor: {$this->actorId}");
        }

        // Apply rate limiting per remote domain (if enabled)
        $rateLimitsEnabled = config('activitypub.rate_limits.enabled', true);

        if ($rateLimitsEnabled) {
            $domain = parse_url($this->targetUrl, PHP_URL_HOST);
            if (!$domain) {
                Log::error("SendToInbox: Invalid target URL (no host): {$this->targetUrl}");
                throw new \RuntimeException("Invalid target URL: {$this->targetUrl}");
            }

            $rateLimitKey = 'activitypub-outbox:' . $domain;
            $maxAttempts = config('activitypub.rate_limits.per_minute', 30);
            $decaySeconds = 60;

            // Attempt to acquire rate limit
            $executed = RateLimiter::attempt(
                $rateLimitKey,
                $maxAttempts,
                function() use ($actor, $privateKey, $actorUrl) {
                    $this->sendRequest($actor, $privateKey, $actorUrl);
                },
                $decaySeconds
            );

            if (!$executed) {
                $availableIn = RateLimiter::availableIn($rateLimitKey);
                Log::warning("SendToInbox: Rate limit exceeded for {$domain}. Available in {$availableIn}s. Will retry.");
                throw new \RuntimeException("Rate limit exceeded for {$domain}. Retrying in {$availableIn}s.");
            }
        } else {
            // Rate limiting disabled - send directly
            $this->sendRequest($actor, $privateKey, $actorUrl);
        }
    }

    /**
     * Send the HTTP request to the target inbox.
     */
    protected function sendRequest($actor, string $privateKey, string $actorUrl): void
    {
        $jsonBody = json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $headers = \Ethernick\ActivityPubCore\Services\HttpSignature::sign(
                $this->targetUrl,
                $actorUrl,
                $privateKey,
                $jsonBody
            );

            if (empty($headers)) {
                Log::error("SendToInbox: Failed to sign request for {$this->targetUrl}");
                throw new \Exception("Failed to sign request");
            }

            $response = Http::withHeaders($headers)
                ->withBody($jsonBody, 'application/activity+json')
                ->timeout(config('activitypub.http.timeout', 30))
                ->post($this->targetUrl);

            if ($response->successful()) {
                Log::info("SendToInbox: Successfully sent to {$this->targetUrl} (status: {$response->status()})");
            } else {
                $body = $response->body();
                Log::warning("SendToInbox: Failed to send to {$this->targetUrl} (status: {$response->status()}). Response: {$body}");

                // Only throw exception if we want to retry
                if ($response->status() >= 500 || $response->status() === 429) {
                    throw new \Exception("Server error or rate limit: {$response->status()}");
                }
            }
        } catch (\Exception $e) {
            Log::error("SendToInbox: Exception sending to {$this->targetUrl}: " . $e->getMessage());
            throw $e; // Re-throw to trigger retry
        }
    }
}
