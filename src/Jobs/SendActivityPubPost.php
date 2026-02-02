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

class SendActivityPubPost implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;
    public array $backoff;
    public int $timeout;

    protected string $entryId;

    /**
     * Create a new job instance.
     */
    public function __construct(string $entryId)
    {
        $this->entryId = $entryId;

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
        $entry = Entry::find($this->entryId);

        if (!$entry) {
            Log::error("SendActivityPubPost: Entry not found with ID: {$this->entryId}");
            return; // Entry likely deleted - retrying won't help
        }

        // Only process internal items (double check)
        if ($entry->get('is_internal') === false) {
            return;
        }

        $apJson = $entry->get('activitypub_json');
        if (!$apJson) {
            Log::warning("SendActivityPubPost: No ActivityPub JSON for entry: {$this->entryId}");
            return;
        }

        $data = json_decode($apJson, true);
        if (!$data) {
            Log::error("SendActivityPubPost: Invalid JSON for entry: {$this->entryId}");
            return;
        }

        // Get Actor
        $actorId = $entry->get('actor');
        if (is_array($actorId))
            $actorId = $actorId[0] ?? null;

        if (!$actorId) {
            Log::error("SendActivityPubPost: No actor found for entry: {$this->entryId}");
            return; // Entry orphaned - retrying won't help
        }

        $actor = Entry::find($actorId);
        if (!$actor) {
            Log::error("SendActivityPubPost: Actor not found: {$actorId}");
            return; // Actor deleted - retrying won't help
        }

        $blocks = $actor->get('blocks', []) ?: [];

        // Determine Audience
        $recipients = [];
        if (isset($data['to']))
            $recipients = array_merge($recipients, (array) $data['to']);
        if (isset($data['cc']))
            $recipients = array_merge($recipients, (array) $data['cc']);
        $recipients = array_unique($recipients);

        $inboxes = [];

        foreach ($recipients as $recipient) {
            // Skip Public
            if ($recipient === 'https://www.w3.org/ns/activitystreams#Public' || $recipient === 'as:Public') {
                continue;
            }

            // Handle Followers Collection
            if (str_ends_with($recipient, '/followers')) {
                Log::info("SendActivityPubPost: Resolving followers for coverage of $recipient");

                // OPTIMIZED: Use the inverse relationship (followed_by_actors) instead of querying all actors
                $followerIds = $actor->get('followed_by_actors', []);
                if (!is_array($followerIds)) {
                    $followerIds = $followerIds ? [$followerIds] : [];
                }

                // Filter out blocked actors
                $followerIds = array_diff($followerIds, $blocks);

                // OPTIMIZED: Batch load followers in a single query instead of N+1
                // Previous: collect($ids)->map(fn($id) => Entry::find($id)) = N queries
                // Now: Entry::whereInId($ids) = 1 query
                $followers = !empty($followerIds) ? Entry::whereInId($followerIds) : collect([]);

                foreach ($followers as $follower) {
                    $target = $follower->get('shared_inbox_url') ?? $follower->get('inbox_url');
                    if ($target)
                        $inboxes[$target] = true;
                }
                continue;
            }

            // Handle Direct Actor Addressing (Mentions)
            // Attempt to find by 'url' (AP ID)
            $targetActor = Entry::query()
                ->where('collection', 'actors')
                ->where('url', $recipient)
                ->first();

            if (!$targetActor) {
                // Try 'activitypub_id' if implemented
                $targetActor = Entry::query()
                    ->where('collection', 'actors')
                    ->where('activitypub_id', $recipient)
                    ->first();
            }

            if ($targetActor) {
                $target = $targetActor->get('shared_inbox_url') ?? $targetActor->get('inbox_url');
                if ($target) {
                    if (in_array($targetActor->id(), $blocks)) {
                        Log::info("SendActivityPubPost: Skipping blocked actor $recipient");
                        continue;
                    }
                    $inboxes[$target] = true;
                    Log::info("SendActivityPubPost: Added inbox for mentioned actor $recipient");
                }
            } else {
                Log::warning("SendActivityPubPost: Could not resolve inbox for recipient: $recipient (Not found locally)");
            }
        }

        $uniqueInboxes = array_keys($inboxes);

        if (empty($uniqueInboxes)) {
            Log::info("SendActivityPubPost: No recipients found to send to.");
            return;
        }

        Log::info("SendActivityPubPost: Sending to " . count($uniqueInboxes) . " unique inboxes.");

        $privateKey = $actor->get('private_key');
        $actorUrl = $actor->get('activitypub_id');

        if (!$privateKey || !$actorUrl) {
            Log::error("SendActivityPubPost: Missing private key or ActivityPub ID for actor: {$actorId}");
            throw new \RuntimeException("Missing private key or ActivityPub ID for actor: {$actorId}");
        }

        $jsonBody = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $successCount = 0;
        $failureCount = 0;

        // Get max concurrent requests from config (default 10)
        $maxConcurrent = config('activitypub.http.max_concurrent', 10);

        // If we have many inboxes, chunk them to avoid overwhelming the system
        $inboxChunks = array_chunk($uniqueInboxes, $maxConcurrent);

        foreach ($inboxChunks as $chunkIndex => $inboxChunk) {
            Log::debug("SendActivityPubPost: Processing chunk " . ($chunkIndex + 1) . " of " . count($inboxChunks) . " (" . count($inboxChunk) . " inboxes)");

            // Pre-generate signatures for all inboxes in this chunk, checking rate limits
            $signedRequests = [];
            $rateLimitedRequests = [];
            $rateLimitsEnabled = config('activitypub.rate_limits.enabled', true);
            $maxAttemptsPerMinute = config('activitypub.rate_limits.per_minute', 30);

            foreach ($inboxChunk as $targetUrl) {
                // Check rate limit for this domain
                if ($rateLimitsEnabled) {
                    $domain = parse_url($targetUrl, PHP_URL_HOST);
                    if (!$domain) {
                        Log::error("SendActivityPubPost: Invalid target URL (no host): $targetUrl");
                        $failureCount++;
                        continue;
                    }

                    $rateLimitKey = 'activitypub-outbox:' . $domain;

                    // Check if we can make this request within rate limit
                    if (RateLimiter::tooManyAttempts($rateLimitKey, $maxAttemptsPerMinute)) {
                        $availableIn = RateLimiter::availableIn($rateLimitKey);
                        Log::warning("SendActivityPubPost: Rate limit exceeded for {$domain} (available in {$availableIn}s). Skipping for now.");
                        $rateLimitedRequests[] = $targetUrl;
                        continue;
                    }

                    // Reserve a slot in the rate limiter (will be counted when we actually send)
                    RateLimiter::hit($rateLimitKey, 60);
                }

                // Generate signature
                $headers = \Ethernick\ActivityPubCore\Services\HttpSignature::sign($targetUrl, $actorUrl, $privateKey, $jsonBody);

                if (empty($headers)) {
                    Log::error("SendActivityPubPost: Failed to sign request for $targetUrl");
                    $failureCount++;
                    continue;
                }

                $signedRequests[$targetUrl] = $headers;
            }

            // Log rate-limited requests
            if (!empty($rateLimitedRequests)) {
                Log::info("SendActivityPubPost: Skipped " . count($rateLimitedRequests) . " requests due to rate limits. They will retry on job retry.");
            }

            // Send all requests in this chunk concurrently using Http::pool()
            try {
                $responses = Http::pool(function ($pool) use ($signedRequests, $jsonBody) {
                    $poolRequests = [];
                    foreach ($signedRequests as $targetUrl => $headers) {
                        $poolRequests[$targetUrl] = $pool
                            ->withHeaders($headers)
                            ->withBody($jsonBody, 'application/activity+json')
                            ->timeout(30)
                            ->post($targetUrl);
                    }
                    return $poolRequests;
                });

                // Process responses
                foreach ($responses as $targetUrl => $response) {
                    try {
                        if ($response->successful()) {
                            Log::info("SendActivityPubPost: Successfully sent to $targetUrl (status: {$response->status()})");
                            $successCount++;
                        } else {
                            Log::warning("SendActivityPubPost: Failed to send to $targetUrl (status: {$response->status()})");
                            $failureCount++;
                        }
                    } catch (\Exception $e) {
                        Log::error("SendActivityPubPost: Exception processing response from $targetUrl: " . $e->getMessage());
                        $failureCount++;
                    }
                }
            } catch (\Exception $e) {
                Log::error("SendActivityPubPost: Exception during concurrent HTTP pool: " . $e->getMessage());
                // Count all failed requests in this chunk
                $failureCount += count($signedRequests);
            }
        }

        Log::info("SendActivityPubPost: Completed. Success: {$successCount}, Failures: {$failureCount}");
    }
}
