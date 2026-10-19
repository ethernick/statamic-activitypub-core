<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Statamic\Facades\Entry;
use Statamic\Facades\File;
use Statamic\Facades\YAML;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Event;
use Statamic\Events\EntryDeleted;

class CleanOldActivityPubData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;
    public int $timeout;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        // Load configuration values
        $this->tries = config('activitypub.queue.maintenance.tries', 2);
        $this->timeout = config('activitypub.queue.maintenance.timeout', 600);

        $this->onQueue('maintenance');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('CleanOldActivityPubData: Starting cleanup...');

        // Suppress EntryDeleted events to prevent side effects
        Event::fake([EntryDeleted::class]);

        $settings = $this->getSettings();
        $activityRetention = (int) ($settings['retention_activities'] ?? config('activitypub.retention.activities', 2));
        $entryRetention = (int) ($settings['retention_entries'] ?? config('activitypub.retention.entries', 30));

        Log::info("CleanOldActivityPubData: Retention Policy - Activities: {$activityRetention} days, Entries: {$entryRetention} days");

        // 1. Cleanup Activities
        $this->cleanupActivities($activityRetention);

        // 2. Cleanup Other Entries
        $this->cleanupEntries($entryRetention, $settings);

        // 3. Cleanup Auto-Block Logs
        $this->cleanupAutoBlockLogs();

        // 4. Cleanup Log Files
        $logRetention = (int) ($settings['retention_logs'] ?? config('activitypub.retention.logs', 7));
        $this->cleanupLogFiles($logRetention);

        // 5. Cleanup Stale Failed Queue Jobs (>14 days)
        $this->cleanupFailedJobs();

        // 6. Cleanup Orphaned Sidecar JSON files
        $this->cleanupOrphanedPayloads($settings);

        // Clear and immediately warm Stache cache to reflect bulk filesystem updates
        \Statamic\Facades\Stache::clear();
        try {
            \Statamic\Facades\Stache::warm();
        } catch (\Throwable $e) {
            Log::warning('CleanOldActivityPubData: Stache warm issue: ' . $e->getMessage());
        }

        Log::info('CleanOldActivityPubData: Cleanup completed.');
    }

    protected function cleanupFailedJobs(): void
    {
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('failed_jobs')) {
                $cutoff = Carbon::now()->subDays(14);
                $deleted = \Illuminate\Support\Facades\DB::table('failed_jobs')
                    ->where('failed_at', '<', $cutoff)
                    ->delete();
                Log::info("CleanOldActivityPubData: Pruned {$deleted} stale records from failed_jobs.");
            }
        } catch (\Exception $e) {
            Log::warning('CleanOldActivityPubData: Failed to prune failed_jobs: ' . $e->getMessage());
        }
    }

    protected function cleanupOrphanedPayloads(array $settings): void
    {
        // Scan collections for .json sidecars that no longer have a companion .md or .yaml entry
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
            Log::info("CleanOldActivityPubData: Deleted {$deletedCount} orphaned sidecar payload files.");
        }
    }

    protected function cleanupAutoBlockLogs(): void
    {
        Log::info('CleanOldActivityPubData: Pruning auto-block logs...');
        $count = \Ethernick\ActivityPubCore\Services\BlockList::prune();
        Log::info("CleanOldActivityPubData: Pruned {$count} auto-block log entries.");
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
            $mtime = \Illuminate\Support\Facades\File::lastModified($file);
            if ($mtime && Carbon::createFromTimestamp($mtime)->lessThan($cutoff)) {
                @unlink($file);
                $deleted++;
            }
        }

        if ($deleted > 0) {
            Log::info("CleanOldActivityPubData: Deleted {$deleted} old ActivityPub log file(s) (> {$days} days old).");
        }
    }

    protected function cleanupActivities(int $days): void
    {
        $cutoff = Carbon::now()->subDays($days);
        Log::info("CleanOldActivityPubData: Cleaning activities older than {$cutoff->toDateTimeString()}");

        $query = Entry::query()
            ->where('collection', 'activities')
            ->where('is_internal', '!=', true)
            ->where('date', '<', $cutoff);

        $entries = $query->get();
        $count = $entries->count();

        if ($count > 0) {
            Log::info("CleanOldActivityPubData: Deleting {$count} activities in chunks");

            $targets = [];
            foreach ($entries as $entry) {
                $path = $entry->path();
                if ($path) {
                    $targets[] = $path;
                }
            }

            unset($entries);

            $deletedCount = 0;
            foreach (array_chunk($targets, 500) as $chunk) {
                foreach ($chunk as $path) {
                    $jsonPath = preg_replace('/\.(md|yaml)$/', '.json', $path);
                    if (\Illuminate\Support\Facades\File::exists($jsonPath)) {
                        @unlink($jsonPath);
                    }
                    if (\Illuminate\Support\Facades\File::exists($path)) {
                        @unlink($path);
                    }
                    $deletedCount++;
                }
            }

            Log::info("CleanOldActivityPubData: Deleted {$deletedCount} activities");
        } else {
            Log::info("CleanOldActivityPubData: No old activities to delete");
        }
    }

    protected function cleanupEntries(int $days, array $settings): void
    {
        $cutoff = Carbon::now()->subDays($days);
        Log::info("CleanOldActivityPubData: Cleaning external entries older than {$cutoff->toDateTimeString()}");

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
                Log::info("CleanOldActivityPubData: Deleting {$count} entries from {$collection} in chunks");

                $targets = [];
                foreach ($entries as $entry) {
                    $path = $entry->path();
                    if ($path) {
                        $targets[] = $path;
                    }
                }

                unset($entries);

                $deletedCount = 0;
                foreach (array_chunk($targets, 500) as $chunk) {
                    foreach ($chunk as $path) {
                        $jsonPath = preg_replace('/\.(md|yaml)$/', '.json', $path);
                        if (\Illuminate\Support\Facades\File::exists($jsonPath)) {
                            @unlink($jsonPath);
                        }
                        if (\Illuminate\Support\Facades\File::exists($path)) {
                            @unlink($path);
                        }
                        $deletedCount++;
                    }
                }

                Log::info("CleanOldActivityPubData: Deleted {$deletedCount} entries from {$collection}");
            } else {
                Log::info("CleanOldActivityPubData: No old entries in {$collection}");
            }
        }
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
