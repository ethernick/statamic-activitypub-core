<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Console\Commands;

use Illuminate\Console\Command;
use Statamic\Facades\Entry;

class DiagnoseThreads extends Command
{
    protected $signature = 'activitypub:diagnose-threads';
    protected $description = 'Diagnose circular references in ActivityPub threads.';

    public function handle(): int
    {
        $this->info('Scanning for circular thread references...');

        $notes = Entry::whereCollection('notes');
        $bar = $this->output->createProgressBar($notes->count());
        $bar->start();

        $cycles = [];

        foreach ($notes as $note) {
            $chain = [$note->id()];
            $current = $note;

            while ($parentId = $current->get('in_reply_to')) {
                // If parent ID matches any ID in our current chain, we found a cycle
                if (in_array($parentId, $chain)) {
                    $chain[] = $parentId; // Add it to show the full circle
                    $cycles[] = $chain;
                    break;
                }

                // Add parent to chain
                $chain[] = $parentId;

                // Load parent
                $parent = Entry::find($parentId);

                // If parent likely external (URL) or not found, stop checking this branch
                if (!$parent) {
                    break;
                }

                $current = $parent;

                // Safety break for very deep threads to avoid infinite run if we miss a cycle
                if (count($chain) > 100) {
                    break;
                }
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        if (count($cycles) > 0) {
            $this->error('Found ' . count($cycles) . ' circular references!');
            foreach ($cycles as $index => $chain) {
                $this->line("Cycle #" . ($index + 1) . ": " . implode(' -> ', $chain));
            }
            return 1;
        }

        $this->info('No circular references found.');
        return 0;
    }
}
