<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Console\Commands\Migrations;

use Illuminate\Console\Command;
use Ethernick\ActivityPubCore\Jobs\FileQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class M20260116_MigrateOutboxToQueue extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'activitypub:migrate:outbox-to-queue';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '[Migration 20260116] Migrate existing file-based outbox items to Laravel queue';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('Starting migration of outbox files to database queue...');

        $queue = new FileQueue();
        $files = $queue->list('outbox', 1000); // Get up to 1000 files

        if (empty($files)) {
            $this->info('No files found in outbox. Nothing to migrate.');
            return 0;
        }

        $count = count($files);
        $this->info("Found {$count} files to migrate.");

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $migratedCount = 0;
        $errorCount = 0;

        foreach ($files as $file) {
            try {
                $item = $queue->get($file);

                if (!$item) {
                    $this->warn("\nCould not read file: {$file}. Skipping.");
                    $queue->delete($file); // Delete corrupted file
                    $errorCount++;
                    $bar->advance();
                    continue;
                }

                $targetUrl = $item['target_url'] ?? null;
                $actorId = $item['actor_id'] ?? null;
                $payload = $item['payload'] ?? null;

                if (!$targetUrl || !$actorId || !$payload) {
                    $this->warn("\nInvalid file data in: {$file}. Skipping.");
                    $queue->delete($file); // Delete invalid file
                    $errorCount++;
                    $bar->advance();
                    continue;
                }

                // Create a job entry directly in the database
                // We'll create a special job that sends to a single inbox
                DB::table('jobs')->insert([
                    'queue' => 'activitypub-outbox',
                    'payload' => json_encode([
                        'uuid' => \Illuminate\Support\Str::uuid()->toString(),
                        'displayName' => 'Ethernick\\ActivityPubCore\\Jobs\\SendToInbox',
                        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                        'maxTries' => 3,
                        'maxExceptions' => null,
                        'failOnTimeout' => false,
                        'backoff' => [60, 300, 900],
                        'timeout' => 120,
                        'retryUntil' => null,
                        'data' => [
                            'commandName' => 'Ethernick\\ActivityPubCore\\Jobs\\SendToInbox',
                            'command' => serialize(new \Ethernick\ActivityPubCore\Jobs\SendToInbox(
                                $targetUrl,
                                $actorId,
                                $payload
                            )),
                        ],
                    ]),
                    'attempts' => 0,
                    'reserved_at' => null,
                    'available_at' => Carbon::now()->timestamp,
                    'created_at' => Carbon::now()->timestamp,
                ]);

                // Delete the file after successful migration
                $queue->delete($file);
                $migratedCount++;

            } catch (\Exception $e) {
                $this->error("\nError migrating file {$file}: " . $e->getMessage());
                Log::error("MigrateOutboxToQueue: Error migrating {$file}: " . $e->getMessage());
                $errorCount++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Migration completed!");
        $this->info("Successfully migrated: {$migratedCount}");

        if ($errorCount > 0) {
            $this->warn("Errors encountered: {$errorCount}");
        }

        return 0;
    }
}
