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

        $this->newLine();
        $this->info('ActivityPub cleanup completed.');
        return 0;
    }

    protected function cleanupActivities(int $days): void
    {
        $cutoff = Carbon::now()->subDays($days);
        $this->newLine();
        $this->info("Cleaning activities older than {$cutoff->toDateTimeString()}...");

        $query = Entry::query()
            ->where('collection', 'activities')
            ->where('is_internal', false)
            ->where('date', '<', $cutoff);

        $entries = $query->get();
        $count = $entries->count();

        if ($count > 0) {
            $this->info("Deleting $count activities...");
            $bar = $this->output->createProgressBar($count);
            $bar->start();

            foreach ($entries as $entry) {
                $entry->delete();
                $bar->advance();
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
                ->where('is_internal', false)
                ->where('date', '<', $cutoff);

            $entries = $query->get();
            $count = $entries->count();

            if ($count > 0) {
                $this->info("Deleting $count entries from $collection...");
                $bar = $this->output->createProgressBar($count);
                $bar->start();

                foreach ($entries as $entry) {
                    $entry->delete();
                    $bar->advance();
                }

                $bar->finish();
                $this->newLine();
            } else {
                $this->info("No old entries in $collection.");
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
