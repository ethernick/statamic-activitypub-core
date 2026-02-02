<?php

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

    protected function fixActivityDates()
    {
        $this->info('Checking activity dates...');

        $entries = Entry::query()
            ->whereIn('collection', ['activities', 'notes'])
            ->where('is_internal', false) // Only fix external items
            ->get();

        $count = $entries->count();
        if ($count === 0) {
            return;
        }

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $updatedCount = 0;

        foreach ($entries as $entry) {
            $json = $entry->get('activitypub_json');
            $dateStr = null;

            if ($json && $payload = json_decode($json, true)) {
                $dateStr = $payload['published'] ?? $payload['updated'] ?? $payload['object']['published'] ?? null;
            }

            // Fallback for array dates if JSON didn't provide a date
            $currentDateValue = $entry->data()->get('date');
            if (!$dateStr && is_array($currentDateValue)) {
                // Try to get a date string from the array
                $dateStr = $currentDateValue['published'] ?? $currentDateValue[0] ?? null;
            }

            if (!$dateStr) {
                // If we still don't have a date string but the current value is invalid (array),
                // we MUST fix it. Use the entry's last modified or now.
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

                // Check if date differs significantly or if the stored value is invalid (e.g. array)
                $isInvalidFormat = is_array($currentDateValue);
                $isDifferent = $currentDate && abs($correctDate->diffInMinutes($currentDate)) > 0;

                if ($isInvalidFormat || $isDifferent) {
                    $entry->date($correctDate);
                    $entry->set('date', $correctDate->toIso8601String()); // Explicitly set string
                    $entry->saveQuietly(); // Use saveQuietly to avoid triggering events
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

    public function handle()
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

    // ... (fixActivityDates remains unchanged, assume it's there or I leave it alone check lines)
    // Wait, I need to make sure I don't delete fixActivityDates if I target the whole file. 
    // I will target specific blocks. 

    // Actually, I can replace `updateReplyCounts` and `handle` separately or together if contiguous.
    // They are not contiguous if `fixActivityDates` is between them?
    // In the file provided:
    // handle() is lines 31-44
    // fixActivityDates() is lines 46-119
    // updateReplyCounts() is lines 121-156

    // So I will make two edits. 
    // Edit 1: update handle()
    // Edit 2: replace updateReplyCounts() with recalculateCounts()

    // Wait, I can do it in one go if I use multi_replace? 
    // Yes.

    // BUT checking the file content again...
    // I will use replace_file_content for handle() first.
    // Then replace_file_content for updateReplyCounts().

    // Let's stick to single tool calls for safety if I can.

    protected function recalculateCounts()
    {
        $this->info('Recalculating all counts (Replies, Likes, Boosts, Related)...');

        // 1. Reset all counts to 0 (optional but safer for full rebuild, though slow)
        // Let's just iterate and set them.

        $notes = Entry::query()->where('collection', 'notes')->get();

        $bar = $this->output->createProgressBar($notes->count());
        $bar->start();

        foreach ($notes as $note) {
            $id = $note->id();
            $apId = $note->get('activitypub_id');
            $absUrl = $note->absoluteUrl(); // Caution: slow if generating thousands of URLs?
            // Actually absoluteUrl() is fast usually.

            // Resolve IDs to match against 'object' or 'in_reply_to'
            $objectIds = array_filter([$id, $apId, $absUrl]);

            // 1. Reply Count
            $replyCount = Entry::query()
                ->where('collection', 'notes')
                ->whereIn('in_reply_to', $objectIds)
                ->count();

            // 2. Like Count (from Like activities)
            // Note: Use 'activities' collection.
            $likeCount = Entry::query()
                ->where('collection', 'activities')
                ->where('type', '=', 'Like')
                ->get() // Stache 'whereIn object' is tricky if object is array.
                ->filter(function ($act) use ($objectIds) {
                    $obj = $act->get('object');
                    if (is_array($obj))
                        $obj = $obj['id'] ?? $obj[0] ?? null;
                    return in_array($obj, $objectIds);
                })
                ->count();

            // 3. Boost Count (Announce)
            $boostCount = Entry::query()
                ->where('collection', 'activities')
                ->where('type', '=', 'Announce')
                ->get()
                ->filter(function ($act) use ($objectIds) {
                    $obj = $act->get('object');
                    if (is_array($obj))
                        $obj = $obj['id'] ?? $obj[0] ?? null;
                    return in_array($obj, $objectIds);
                })
                ->count();

            // 4. Related Activity Count (Generic)
            // Any activity where object matches, excluding basic ones?
            // "Related Activity" usually means Likes + Boosts + Replies (if sent as activity) + Mentions?
            // Or just "Activities about this object".
            // Implementation plan said "fetch all activities ... count".
            // Let's count ALL activities targeting this object.
            $relatedCount = Entry::query()
                ->where('collection', 'activities')
                ->get()
                ->filter(function ($act) use ($objectIds) {
                    $obj = $act->get('object');
                    if (is_array($obj))
                        $obj = $obj['id'] ?? $obj[0] ?? null;
                    return in_array($obj, $objectIds);
                })
                ->count();

            // Optimization: If counts match existing, skip save.
            $data = $note->data()->toArray();
            if (
                ($data['reply_count'] ?? 0) !== $replyCount ||
                ($data['like_count'] ?? 0) !== $likeCount ||
                ($data['boost_count'] ?? 0) !== $boostCount ||
                ($data['related_activity_count'] ?? 0) !== $relatedCount
            ) {
                $note->set('reply_count', $replyCount);
                $note->set('like_count', $likeCount);
                $note->set('boost_count', $boostCount);
                $note->set('related_activity_count', $relatedCount);
                $note->saveQuietly();
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }
}
