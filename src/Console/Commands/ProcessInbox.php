<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Console\Commands;

use Illuminate\Console\Command;
use Ethernick\ActivityPubCore\Jobs\FileQueue;
use Ethernick\ActivityPubCore\Jobs\InboxHandler;
use Statamic\Facades\Entry;
use Illuminate\Support\Facades\Log;
use Ethernick\ActivityPubCore\Logging\ActivityPubLog;

class ProcessInbox extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'activitypub:process-inbox {--limit=}';

    // ...

    protected $description = 'Process queued ActivityPub inbox items from file storage';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(FileQueue $queue, InboxHandler $handler): int
    {
        $limit = $this->option('limit');

        if ($limit === null) {
            $settingsPath = resource_path('settings/activitypub.yaml');
            $limit = 50;
            if (\Statamic\Facades\File::exists($settingsPath)) {
                $settings = \Statamic\Facades\YAML::parse(\Statamic\Facades\File::get($settingsPath));
                $limit = $settings['inbox_batch_size'] ?? 50;
            }
        } else {
            $limit = (int) $limit;
        }

        $files = $queue->list('inbox', $limit);

        if (empty($files)) {
            ActivityPubLog::info('ProcessInbox: No items in queue.'); // Optional: log empty runs? Maybe too noisy if every minute.
            // Let's stick to logging only when there is work, or maybe just debug level if we had it.
            // But user wants to know start/end.
            // Let's log start/end only if items are found to avoid span, OR make it configurable?
            // User asked "Does it tell me start and end".
            // Let's log it.
            // ActivityPubLog::info('ProcessInbox: Run started (no items).'); 
            $this->info('No items in inbox queue.');
            return 0;
        }

        ActivityPubLog::info("ProcessInbox: Started. Found " . count($files) . " items.");
        $this->info("Found " . count($files) . " items. Processing...");

        foreach ($files as $file) {
            $this->info("Processing $file...");

            try {
                $data = $queue->get($file);
                if (!$data) {
                    // Invalid or empty file, delete it
                    $queue->delete($file);
                    ActivityPubLog::warning("ProcessInbox: Deleted invalid/empty file $file");
                    continue;
                }

                $payload = $data['payload'] ?? null;
                $localActorId = $data['local_actor_id'] ?? null;
                $externalActorUrl = $data['external_actor_url'] ?? null;
                $externalActorId = $data['external_actor_id'] ?? null;

                if (!$payload || !$localActorId) {
                    $queue->delete($file);
                    ActivityPubLog::warning("ProcessInbox: Missing payload or local_actor_id in $file");
                    continue;
                }

                // Load Local Actor
                $localActor = Entry::find($localActorId);
                if (!$localActor) {
                    ActivityPubLog::error("ProcessInbox: Local actor $localActorId not found for $file");
                    // Should we delete? If local actor is gone, we can't process it.
                    $queue->delete($file);
                    continue;
                }

                // Resolve External Actor
                // Use ActorResolver service
                $resolver = new \Ethernick\ActivityPubCore\Services\ActorResolver();
                $externalActor = null;

                if ($externalActorId) {
                    $externalActor = Entry::find($externalActorId);
                }

                if (!$externalActor && $externalActorUrl) {
                    // Try to resolve from URL
                    // We should probably save it if we resolve it here? 
                    // InboxHandler expects an Entry.
                    // Resolve with 'save' = true?
                    // Typically background jobs should do the heavy lifting of resolution.
                    try {
                        $externalActor = $resolver->resolve($externalActorUrl, true);
                    } catch (\Exception $e) {
                        ActivityPubLog::warning("ProcessInbox: Failed to resolve actor $externalActorUrl: " . $e->getMessage());
                    }
                }

                // If actor resolution failed but we have a URL, maybe we pass null/url?
                // InboxHandler::handle($payload, $localActor, $externalActor)
                // It seems to expect $externalActor to be an Entry or similar (calls ->id()).
                // If null, it logs "Blocked activity from..." check?
                // Let's ensure we have something. If not, we might fail or pass null.
                // If we pass null, InboxHandler might crash if it calls methods on it.
                // Let's check InboxHandler lines 23: $externalActor->id().
                // So $externalActor MUST be an object.

                if (!$externalActor) {
                    ActivityPubLog::error("ProcessInbox: Could not resolve external actor for $file. Skipping/Deleting.");
                    // If we can't resolve the actor, we can't really process the activity (except maybe verify signature again? but we trust the queue).
                    $queue->delete($file);
                    continue;
                }

                // Handle
                $handler->handle($payload, $localActor, $externalActor);


                // Success
                $queue->delete($file);
                $this->info("Processed and deleted $file");
                ActivityPubLog::info("ProcessInbox: Processed $file");

            } catch (\Exception $e) {
                ActivityPubLog::error("ProcessInbox: Error processing $file: " . $e->getMessage());
                // ...
            }
        }

        ActivityPubLog::info("ProcessInbox: Finished.");
        return 0;
    }
}
