<?php

namespace Ethernick\ActivityPubCore\Console\Commands;

use Illuminate\Console\Command;
use Statamic\Facades\Entry;

class DiagnoseBadData extends Command
{
    protected $signature = 'activitypub:diagnose-data {--fix : Attempt to fix found issues}';
    protected $description = 'Scan for entries with array data in fields expected to be strings.';

    public function handle()
    {
        $this->info('Scanning notes for array data...');

        $notes = Entry::whereCollection('notes');
        $bar = $this->output->createProgressBar($notes->count());
        $bar->start();

        $issues = [];

        foreach ($notes as $note) {
            $id = $note->id();

            // Check in_reply_to
            $inReplyTo = $note->get('in_reply_to');
            if (is_array($inReplyTo)) {
                $issues[] = [
                    'id' => $id,
                    'field' => 'in_reply_to',
                    'value' => json_encode($inReplyTo)
                ];
            }

            // Check actor (should be array actually? Statamic stores relations as array often, but we treat as single sometimes)
            // The codebase seems to expect single actor handle/id in some places (ReplyController line 18 findActor($handle))
            // But ReplyController line 63: Entry::find($reply->get('actor')) -> implies it expects ID.
            // If it's an array of IDs, find() might handle it if it's the first arg, wait. find() takes $id.

            // The crash happened on 'in_reply_to' in ReplyController and InboxController.
            // Let's focus on that.

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        if (count($issues) > 0) {
            $this->error('Found ' . count($issues) . ' entries with unexpected array data!');
            foreach ($issues as $issue) {
                $this->line("Entry {$issue['id']} has array in {$issue['field']}: {$issue['value']}");

                if ($this->option('fix')) {
                    $entry = Entry::find($issue['id']);
                    if ($entry && $issue['field'] === 'in_reply_to') {
                        $currentVal = $entry->get('in_reply_to');
                        if (is_array($currentVal)) {
                            // Extract ID or URL or first item
                            $newVal = $currentVal['id'] ?? $currentVal['url'] ?? $currentVal[0] ?? null;
                            if ($newVal && is_string($newVal)) {
                                $entry->set('in_reply_to', $newVal);
                                $entry->save();
                                $this->info("Fixed entry {$issue['id']}: in_reply_to set to $newVal");
                            } else {
                                $this->warn("Could not determine fix for entry {$issue['id']}");
                            }
                        }
                    }
                }
            }
            // Return 0 if fixed?
            if ($this->option('fix')) {
                $this->info("Fixes applied.");
                return 0;
            }
            return 1;
        }

        $this->info('No data issues found.');
        return 0;
    }
}
