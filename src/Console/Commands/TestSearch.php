<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Console\Commands;

use Illuminate\Console\Command;
use Statamic\Facades\Entry;
use Ethernick\ActivityPubCore\Search\ActorSearchIndex;
use Ethernick\ActivityPubCore\Search\SearchTokenExtractor;

class TestSearch extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'activitypub:test:search {query? : Optional search term, @handle, or #tag to test}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Benchmark and verify the functionality and speed of ActivityPub search indexes';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('=====================================================');
        $this->info('  ActivityPub Search Index Performance & Health Test ');
        $this->info('=====================================================');
        $this->newLine();

        $this->testActorIndex();
        $this->testSearchTokenCoverage();

        $query = $this->argument('query');
        if ($query) {
            $this->benchmarkQuery($query);
        } else {
            $this->runDefaultBenchmarkSuite();
        }

        $this->newLine();
        $this->info('✓ Search test suite completed.');
        return 0;
    }

    protected function testActorIndex(): void
    {
        $this->line('<fg=yellow;options=bold>1. Actor Fast Lookup Map</>');

        // Measure cold/warm lookup
        ActorSearchIndex::clear();
        $t0 = microtime(true);
        $map = ActorSearchIndex::getLookupMap();
        $coldMs = round((microtime(true) - $t0) * 1000, 2);

        $t1 = microtime(true);
        ActorSearchIndex::getLookupMap();
        $warmMs = round((microtime(true) - $t1) * 1000, 2);

        $actorsCount = Entry::query()->where('collection', 'actors')->count();
        $keysCount = count($map);

        $this->table(
            ['Metric', 'Value', 'Status'],
            [
                ['Total Actor Entries', (string) $actorsCount, $actorsCount > 0 ? '✓ OK' : '⚠ No actors found'],
                ['Indexed Key Permutations', (string) $keysCount, $keysCount > 0 ? '✓ OK' : '⚠ Empty index'],
                ['Cold Index Build Time', "{$coldMs} ms", $coldMs < 100 ? '✓ Fast' : '⚠ Slow (>100ms)'],
                ['Cached Retrieval Time', "{$warmMs} ms", $warmMs < 5 ? '✓ Microsecond' : '⚠ Normal'],
            ]
        );
        $this->newLine();
    }

    protected function testSearchTokenCoverage(): void
    {
        $this->line('<fg=yellow;options=bold>2. Search Token Index Coverage</>');

        $collections = \Ethernick\ActivityPubCore\Services\ActivityPubTypes::getInboxCollections();
        // Omit static pages from ActivityPub token coverage check
        $collections = array_values(array_filter($collections, fn($c) => $c !== 'pages'));

        $totalEntries = Entry::query()->whereIn('collection', $collections)->count();
        $indexedEntries = Entry::query()
            ->whereIn('collection', $collections)
            ->whereNotNull('search_tokens')
            ->count();

        $coveragePercent = $totalEntries > 0 ? round(($indexedEntries / $totalEntries) * 100, 1) : 100;
        $status = $coveragePercent >= 90 ? '✓ Healthy' : '⚠ Run: php artisan activitypub:migrate';

        $this->table(
            ['Metric', 'Value', 'Status'],
            [
                ['Target Collections', implode(', ', $collections), '✓ OK'],
                ['Total Target Entries', (string) $totalEntries, '✓ OK'],
                ['Entries with search_tokens', (string) $indexedEntries, $status],
                ['Index Coverage', "{$coveragePercent}%", $status],
            ]
        );

        if ($coveragePercent < 90 && $totalEntries > 0) {
            $this->warn("! {$indexedEntries}/{$totalEntries} entries have indexed tokens. Run 'php artisan activitypub:migrate' to backfill.");
        }
        $this->newLine();
    }

    protected function runDefaultBenchmarkSuite(): void
    {
        $this->line('<fg=yellow;options=bold>3. Standard Query Benchmark Suite</>');

        // Sample queries
        $firstActor = Entry::query()->where('collection', 'actors')->first();
        $handleQuery = $firstActor ? '@' . ($firstActor->slug() ?: 'test') : '@admin';

        $queries = [
            'Actor Filter' => $handleQuery,
            'Tag Filter' => '#statamic',
            'Word Search' => 'hello',
            'Multi-word' => 'activitypub fediverse',
        ];

        $rows = [];
        foreach ($queries as $label => $testQuery) {
            $result = $this->measureQueryExecution($testQuery);
            $rows[] = [
                $label,
                $testQuery,
                $result['matched_count'],
                "{$result['elapsed_ms']} ms",
                $result['elapsed_ms'] < 100 ? '✓ Fast' : ($result['elapsed_ms'] < 300 ? '✓ Acceptable' : '⚠ Slow'),
            ];
        }

        $this->table(['Type', 'Query', 'Matches', 'Latency', 'Rating'], $rows);
    }

    protected function benchmarkQuery(string $query): void
    {
        $this->line("<fg=yellow;options=bold>3. Custom Query Benchmark: [{$query}]</>");
        $result = $this->measureQueryExecution($query);

        $this->table(
            ['Query', 'Matches', 'Execution Time', 'Rating'],
            [
                [
                    $query,
                    $result['matched_count'],
                    "{$result['elapsed_ms']} ms",
                    $result['elapsed_ms'] < 100 ? '✓ Fast' : ($result['elapsed_ms'] < 300 ? '✓ Acceptable' : '⚠ Slow'),
                ]
            ]
        );
    }

    protected function measureQueryExecution(string $search): array
    {
        $inboxCollections = array_values(array_filter(
            \Ethernick\ActivityPubCore\Services\ActivityPubTypes::getInboxCollections(),
            fn($c) => $c !== 'pages'
        ));

        $t0 = microtime(true);

        $query = Entry::query()->whereIn('collection', $inboxCollections);

        if (str_starts_with($search, '@')) {
            $rawHandle = ltrim($search, '@');
            $handleLower = strtolower($rawHandle);
            $extractedUsername = explode('@', $handleLower)[0] ?? $handleLower;

            $matchedActorIds = ActorSearchIndex::findActorIds($rawHandle);

            if (!empty($matchedActorIds)) {
                $query->where(function ($q) use ($matchedActorIds) {
                    $q->whereJsonOverlaps('actor', $matchedActorIds)
                      ->orWhereIn('actor', $matchedActorIds);
                });
            } else {
                $query->where(function ($q) use ($handleLower, $extractedUsername) {
                    $q->where('actor', 'like', "%{$handleLower}%")
                      ->orWhere('actor_name', 'like', "%{$handleLower}%");

                    if ($extractedUsername !== '' && $extractedUsername !== $handleLower) {
                        $q->orWhere('actor', 'like', "%{$extractedUsername}%")
                          ->orWhere('actor_name', 'like', "%{$extractedUsername}%");
                    }
                });
            }
        } elseif (str_starts_with($search, '#')) {
            $rawTag = ltrim($search, '#');
            $tagLower = strtolower($rawTag);
            $tagUpper = strtoupper($rawTag);

            $query->where(function ($q) use ($tagLower, $tagUpper) {
                $q->whereJsonOverlaps('tags', [$tagLower, $tagUpper, ucfirst($tagLower)])
                  ->orWhere('tags', 'like', "%{$tagLower}%")
                  ->orWhere('content', 'like', "%#{$tagLower}%");
            });
        } else {
            $tokens = SearchTokenExtractor::extract($search);
            $needle = strtolower($search);

            if (!empty($tokens)) {
                foreach ($tokens as $token) {
                    $query->whereJsonOverlaps('search_tokens', [$token]);
                }
            } else {
                $query->where(function ($q) use ($needle) {
                    $q->where('title', 'like', "%{$needle}%")
                      ->orWhere('content', 'like', "%{$needle}%")
                      ->orWhere('summary', 'like', "%{$needle}%")
                      ->orWhere('tags', 'like', "%{$needle}%")
                      ->orWhereJsonOverlaps('tags', [$needle, strtoupper($needle), ucfirst($needle)]);
                });
            }
        }

        $matchedCount = $query->count();
        $elapsedMs = round((microtime(true) - $t0) * 1000, 2);

        return [
            'matched_count' => $matchedCount,
            'elapsed_ms' => $elapsedMs,
        ];
    }
}
