<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Console\Commands\Migrations;

use Illuminate\Console\Command;
use Statamic\Facades\Entry;
use Ethernick\ActivityPubCore\Search\SearchTokenExtractor;
use Ethernick\ActivityPubCore\Services\ActivityPubUtils;
use Statamic\Facades\File;
use Statamic\Facades\YAML;

class M20261004_BackfillSearchTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'activitypub:migrate:search-tokens';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '[Migration 20261004] Backfill search_tokens on existing content entries for fast Stache query resolution.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('Starting one-time search_tokens backfill migration...');

        $settings = $this->getSettings();
        $targetCollections = [];
        foreach ($settings as $key => $config) {
            if (is_array($config) && ($config['enabled'] ?? false) && $key !== 'hashtags' && $key !== 'activities' && $key !== 'actors') {
                $targetCollections[] = $key;
            }
        }
        if (empty($targetCollections)) {
            $targetCollections = ['notes', 'articles', 'polls', 'events', 'places'];
        }

        $this->info('Target collections: ' . implode(', ', $targetCollections));

        $entries = Entry::query()
            ->whereIn('collection', $targetCollections)
            ->get();

        // Only process entries missing search_tokens
        $pending = $entries->filter(function ($entry) {
            $tokens = $entry->get('search_tokens');
            return empty($tokens) || !is_array($tokens);
        });

        $count = $pending->count();
        if ($count === 0) {
            $this->info('All entries already have search_tokens indexed. Skipping.');
            return 0;
        }

        $this->info("Found {$count} entries lacking search_tokens. Backfilling...");
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $updated = 0;
        foreach ($pending as $entry) {
            $tokens = SearchTokenExtractor::extractFromEntry($entry);
            if (!empty($tokens)) {
                $entry->set('search_tokens', $tokens);
                // Save quietly so we don't trigger outbox activities on historical content
                $entry->saveQuietly();
                $updated++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Search token backfill complete! Updated {$updated} entries.");

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
