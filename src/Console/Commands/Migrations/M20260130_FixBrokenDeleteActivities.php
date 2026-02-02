<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Console\Commands\Migrations;

use Illuminate\Console\Command;
use Statamic\Facades\Entry;

class M20260130_FixBrokenDeleteActivities extends Command
{
    protected $signature = 'activitypub:migrate:fix-broken-deletes {--dry-run : Show what would be done without making changes}';

    protected $description = '[Migration 20260130] Fix Delete activities with null objects by extracting UUIDs from titles';

    public function handle()
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $this->info('Finding broken Delete activities...');

        // Find all Delete activities
        $deleteActivities = Entry::query()
            ->where('collection', 'activities')
            ->where('type', 'Delete')
            ->get()
            ->filter(function ($activity) {
                // Check if object is null in JSON
                $json = $activity->get('activitypub_json');
                if (!$json) {
                    return false;
                }

                $data = is_string($json) ? json_decode($json, true) : $json;
                return isset($data['object']) && $data['object'] === null;
            });

        if ($deleteActivities->isEmpty()) {
            $this->info('No broken Delete activities found.');
            return 0;
        }

        $this->info("Found {$deleteActivities->count()} broken Delete activity(ies):");

        $fixedCount = 0;
        $skippedCount = 0;

        foreach ($deleteActivities as $activity) {
            $title = $activity->get('title');

            // Extract UUID from title (format: "Delete <uuid>")
            if (preg_match('/Delete\s+([a-f0-9-]{36})/', $title, $matches)) {
                $uuid = $matches[1];
                $url = url("/notes/{$uuid}");

                $this->line("  → {$activity->id()}: {$title}");
                $this->line("     Will set deleted_object_url to: {$url}");

                if (!$isDryRun) {
                    $activity->set('deleted_object_url', $url);
                    $activity->save(); // This will trigger ActivityPubListener to regenerate JSON
                }

                $fixedCount++;
            } else {
                $this->warn("  ✗ {$activity->id()}: Could not extract UUID from title: {$title}");
                $skippedCount++;
            }
        }

        $this->newLine();

        if ($isDryRun) {
            $this->info("DRY RUN: Would fix {$fixedCount} activity(ies)");
            if ($skippedCount > 0) {
                $this->warn("DRY RUN: Would skip {$skippedCount} activity(ies)");
            }
        } else {
            $this->info("✓ Fixed {$fixedCount} activity(ies)");
            if ($skippedCount > 0) {
                $this->warn("✗ Skipped {$skippedCount} activity(ies)");
            }

            $this->newLine();
            $this->info('To resend these fixed activities, run:');
            $this->line('  php artisan activitypub:resend-activity <activity-id>');
        }

        return 0;
    }
}
