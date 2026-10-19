<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Console\Commands;

use Illuminate\Console\Command;
use Statamic\Facades\Entry;
use Statamic\Facades\File;
use Statamic\Facades\YAML;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Statamic\Events\EntryDeleted;
use Ethernick\ActivityPubCore\Events\EntryCleaning;

class ActivityPubClean extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'activitypub:clean';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old external ActivityPub activities and entries.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('Starting ActivityPub cleanup...');

        // Suppress EntryDeleted events to prevent side effects (like auto-generating Delete activities)
        // or triggering other listeners during bulk cleanup.
        Event::fake([EntryDeleted::class]);

        $settings = $this->getSettings();
        $activityRetention = (int) ($settings['retention_activities'] ?? 2);
        $entryRetention = (int) ($settings['retention_entries'] ?? 30);

        $this->info("Retention Policy: Activities > $activityRetention days, Entries > $entryRetention days.");

        // 1. Cleanup Activities
        $this->cleanupActivities($activityRetention);

        // 2. Cleanup Other Entries (Notes, Articles, etc in AP enabled collections)
        $this->cleanupEntries($entryRetention);

        // 3. Cleanup Auto-Block logs
        $prunedLogs = \Ethernick\ActivityPubCore\Services\BlockList::prune();
        if ($prunedLogs > 0) {
            $this->info("Pruned {$prunedLogs} old auto-block log entries.");
        }

        // 4. Cleanup Log Files
        $logRetention = (int) ($settings['retention_logs'] ?? config('activitypub.retention.logs', 7));
        $this->cleanupLogFiles($logRetention);

        // 5. Cleanup orphaned json sidecars
        $this->cleanupOrphanedPayloads();

        // Clear and immediately warm Stache cache to reflect bulk filesystem updates
        $this->newLine();
        $this->info('Flushing and rebuilding Stache cache...');
        \Statamic\Facades\Stache::clear();
        try {
            if (function_exists('Laravel\Prompts\spin') && $this->output->isDecorated()) {
                \Laravel\Prompts\spin(
                    callback: fn () => \Statamic\Facades\Stache::warm(),
                    message: 'Warming the Stache...'
                );
            } else {
                $this->output->write('Re-indexing collections... ');
                \Statamic\Facades\Stache::warm();
                $this->info('done.');
            }
        } catch (\Throwable $e) {
            $this->warn('Warning: Stache warm encountered an issue: ' . $e->getMessage());
        }

        $this->newLine();
        $this->info('ActivityPub cleanup completed.');
        return 0;
    }

    protected function cleanupOrphanedPayloads(): void
    {
        $contentPath = base_path('content/collections');
        if (!\Illuminate\Support\Facades\File::isDirectory($contentPath)) {
            return;
        }

        $deletedCount = 0;
        $files = \Illuminate\Support\Facades\File::allFiles($contentPath);

        foreach ($files as $file) {
            if ($file->getExtension() === 'json') {
                $base = substr($file->getPathname(), 0, -5);
                if (!\Illuminate\Support\Facades\File::exists($base . '.md') && !\Illuminate\Support\Facades\File::exists($base . '.yaml')) {
                    \Illuminate\Support\Facades\File::delete($file->getPathname());
                    $deletedCount++;
                }
            }
        }

        if ($deletedCount > 0) {
            $this->info("Deleted {$deletedCount} orphaned sidecar payload files.");
        }
    }

    protected function cleanupLogFiles(int $days): void
    {
        $logDir = storage_path('logs');
        if (!\Illuminate\Support\Facades\File::isDirectory($logDir)) {
            return;
        }

        $cutoff = Carbon::now()->subDays($days);
        $deleted = 0;
        $files = \Illuminate\Support\Facades\File::glob($logDir . '/activitypub*.log');

        foreach ($files as $file) {
            // Check file modified time
            $mtime = \Illuminate\Support\Facades\File::lastModified($file);
            if ($mtime && Carbon::createFromTimestamp($mtime)->lessThan($cutoff)) {
                @unlink($file);
                $deleted++;
            }
        }

        if ($deleted > 0) {
            $this->info("Deleted {$deleted} old ActivityPub log file(s) (> {$days} days old).");
        }
    }

    protected function cleanupActivities(int $days): void
    {
        $cutoff = Carbon::now()->subDays($days);
        $this->newLine();
        $this->info("Cleaning activities older than {$cutoff->toDateTimeString()}...");

        $query = Entry::query()
            ->where('collection', 'activities')
            ->where('is_internal', '!=', true)
            ->where('date', '<', $cutoff);

        // Fetch all matching entry objects once up front to snapshot the work
        $entries = $query->get();
        $count = $entries->count();

        if ($count > 0) {
            $this->info("Deleting $count activities...");
            $bar = $this->output->createProgressBar($count);
            $bar->start();

            // Extract file paths into a lightweight memory array
            $targets = [];
            foreach ($entries as $entry) {
                if ($this->shouldProtect($entry)) {
                    $bar->advance();
                    continue;
                }

                $path = $entry->path();
                if ($path) {
                    $targets[] = $path;
                }
            }

            // Free the heavy entry models from memory immediately
            unset($entries);

            // Chunk unlinks to avoid filesystem saturation
            foreach (array_chunk($targets, 500) as $chunk) {
                foreach ($chunk as $path) {
                    $jsonPath = preg_replace('/\.(md|yaml)$/', '.json', $path);
                    if (File::exists($jsonPath)) {
                        @unlink($jsonPath);
                    }
                    if (File::exists($path)) {
                        @unlink($path);
                    }
                    $bar->advance();
                }
            }

            $bar->finish();
            $this->newLine();
        } else {
            $this->info("No old activities to delete.");
        }
    }

    protected function cleanupEntries(int $days): void
    {
        $cutoff = Carbon::now()->subDays($days);
        $this->newLine();
        $this->info("Cleaning external entries older than {$cutoff->toDateTimeString()}...");

        $settings = $this->getSettings();
        $collections = [];
        foreach ($settings as $key => $config) {
            if (is_array($config) && ($config['enabled'] ?? false)) {
                if ($key !== 'activities' && $key !== 'actors') {
                    $collections[] = $key;
                }
            }
        }

        foreach ($collections as $collection) {
            $query = Entry::query()
                ->where('collection', $collection)
                ->where('is_internal', '!=', true)
                ->where('date', '<', $cutoff);

            $entries = $query->get();
            $count = $entries->count();

            if ($count > 0) {
                $this->info("Deleting $count entries from $collection...");
                $bar = $this->output->createProgressBar($count);
                $bar->start();

                $targets = [];
                foreach ($entries as $entry) {
                    if ($this->shouldProtect($entry)) {
                        $bar->advance();
                        continue;
                    }

                    $path = $entry->path();
                    if ($path) {
                        $targets[] = $path;
                    }
                }

                unset($entries);

                foreach (array_chunk($targets, 500) as $chunk) {
                    foreach ($chunk as $path) {
                        $jsonPath = preg_replace('/\.(md|yaml)$/', '.json', $path);
                        if (File::exists($jsonPath)) {
                            @unlink($jsonPath);
                        }
                        if (File::exists($path)) {
                            @unlink($path);
                        }
                        $bar->advance();
                    }
                }

                $bar->finish();
                $this->newLine();
            } else {
                $this->info("No old entries in $collection.");
            }
        }
    }

    protected function shouldProtect($entry): bool
    {
        // Dispatch event allowing other addons to protect this entry from cleanup
        $responses = Event::dispatch(new EntryCleaning($entry));
        
        foreach ($responses as $response) {
            if ($response === true) {
                return true; // Skip deletion
            }
        }

        return false;
    }

    protected function getSettings(): array
    {
        $path = \Ethernick\ActivityPubCore\Services\ActivityPubUtils::settingsPath();
        if (!File::exists($path)) {
            return [];
        }
        return YAML::parse(File::get($path));
    }
}
