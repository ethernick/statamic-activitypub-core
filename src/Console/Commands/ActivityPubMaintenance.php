<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Console\Commands;

use Illuminate\Console\Command;
use Statamic\Facades\Entry;
use Ethernick\ActivityPubCore\Services\ThreadService;
use Illuminate\Support\Carbon;

class ActivityPubMaintenance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'activitypub:maintenance';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run daily maintenance tasks: update reply counts and clean old data.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    // Old handle method removed

    protected function fixActivityDates(): void
    {
        $this->info('Checking activity dates...');

        $entries = Entry::query()
            ->whereIn('collection', ['activities', 'notes'])
            ->where('is_internal', '!=', true) // Only fix external items
            ->get();

        $count = $entries->count();
        if ($count === 0) {
            return;
        }

        $bar = $this->output->createProgressBar($count);
        $bar->start();
        $updatedCount = 0;

        foreach ($entries as $entry) {
            $payload = \Ethernick\ActivityPubCore\Storage\ActivityPubPayload::for($entry)->toArray();
            $dateStr = null;

            if (!empty($payload)) {
                // If it is an activity (e.g. Announce, Create), use outer activity published first
                if ($entry->collection()->handle() === 'activities') {
                    $dateStr = $payload['published'] ?? $payload['updated'] ?? null;
                    if (!$dateStr && isset($payload['object']) && is_array($payload['object'])) {
                        $dateStr = $payload['object']['published'] ?? null;
                    }
                } else {
                    // For notes and objects:
                    // If an edit/updated timestamp exists, preserve updated on the entry if not set
                    if (!empty($payload['updated']) && !$entry->get('updated')) {
                        try {
                            $entry->set('updated', Carbon::parse($payload['updated'])->toIso8601String());
                        } catch (\Exception $e) {
                            // ignore
                        }
                    }

                    // Original creation date must strictly prioritize published
                    $dateStr = $payload['published'] ?? $payload['updated'] ?? null;
                }
            }

            // Fallback for array dates if JSON didn't provide a date
            $currentDateValue = $entry->data()->get('date');
            if (!$dateStr && is_array($currentDateValue)) {
                $dateStr = $currentDateValue['published'] ?? $currentDateValue[0] ?? null;
            }

            if (!$dateStr) {
                if (is_array($currentDateValue)) {
                    $dateStr = $entry->lastModified()->toIso8601String();
                } else {
                    $bar->advance();
                    continue;
                }
            }

            try {
                $correctDate = Carbon::parse($dateStr);
                $currentDate = $entry->date();
                $currentDateValue = $entry->data()->get('date');

                $isInvalidFormat = is_array($currentDateValue);
                $isDifferent = $currentDate && abs($correctDate->diffInMinutes($currentDate)) > 0;

                if ($isInvalidFormat || $isDifferent) {
                    $entry->date($correctDate);
                    $entry->set('date', $correctDate->toIso8601String());
                    $entry->saveQuietly();
                    $updatedCount++;
                }
            } catch (\Exception $e) {
                // Ignore parsing errors
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        if ($updatedCount > 0) {
            $this->info("Corrected dates for {$updatedCount} entries.");
        }
    }

    public function handle(): int
    {
        $this->info('Queueing ActivityPub maintenance tasks...');

        // Dispatch maintenance tasks to queue
        \Ethernick\ActivityPubCore\Jobs\RecalculateActivityPubCounts::dispatch()
            ->onQueue('maintenance');

        \Ethernick\ActivityPubCore\Jobs\CleanOldActivityPubData::dispatch()
            ->onQueue('maintenance');

        // Fix activity dates inline (quick operation)
        $this->fixActivityDates();

        $this->info('Maintenance tasks queued. Heavy operations will run in the background.');

        return 0;
    }
}
