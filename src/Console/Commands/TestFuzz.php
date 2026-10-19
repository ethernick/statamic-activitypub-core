<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Console\Commands;

use Illuminate\Console\Command;
use Statamic\Facades\Entry;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Ethernick\ActivityPubCore\Search\ActorSearchIndex;
use Ethernick\ActivityPubCore\Search\SearchTokenExtractor;

class TestFuzz extends Command
{
    /**
     * Non-destructive test prefix scoping all generated data.
     */
    protected const FUZZ_PREFIX = 'fuzz-test-';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'activitypub:test:fuzz
                            {--count=2000 : Number of synthetic entries to generate}
                            {--actors=20 : Number of synthetic actors to generate}
                            {--cleanup : Purge all fuzz test data from collections}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate or clean up high-volume synthetic ActivityPub data for scale and performance testing';

    public function handle(): int
    {
        if ($this->option('cleanup')) {
            return $this->cleanupFuzzData();
        }

        return $this->generateFuzzData();
    }

    protected function cleanupFuzzData(): int
    {
        $this->info('Cleaning up all fuzz test data (prefix: ' . self::FUZZ_PREFIX . ')...');

        $collections = ['notes', 'activities', 'actors', 'articles', 'polls'];
        $totalDeleted = 0;

        foreach ($collections as $collection) {
            $entries = Entry::query()
                ->where('collection', $collection)
                ->get()
                ->filter(function ($entry) {
                    $slug = (string) $entry->slug();
                    $id = (string) $entry->id();
                    return str_starts_with($slug, self::FUZZ_PREFIX) || str_starts_with($id, self::FUZZ_PREFIX);
                });

            $count = $entries->count();
            if ($count > 0) {
                $this->line("Purging {$count} entries from collection [{$collection}]...");
                foreach ($entries as $entry) {
                    // Also delete companion sidecar JSON file if present
                    $sidecar = base_path("content/activitypub/{$collection}/{$entry->id()}.json");
                    if (File::exists($sidecar)) {
                        File::delete($sidecar);
                    }

                    $entry->delete();
                    $totalDeleted++;
                }
            }
        }

        // Clear cached actor map
        ActorSearchIndex::clear();

        $this->newLine();
        $this->info("✓ Cleaned up {$totalDeleted} fuzz test entries.");
        return 0;
    }

    protected function generateFuzzData(): int
    {
        $count = (int) $this->option('count');
        $actorCount = (int) $this->option('actors');

        $this->info("=====================================================");
        $this->info("  ActivityPub Fuzz & Scale Generator                 ");
        $this->info("=====================================================");
        $this->line("Target Entry Count: <info>{$count}</info>");
        $this->line("Target Actors:      <info>{$actorCount}</info>");
        $this->line("Scoped Prefix:      <comment>" . self::FUZZ_PREFIX . "</comment>");
        $this->newLine();

        // 1. Create Synthetic Actors
        $actorIds = $this->createSyntheticActors($actorCount);
        if (empty($actorIds)) {
            $this->error('Failed to create or retrieve actors.');
            return 1;
        }

        // 2. Generate Synthetic Notes and Activities
        $this->createSyntheticEntries($count, $actorIds);

        // 3. Clear and warm actor index
        ActorSearchIndex::clear();
        $t0 = microtime(true);
        ActorSearchIndex::getLookupMap();
        $mapMs = round((microtime(true) - $t0) * 1000, 2);

        $this->newLine();
        $this->info("✓ Fuzz dataset creation completed!");
        $this->line("Warmed actor index in <info>{$mapMs} ms</info>.");
        $this->newLine();
        $this->line("Next steps:");
        $this->line("  1. Run <info>php artisan statamic:stache:warm</info>");
        $this->line("  2. Benchmark with <info>php artisan activitypub:test:search</info>");
        $this->line("  3. When done, purge using <info>php artisan activitypub:test:fuzz --cleanup</info>");

        return 0;
    }

    protected function createSyntheticActors(int $count): array
    {
        $this->info("Creating {$count} synthetic actors...");
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $actorIds = [];
        $domains = ['mastodon.social', 'fosstodon.org', 'pixelfed.social', 'fediverse.space', 'worktree.ca'];

        for ($i = 1; $i <= $count; $i++) {
            $slug = self::FUZZ_PREFIX . "actor-{$i}";
            $existing = Entry::query()->where('collection', 'actors')->where('slug', $slug)->first();
            if ($existing) {
                $actorIds[] = $existing->id();
                $bar->advance();
                continue;
            }

            $domain = $domains[$i % count($domains)];
            $username = "fuzzuser{$i}";
            $actorId = "fuzz-actor-uuid-{$i}";

            $actor = Entry::make()
                ->collection('actors')
                ->id($actorId)
                ->slug($slug)
                ->data([
                    'title' => "Fuzz User {$i}",
                    'username' => $username,
                    'name' => "Fuzz User {$i}",
                    'handle' => "@{$username}@{$domain}",
                    'domain' => $domain,
                    'actor_type' => ($i % 5 === 0) ? 'Service' : 'Person',
                    'activitypub_id' => "https://{$domain}/users/{$username}",
                    'inbox' => "https://{$domain}/users/{$username}/inbox",
                    'outbox' => "https://{$domain}/users/{$username}/outbox",
                    'summary' => "Simulated synthetic test actor #{$i} for performance benchmarking.",
                ]);

            $actor->save();
            $actorIds[] = $actor->id();
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        return $actorIds;
    }

    protected function createSyntheticEntries(int $count, array $actorIds): void
    {
        $this->info("Creating {$count} synthetic notes & activities...");
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $tagPool = [
            'statamic', 'fediverse', 'activitypub', 'laravel', 'php',
            'benchmarking', 'scale', 'fuzzing', 'performance', 'indexing'
        ];

        $wordPool = [
            'speed', 'optimization', 'latency', 'queries', 'database', 'stache',
            'memory', 'response', 'payload', 'testing', 'federation', 'mastodon'
        ];

        $now = Carbon::now();

        for ($i = 1; $i <= $count; $i++) {
            $slug = self::FUZZ_PREFIX . "note-{$i}";
            $id = self::FUZZ_PREFIX . "note-id-{$i}";

            $actorId = $actorIds[$i % count($actorIds)];
            $assignedTags = [
                $tagPool[$i % count($tagPool)],
                $tagPool[($i + 3) % count($tagPool)],
            ];

            // Mix realistic keywords in content
            $words = [
                $wordPool[$i % count($wordPool)],
                $wordPool[($i + 2) % count($wordPool)],
                $wordPool[($i + 5) % count($wordPool)],
            ];
            $content = "Synthetic performance test entry #{$i}. " . implode(' ', $words) . " #" . implode(' #', $assignedTags);

            // Pre-extract search tokens for instant indexing
            $tokens = SearchTokenExtractor::extract($content);

            // Stagger dates backwards over 90 days
            $entryDate = $now->copy()->subMinutes($i * 15);

            $entry = Entry::make()
                ->collection('notes')
                ->id($id)
                ->slug($slug)
                ->date($entryDate)
                ->data([
                    'title' => "Synthetic Note #{$i}",
                    'content' => $content,
                    'summary' => "Summary for note #{$i}",
                    'actor' => [$actorId],
                    'tags' => $assignedTags,
                    'search_tokens' => $tokens,
                    'is_internal' => false,
                    'activitypub_id' => "https://remote.social/notes/{$i}",
                    'type' => 'Note',
                ]);

            $entry->save();
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
    }
}
