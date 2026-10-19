<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Console\Commands;

use Illuminate\Console\Command;
use Statamic\Facades\Entry;
use Statamic\Facades\Collection;
use Ethernick\ActivityPubCore\Storage\ActivityPubPayload;

class ActivityPubMigrateJson extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'activitypub:migrate-json
                            {--chunk=100 : Number of entries to process per batch}
                            {--delay=100 : Delay in milliseconds between batches}
                            {--dry-run : Perform a dry run without modifying files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate inline activitypub_json front-matter to sidecar JSON files';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('Starting ActivityPub JSON sidecar migration...');

        $dryRun = $this->option('dry-run');
        $chunkSize = (int) $this->option('chunk');
        $delay = (int) $this->option('delay');

        if ($dryRun) {
            $this->warn('*** DRY RUN MODE - No files will be modified ***');
        }

        // Load settings to find enabled ActivityPub collections
        $settingsPath = \Ethernick\ActivityPubCore\Services\ActivityPubUtils::settingsPath();
        $settings = \Statamic\Facades\File::exists($settingsPath) ? \Statamic\Facades\YAML::parse(\Statamic\Facades\File::get($settingsPath)) : [];

        $apCollections = [];
        foreach ($settings as $key => $config) {
            if (is_array($config) && ($config['enabled'] ?? false)) {
                if ($key !== 'activities' && $key !== 'actors') {
                    $apCollections[] = $key;
                }
            }
        }

        if (empty($apCollections)) {
            $collectionHandles = Collection::all()->map->handle()->all();
        } else {
            $collectionHandles = $apCollections;
        }

        if (empty($collectionHandles)) {
            $this->info('No collections to migrate.');
            return 0;
        }

        $this->info('Scanning collections: ' . implode(', ', $collectionHandles));

        $entriesToMigrate = [];
        foreach ($collectionHandles as $collectionHandle) {
            if (!Collection::find($collectionHandle)) {
                continue;
            }
            Entry::query()
                ->where('collection', $collectionHandle)
                ->chunk(200, function ($entries) use (&$entriesToMigrate) {
                    foreach ($entries as $entry) {
                        $inline = $entry->get('activitypub_json');
                        if (!empty($inline)) {
                            $entriesToMigrate[] = $entry;
                        }
                    }
                });
        }
        $entriesToMigrate = collect($entriesToMigrate);

        $totalCount = $entriesToMigrate->count();
        if ($totalCount === 0) {
            $this->info('No entries found with inline activitypub_json front-matter.');
            return 0;
        }

        $this->info("Found {$totalCount} entries to migrate.");

        $chunks = $entriesToMigrate->chunk($chunkSize);
        $migratedCount = 0;
        $bar = $this->output->createProgressBar($totalCount);
        $bar->start();

        foreach ($chunks as $chunkIndex => $chunk) {
            foreach ($chunk as $entry) {
                $inline = $entry->get('activitypub_json');
                if (is_array($inline) && isset($inline['code'])) {
                    $inline = $inline['code'];
                }
                $jsonStr = (string) $inline;

                if ($dryRun) {
                    $this->newLine();
                    $this->info("Would migrate entry [{$entry->id()}] in collection [{$entry->collection()->handle()}]");
                    $this->line("  Path: " . $entry->path());
                    $this->line("  JSON length: " . strlen($jsonStr));
                } else {
                    // 1. Remove inline json first to write stripped markdown
                    $entry->remove('activitypub_json');

                    // 2. Prevent blueprint listener from re-injecting during saveQuietly
                    \Ethernick\ActivityPubCore\Listeners\ActivityPubListener::setSaving($entry->id(), true);

                    // 3. Save entry quietly (this writes stripped .md to its final path)
                    $entry->saveQuietly();

                    // 4. Save sidecar JSON to the final path
                    $payload = ActivityPubPayload::for($entry);
                    $payload->save($jsonStr);

                    // 5. Clean up saving status
                    \Ethernick\ActivityPubCore\Listeners\ActivityPubListener::setSaving($entry->id(), false);
                }

                $migratedCount++;
                $bar->advance();
            }

            // Apply delay between chunks (not after the final chunk)
            if ($chunkIndex < $chunks->count() - 1 && $delay > 0) {
                usleep($delay * 1000);
            }
        }

        $bar->finish();
        $this->newLine();

        if (!$dryRun) {
            \Statamic\Facades\Stache::clear();
        }

        if ($dryRun) {
            $this->info("Dry run complete. Checked {$migratedCount} entries.");
        } else {
            $this->info("Migration complete. Successfully migrated {$migratedCount} entries to sidecar JSON files.");
        }

        return 0;
    }
}
