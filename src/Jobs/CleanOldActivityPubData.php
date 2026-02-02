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

        Log::info('CleanOldActivityPubData: Cleanup completed.');
    }

    protected function cleanupActivities(int $days): void
    {
        $cutoff = Carbon::now()->subDays($days);
        Log::info("CleanOldActivityPubData: Cleaning activities older than {$cutoff->toDateTimeString()}");

        $query = Entry::query()
            ->where('collection', 'activities')
            ->where('is_internal', false)
            ->where('date', '<', $cutoff);

        $entries = $query->get();
        $count = $entries->count();

        if ($count > 0) {
            Log::info("CleanOldActivityPubData: Deleting {$count} activities");

            foreach ($entries as $entry) {
                $entry->delete();
            }

            Log::info("CleanOldActivityPubData: Deleted {$count} activities");
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
                ->where('is_internal', false)
                ->where('date', '<', $cutoff);

            $entries = $query->get();
            $count = $entries->count();

            if ($count > 0) {
                Log::info("CleanOldActivityPubData: Deleting {$count} entries from {$collection}");

                foreach ($entries as $entry) {
                    $entry->delete();
                }

                Log::info("CleanOldActivityPubData: Deleted {$count} entries from {$collection}");
            } else {
                Log::info("CleanOldActivityPubData: No old entries in {$collection}");
            }
        }
    }

    protected function getSettings(): array
    {
        $path = resource_path('settings/activitypub.yaml');
        if (!File::exists($path)) {
            return [];
        }
        return YAML::parse(File::get($path));
    }
}
