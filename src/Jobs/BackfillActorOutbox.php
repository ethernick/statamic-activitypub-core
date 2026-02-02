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

class BackfillActorOutbox implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $localActorId;
    protected string $remoteActorId;

    public function __construct(string $localActorId, string $remoteActorId)
    {
        $this->localActorId = $localActorId;
        $this->remoteActorId = $remoteActorId;
    }

    public function handle(): void
    {
        $localActor = Entry::find($this->localActorId);
        $remoteActor = Entry::find($this->remoteActorId);

        if (!$localActor || !$remoteActor) {
            Log::error("BackfillActorOutbox: Actor not found.");
            return; // Actor likely deleted - retrying won't help
        }

        $outboxUrl = $remoteActor->get('outbox_url');
        if (!$outboxUrl) {
            // Try to guess or fetch actor again? 
            // The actor entry should have it if resolved properly.
            Log::warning("BackfillActorOutbox: No outbox URL for " . $remoteActor->get('title'));
            return;
        }

        Log::info("BackfillActorOutbox: Fetching outbox $outboxUrl");

        try {
            $response = Http::withHeaders(['Accept' => 'application/activity+json, application/ld+json'])
                ->get($outboxUrl);

            if (!$response->successful()) {
                Log::error("BackfillActorOutbox: Failed to fetch outbox ($outboxUrl): " . $response->status());
                // Throw exception for server errors to trigger retry
                if ($response->status() >= 500) {
                    throw new \RuntimeException("Server error fetching outbox ($outboxUrl): " . $response->status());
                }
                return;
            }

            $data = $response->json();

            // Navigate to first page if this is a Collection
            $first = $data['first'] ?? null;
            if ($first) {
                if (is_string($first)) {
                    // Fetch first page
                    Log::info("BackfillActorOutbox: Fetching first page $first");
                    $response = Http::withHeaders(['Accept' => 'application/activity+json, application/ld+json'])
                        ->get($first);
                    if ($response->successful()) {
                        $data = $response->json();
                    } else {
                        Log::error("BackfillActorOutbox: Failed to fetch first page ($first): " . $response->status());
                        // Throw exception for server errors to trigger retry
                        if ($response->status() >= 500) {
                            throw new \RuntimeException("Server error fetching first page ($first): " . $response->status());
                        }
                        return;
                    }
                } else {
                    // First page is embedded
                    $data = $first;
                }
            }

            $items = $data['orderedItems'] ?? $data['items'] ?? [];
            if (empty($items)) {
                Log::info("BackfillActorOutbox: No items found in outbox.");
                return;
            }

            Log::info("BackfillActorOutbox: Found " . count($items) . " items. Queueing...");

            $queue = new FileQueue();

            foreach ($items as $item) {
                // If item is just an ID/URL, we might need to fetch it?
                // Or does InboxHandler handle fetching? InboxHandler expects 'payload' array.
                // If we pass a string ID as payload, InboxHandler::handle crashes accessing $payload['type'].
                // So we MUST resolve to object.

                $payload = $item;
                if (is_string($item)) {
                    Log::info("BackfillActorOutbox: Fetching activity $item");
                    try {
                        $actResp = Http::withHeaders(['Accept' => 'application/activity+json'])
                            ->get($item);
                        if ($actResp->successful()) {
                            $payload = $actResp->json();
                        } else {
                            continue;
                        }
                    } catch (\Exception $e) {
                        continue;
                    }
                }

                if (!is_array($payload))
                    continue;

                $queue->push('inbox', [
                    'payload' => $payload,
                    'local_actor_id' => $localActor->id(),
                    'external_actor_id' => $remoteActor->id(),
                ]);
            }

        } catch (\Exception $e) {
            Log::error("BackfillActorOutbox: Exception: " . $e->getMessage());
        }
    }
}
