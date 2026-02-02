<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Statamic\Facades\Entry;
use Illuminate\Support\Facades\Log;

class RecalculateActivityPubCounts implements ShouldQueue
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
        Log::info('RecalculateActivityPubCounts: Starting count recalculation...');

        $notes = Entry::query()->where('collection', 'notes')->get();
        $totalNotes = $notes->count();

        Log::info("RecalculateActivityPubCounts: Processing {$totalNotes} notes");

        // OPTIMIZATION: Load all activities ONCE instead of querying for each note
        // Previous: For each note, query ALL activities 3 times (Like, Announce, All)
        // New: Load all activities once, group by object ID, then lookup counts
        // Complexity improvement: O(n * m) → O(n + m)

        Log::info('RecalculateActivityPubCounts: Loading and grouping activities...');
        $allActivities = Entry::query()->where('collection', 'activities')->get();

        // Group activities by their object field and type
        $likesByObject = [];
        $announcesByObject = [];
        $allActivitiesByObject = [];

        foreach ($allActivities as $activity) {
            $obj = $activity->get('object');
            if (is_array($obj)) {
                $obj = $obj['id'] ?? $obj[0] ?? null;
            }
            if (!$obj) continue;

            $type = $activity->get('type');

            // Track all activities by object
            if (!isset($allActivitiesByObject[$obj])) {
                $allActivitiesByObject[$obj] = 0;
            }
            $allActivitiesByObject[$obj]++;

            // Track likes
            if ($type === 'Like') {
                if (!isset($likesByObject[$obj])) {
                    $likesByObject[$obj] = 0;
                }
                $likesByObject[$obj]++;
            }

            // Track announces
            if ($type === 'Announce') {
                if (!isset($announcesByObject[$obj])) {
                    $announcesByObject[$obj] = 0;
                }
                $announcesByObject[$obj]++;
            }
        }

        Log::info('RecalculateActivityPubCounts: Activities grouped. Processing notes...');

        $updatedCount = 0;

        foreach ($notes as $note) {
            $id = $note->id();
            $apId = $note->get('activitypub_id');
            $absUrl = $note->absoluteUrl();

            // Resolve IDs to match against 'object' or 'in_reply_to'
            $objectIds = array_filter([$id, $apId, $absUrl]);

            // 1. Reply Count (still query-based, as replies are in notes collection)
            $replyCount = Entry::query()
                ->where('collection', 'notes')
                ->whereIn('in_reply_to', $objectIds)
                ->count();

            // 2. Like Count - OPTIMIZED: Lookup from grouped data
            $likeCount = 0;
            foreach ($objectIds as $objId) {
                $likeCount += $likesByObject[$objId] ?? 0;
            }

            // 3. Boost Count - OPTIMIZED: Lookup from grouped data
            $boostCount = 0;
            foreach ($objectIds as $objId) {
                $boostCount += $announcesByObject[$objId] ?? 0;
            }

            // 4. Related Activity Count - OPTIMIZED: Lookup from grouped data
            $relatedCount = 0;
            foreach ($objectIds as $objId) {
                $relatedCount += $allActivitiesByObject[$objId] ?? 0;
            }

            // Optimization: Only save if counts changed
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
                $updatedCount++;
            }
        }

        Log::info("RecalculateActivityPubCounts: Completed. Updated {$updatedCount} notes.");
    }
}
