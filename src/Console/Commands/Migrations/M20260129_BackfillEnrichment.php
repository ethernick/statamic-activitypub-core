<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Console\Commands\Migrations;

use Illuminate\Console\Command;
use Statamic\Facades\Entry;
use Ethernick\ActivityPubCore\Services\OEmbed;
use Ethernick\ActivityPubCore\Services\LinkPreview;

class M20260129_BackfillEnrichment extends Command
{
    protected $signature = 'activitypub:migrate:backfill-enrichment
                            {--collection=* : The collections to process (defaults to notes, polls)}
                            {--limit= : Maximum number of entries to process}
                            {--force : Process all entries, even those already enriched}';

    protected $description = '[Migration 20260129] Backfill OEmbed and link preview data for existing notes';

    public function handle(): int
    {
        $this->info('Starting enrichment backfill migration...');

        // Determine collections
        $collections = $this->option('collection');
        if (empty($collections)) {
            $collections = ['notes', 'polls'];
        }

        $this->info('Targeting collections: ' . implode(', ', $collections));

        // Build query
        $query = Entry::query()->whereIn('collection', $collections);

        // Unless --force, only process entries without enrichment data
        if (!$this->option('force')) {
            $this->info('Filtering to entries without existing enrichment data...');
        }

        $entries = $query->get();

        // Filter in memory if not forcing
        if (!$this->option('force')) {
            $entries = $entries->filter(function ($entry) {
                $oembedData = $entry->get('oembed_data');
                $linkPreview = $entry->get('link_preview');

                // Process if both are missing (null means not yet processed)
                return $oembedData === null && empty($linkPreview);
            });
        }

        // Apply limit if specified
        $limit = $this->option('limit');
        if ($limit) {
            $entries = $entries->take((int) $limit);
        }

        $count = $entries->count();
        if ($count === 0) {
            $this->info('No entries found to process.');
            return 0;
        }

        $this->info("Processing {$count} entries...");
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $stats = [
            'oembed_found' => 0,
            'oembed_none' => 0,
            'link_preview_found' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        foreach ($entries as $entry) {
            try {
                $needsSave = false;
                $content = $entry->get('content');

                if (!$content) {
                    $stats['skipped']++;
                    $bar->advance();
                    continue;
                }

                // Check if content is already HTML (external notes) or Markdown (internal notes)
                $isInternal = $entry->get('is_internal', false);
                $htmlContent = $isInternal
                    ? \Statamic\Facades\Markdown::parse((string) $content)
                    : (string) $content;

                // 1. Try OEmbed first (rich embeds like YouTube, Twitter)
                $existingOembed = $entry->get('oembed_data');
                $hasOembed = false;

                if ($existingOembed === null) {
                    $oembedData = OEmbed::resolve($htmlContent);

                    if ($oembedData) {
                        $entry->set('oembed_data', $oembedData);
                        $stats['oembed_found']++;
                        $hasOembed = true;
                        $needsSave = true;
                    } else {
                        // Store false to indicate "no oembed found"
                        $entry->set('oembed_data', false);
                        $stats['oembed_none']++;
                        $needsSave = true;
                    }
                } elseif ($existingOembed !== false) {
                    // Already has oembed data
                    $hasOembed = true;
                }

                // 2. Try link preview ONLY if no oembed
                if (!$hasOembed) {
                    $existingPreview = $entry->get('link_preview');
                    if (empty($existingPreview)) {
                        if ($url = LinkPreview::extractUrl($htmlContent)) {
                            $previewData = LinkPreview::fetch($url);

                            if ($previewData) {
                                $entry->set('link_preview', $previewData);
                                $stats['link_preview_found']++;
                                $needsSave = true;
                            }
                        }
                    }
                }

                // Save if any changes
                if ($needsSave) {
                    $entry->saveQuietly();
                }

            } catch (\Exception $e) {
                $stats['errors']++;
                $this->newLine();
                $this->error("Error processing {$entry->id()}: " . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->newLine();

        // Display statistics
        $this->info('Backfill migration complete!');
        $this->table(
            ['Metric', 'Count'],
            [
                ['OEmbed Found', $stats['oembed_found']],
                ['No OEmbed', $stats['oembed_none']],
                ['Link Previews Found', $stats['link_preview_found']],
                ['Skipped (no content)', $stats['skipped']],
                ['Errors', $stats['errors']],
            ]
        );

        return 0;
    }
}
