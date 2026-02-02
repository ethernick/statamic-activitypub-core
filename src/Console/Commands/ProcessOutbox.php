<?php

namespace Ethernick\ActivityPubCore\Console\Commands;

use Illuminate\Console\Command;
use Ethernick\ActivityPubCore\Jobs\FileQueue;
use Statamic\Facades\Entry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Ethernick\ActivityPubCore\Logging\ActivityPubLog;

class ProcessOutbox extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'activitypub:process-outbox {--limit=}';

    // ...

    protected $description = 'Process queued ActivityPub outbox attributes (send to remote inboxes)';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(FileQueue $queue)
    {
        $limit = $this->option('limit');

        if ($limit === null) {
            $settingsPath = resource_path('settings/activitypub.yaml');
            $limit = 50;
            if (\Statamic\Facades\File::exists($settingsPath)) {
                $settings = \Statamic\Facades\YAML::parse(\Statamic\Facades\File::get($settingsPath));
                $limit = $settings['outbox_batch_size'] ?? 50;
            }
        } else {
            $limit = (int) $limit;
        }

        $files = $queue->list('outbox', $limit);

        if (empty($files)) {
            ActivityPubLog::info('ProcessOutbox: No items in queue.');
            $this->info('No items in outbox queue.');
            return 0;
        }

        ActivityPubLog::info("ProcessOutbox: Started. Found " . count($files) . " items.");
        $this->info("Found " . count($files) . " items. Processing...");

        foreach ($files as $file) {
            $this->info("Processing $file...");

            try {
                $item = $queue->get($file);

                if (!$item) {
                    ActivityPubLog::warning("ProcessOutbox: Could not read file $file. Deleting.");
                    $queue->delete($file);
                    continue;
                }

                $targetUrl = $item['target_url'] ?? null;
                $actorId = $item['actor_id'] ?? null;
                $payload = $item['payload'] ?? null;

                if (!$targetUrl || !$actorId || !$payload) {
                    ActivityPubLog::error("ProcessOutbox: Invalid job data in $file. Deleting.");
                    $queue->delete($file);
                    continue;
                }

                // Get Actor
                $actor = Entry::find($actorId);
                if (!$actor) {
                    ActivityPubLog::error("ProcessOutbox: Actor not found: $actorId. Deleting.");
                    $queue->delete($file);
                    continue;
                }

                $privateKey = $actor->get('private_key');
                $actorUrl = $actor->get('activitypub_id');

                if (!$privateKey || !$actorUrl) {
                    ActivityPubLog::error("ProcessOutbox: Missing private key or ActivityPub ID for actor: $actorId. Deleting.");
                    $queue->delete($file);
                    continue;
                }

                $jsonBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                $headers = \Ethernick\ActivityPubCore\Services\HttpSignature::sign($targetUrl, $actorUrl, $privateKey, $jsonBody);

                if (empty($headers)) {
                    ActivityPubLog::error("ProcessOutbox: Failed to sign request for $targetUrl");
                    continue;
                }

                $timestamp = now()->toDateTimeString();
                $logEntry = sprintf(
                    "ProcessOutbox: [%s] POST %s\n", // Prefix for context
                    $timestamp,
                    $targetUrl
                );

                try {
                    $response = Http::withHeaders($headers)
                        ->withBody($jsonBody, 'application/activity+json')
                        ->post($targetUrl);

                    $status = $response->status();
                    $logEntry .= "Result: {$status}";
                    ActivityPubLog::info($logEntry);

                    if ($response->successful()) {
                        $this->info("Sent to $targetUrl");
                        $queue->delete($file);
                    } else {
                        $body = $response->body();
                        $this->warn("Failed sending to $targetUrl: $status");
                        ActivityPubLog::warning("ProcessOutbox: Failed delivery to {$targetUrl}: {$status}. Response: {$body}");
                    }
                } catch (\Exception $e) {
                    ActivityPubLog::error("ProcessOutbox: Exception sending to $targetUrl: " . $e->getMessage());
                }

            } catch (\Exception $e) {
                ActivityPubLog::error("ProcessOutbox: Error processing $file: " . $e->getMessage());
            }
        }

        ActivityPubLog::info("ProcessOutbox: Finished.");
        return 0;
    }
}
