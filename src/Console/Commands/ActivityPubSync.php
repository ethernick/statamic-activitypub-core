<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Console\Commands;

use Illuminate\Console\Command;
use Statamic\Facades\Entry;
use Statamic\Facades\File;
use Statamic\Facades\YAML;
use Ethernick\ActivityPubCore\Storage\ActivityPubPayload;
use Ethernick\ActivityPubCore\Services\ActivityPubUtils;

class ActivityPubSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'activitypub:sync 
                            {--collection=* : Collections to sync (defaults to all enabled)}
                            {--force : Re-sync even if sidecars exist and are up to date}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ingest and synchronize raw file uploads (e.g. via SFTP or Git) with ActivityPub sidecars and outbox';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('Scanning collections for unsynced or updated content files...');

        $settings = $this->getSettings();
        $targetCollections = $this->option('collection');
        $force = (bool) $this->option('force');

        if (empty($targetCollections)) {
            // Pick all enabled content collections from settings (excluding activity logs and actors)
            $targetCollections = [];
            foreach ($settings as $key => $config) {
                if (is_array($config) && ($config['enabled'] ?? false) && !in_array($key, ['hashtags', 'activities', 'actors'])) {
                    $targetCollections[] = $key;
                }
            }
            if (empty($targetCollections)) {
                $targetCollections = ['notes', 'articles', 'polls', 'events', 'places'];
            }
        }

        $this->info('Target collections: ' . implode(', ', $targetCollections));

        $totalProcessed = 0;
        $totalCreated = 0;
        $totalUpdated = 0;

        foreach ($targetCollections as $collectionHandle) {
            $entries = Entry::query()
                ->where('collection', $collectionHandle)
                ->get();

            if ($entries->isEmpty()) {
                continue;
            }

            foreach ($entries as $entry) {
                // Only process internal entries (our local authored content)
                if ($entry->get('is_internal') === false) {
                    continue;
                }

                $entryPath = $entry->path();
                if (!$entryPath || !File::exists($entryPath)) {
                    continue;
                }

                $payload = ActivityPubPayload::for($entry);
                $sidecarExists = $payload->exists();
                $needsSync = false;
                $isUpdate = false;

                if ($force || !$sidecarExists) {
                    $needsSync = true;
                    $isUpdate = false;
                } else {
                    // Check if markdown file mtime is newer than companion json sidecar
                    $sidecarPath = $payload->sidecarFilePath();
                    if ($sidecarPath && File::exists($sidecarPath)) {
                        $entryMtime = File::lastModified($entryPath);
                        $sidecarMtime = File::lastModified($sidecarPath);
                        if ($entryMtime > $sidecarMtime) {
                            $needsSync = true;
                            $isUpdate = true;
                        }
                    }

                    // Check if missing required tokens
                    if (!$entry->get('search_tokens')) {
                        $needsSync = true;
                    }
                }

                if ($needsSync) {
                    $this->line("Syncing [{$collectionHandle}] {$entry->slug()}...");

                    // Trigger save to invoke ActivityPubListener & AutoGenerateActivityListener
                    // This generates sidecar JSON, extracts search_tokens, and dispatches Create/Update activity
                    $entry->save();

                    $totalProcessed++;
                    if ($isUpdate) {
                        $totalUpdated++;
                    } else {
                        $totalCreated++;
                    }
                }
            }
        }

        $this->newLine();
        $this->info("ActivityPub Sync complete: {$totalProcessed} entries processed ({$totalCreated} initialized, {$totalUpdated} updated).");

        return 0;
    }

    protected function getSettings(): array
    {
        $path = ActivityPubUtils::settingsPath();
        if (!File::exists($path)) {
            return [];
        }

        return YAML::parse(File::get($path)) ?: [];
    }
}
