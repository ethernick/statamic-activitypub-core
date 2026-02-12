<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Statamic\Facades\Entry;
use Statamic\Events\EntrySaved;

class RegenerateActivityPubJson extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'activitypub:regenerate-json {--collection=* : The collections to regenerate (defaults to notes, articles)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Regenerate ActivityPub JSON for entries without creating new Activities';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('Starting JSON regeneration...');

        // 1. Disable the AutoGenerate listener (and any other Saved listeners) to prevent spam
        // We specifically want to stop AutoGenerateActivityListener which listens on EntrySaved.
        // But we DO want ActivityPubListener (which listens on EntrySaving) to run, as that does the JSON generation.
        Event::forget(EntrySaved::class);
        $this->info('Disabled EntrySaved events to prevent activity spam.');

        // Determine collections
        $collections = $this->option('collection');
        if (empty($collections)) {
            $collections = ['notes', 'articles'];
        }

        $this->info('Targeting collections: ' . implode(', ', $collections));

        $entries = Entry::query()
            ->whereIn('collection', $collections)
            ->get();

        $count = $entries->count();
        if ($count === 0) {
            $this->info('No entries found.');
            return 0;
        }

        $this->info("Found {$count} entries.");
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        foreach ($entries as $entry) {
            // Saving triggers EntrySaving -> ActivityPubListener -> generates JSON
            // But EntrySaved is suppressed, so no new Activity is created.
            $entry->save();
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Regeneration complete!');

        return 0;
    }
}
