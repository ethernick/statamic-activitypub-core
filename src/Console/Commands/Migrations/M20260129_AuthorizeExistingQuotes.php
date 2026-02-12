<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Console\Commands\Migrations;

use Illuminate\Console\Command;
use Statamic\Facades\Entry;

class M20260129_AuthorizeExistingQuotes extends Command
{
    protected $signature = 'activitypub:migrate:authorize-existing-quotes {--dry-run : Show what would be done without making changes}';

    protected $description = '[Migration 20260129] Send QuoteRequests for existing quotes that don\'t have authorization (FEP-044f compliance)';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $this->info('Starting quote authorization migration...');

        // Find all internal notes with quote_of but no authorization status
        $quotes = Entry::query()
            ->where('collection', 'notes')
            ->where('is_internal', true)
            ->get()
            ->filter(function ($note) {
                $quoteOf = $note->get('quote_of');
                $authStatus = $note->get('quote_authorization_status');
                return $quoteOf && !$authStatus;
            });

        if ($quotes->isEmpty()) {
            $this->info('No quotes found that need authorization.');
            return 0;
        }

        $this->info("Found {$quotes->count()} quote(s) without authorization:");

        $processedCount = 0;
        $skippedCount = 0;

        foreach ($quotes as $quote) {
            $quoteOf = $quote->get('quote_of');
            if (is_array($quoteOf)) {
                $quoteOf = $quoteOf[0] ?? null;
            }

            if (!$quoteOf) {
                $this->warn("  ✗ Skipping {$quote->id()} - invalid quote_of");
                $skippedCount++;
                continue;
            }

            $quotedEntry = Entry::find($quoteOf);
            if (!$quotedEntry) {
                $this->warn("  ✗ Skipping {$quote->id()} - quoted entry not found");
                $skippedCount++;
                continue;
            }

            // If quoting an internal note, auto-accept
            if ($quotedEntry->get('is_internal') !== false) {
                $this->line("  → {$quote->id()} quotes internal note - auto-accepting");
                if (!$isDryRun) {
                    $quote->set('quote_authorization_status', 'accepted');
                    $quote->saveQuietly();
                }
                $processedCount++;
                continue;
            }

            // External quote - send QuoteRequest
            $quotedUrl = $quotedEntry->get('activitypub_id') ?: $quotedEntry->absoluteUrl();
            $this->line("  → {$quote->id()} quotes {$quotedUrl}");

            if (!$isDryRun) {
                $quote->set('quote_authorization_status', 'pending');
                $quote->saveQuietly();

                \Ethernick\ActivityPubCore\Jobs\SendQuoteRequest::dispatch($quote->id())
                    ->onQueue('activitypub-outbox');
            }

            $processedCount++;
        }

        $this->newLine();

        if ($isDryRun) {
            $this->info("DRY RUN: Would process {$processedCount} quote(s)");
            if ($skippedCount > 0) {
                $this->warn("DRY RUN: Would skip {$skippedCount} quote(s)");
            }
        } else {
            $this->info("✓ Processed {$processedCount} quote(s)");
            if ($skippedCount > 0) {
                $this->warn("✗ Skipped {$skippedCount} quote(s)");
            }
            $this->info('QuoteRequests have been queued. Check logs for delivery status.');
        }

        return 0;
    }
}
