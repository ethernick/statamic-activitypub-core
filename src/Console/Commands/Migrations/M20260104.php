<?php

namespace Ethernick\ActivityPubCore\Console\Commands\Migrations;

use Illuminate\Console\Command;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Illuminate\Support\Carbon;

class M20260104 extends Command
{
    protected $signature = 'activitypub:migrate:20260104';
    protected $description = 'Initial ActivityPub migration: backfills flags, blocks, likes, dates, and normalizes blueprints.';

    public function handle()
    {
        $this->info('Starting Migration 20260104...');

        $this->backfillInternalFlags();
        $this->backfillBlocksField();
        $this->backfillLikeFields();
        $this->backfillSensitiveSummaries();
        $this->backfillCorrectDates();

        $this->info('Normalizing Activity Blueprints...');
        $this->normalizeBlueprints();

        $this->info('Migration 20260104 complete.');
        return 0;
    }

    protected function normalizeBlueprints()
    {
        if (!\Statamic\Facades\Collection::find('activities')) {
            $this->warn("Collection 'activities' not found. Skipping blueprint normalization.");
            return;
        }

        $activities = Entry::query()
            ->where('collection', 'activities')
            ->get();

        $count = 0;
        $updated = 0;

        foreach ($activities as $entry) {
            $count++;
            // Check raw value to see if it's explicitly set to 'activity'
            $rawBlueprint = $entry->get('blueprint');

            if ($rawBlueprint === 'activity') {
                $this->line("Found inconsistent blueprint: {$rawBlueprint} for entry {$entry->id()}");

                $entry->set('blueprint', 'activities');
                $entry->save();
                $updated++;
            }
        }

        $this->info("Scanned {$count} activities. Updated {$updated} entries.");
    }

    protected function backfillInternalFlags()
    {
        $this->info('Backfilling internal flags...');

        // Pre-fetch user actor map
        $userActorIds = [];
        foreach (User::all() as $user) {
            $actors = $user->get('actors', []);
            foreach ($actors as $actorId) {
                $userActorIds[] = $actorId;
            }
        }
        $userActorIds = array_unique($userActorIds);

        // 1. Process Actors
        $this->safeIterateCollection('actors', function ($actor) use ($userActorIds) {
            $isInternal = false;

            if (in_array($actor->id(), $userActorIds)) {
                $isInternal = true;
            } else {
                $isInternal = $actor->get('is_internal') ?? true;
            }

            if ($actor->get('is_internal') !== $isInternal) {
                $actor->set('is_internal', $isInternal);
                $actor->saveQuietly();
            }
        });

        // 2. Process Notes, Articles, and Activities
        foreach (['notes', 'articles', 'activities'] as $collection) {
            $this->safeIterateCollection($collection, function ($entry) {
                $actorId = $entry->get('actor');
                if (is_array($actorId)) {
                    $actorId = $actorId[0] ?? null;
                }

                if ($actorId) {
                    $actor = Entry::find($actorId);
                    if ($actor) {
                        $isInternal = $actor->get('is_internal', false);
                        if ($entry->get('is_internal') !== $isInternal) {
                            $entry->set('is_internal', $isInternal);
                            $entry->saveQuietly();
                        }
                    }
                }
            });
        }
    }

    protected function backfillBlocksField()
    {
        $this->info('Backfilling blocks field...');
        $this->safeIterateCollection('actors', function ($actor) {
            if ($actor->get('blocks') === null) {
                $actor->set('blocks', []);
                $actor->saveQuietly();
            }
        });
    }

    protected function backfillLikeFields()
    {
        $this->info('Backfilling like fields...');
        foreach (['notes', 'articles'] as $collection) {
            $this->safeIterateCollection($collection, function ($entry) {
                $updated = false;
                if ($entry->get('liked_by') === null) {
                    $entry->set('liked_by', []);
                    $updated = true;
                }
                if ($entry->get('like_count') === null) {
                    $entry->set('like_count', 0);
                    $updated = true;
                }
                if ($updated) {
                    $entry->saveQuietly();
                }
            });
        }
    }

    protected function backfillSensitiveSummaries()
    {
        $this->info('Backfilling sensitive summaries...');
        $this->safeIterateCollection('notes', function ($entry) {
            $updated = false;
            $json = $entry->get('activitypub_json');

            if ($json) {
                $data = json_decode($json, true);
                if (isset($data['sensitive']) && $data['sensitive'] === true) {
                    if (!$entry->get('sensitive')) {
                        $entry->set('sensitive', true);
                        $updated = true;
                    }
                    if (empty($entry->get('summary'))) {
                        $jsonSummary = $data['summary'] ?? null;
                        $entry->set('summary', $jsonSummary ?: 'Sensitive Content');
                        $updated = true;
                    }
                }
            }
            if ($updated) {
                $entry->saveQuietly();
            }
        });
    }

    protected function backfillCorrectDates()
    {
        $this->info('Backfilling correct dates...');
        foreach (['notes', 'articles', 'activities'] as $collection) {
            $this->safeIterateCollection($collection, function ($entry) {
                $updated = false;
                $json = $entry->get('activitypub_json');
                $dateStr = null;

                if ($json) {
                    $payload = json_decode($json, true);
                    $dateStr = $payload['published'] ?? $payload['updated'] ?? $payload['object']['published'] ?? null;
                }

                if ($dateStr) {
                    try {
                        $correctDate = Carbon::parse($dateStr);
                        $currentDate = $entry->date();

                        if (!$currentDate || abs($correctDate->diffInMinutes($currentDate)) > 0) {
                            $entry->date($correctDate);
                            $entry->set('date', $correctDate->toIso8601String());
                            $updated = true;
                        }
                    } catch (\Exception $e) {
                        // ignore
                    }
                }

                if ($updated) {
                    $entry->saveQuietly();
                }
            });
        }
    }

    protected function safeIterateCollection($collection, callable $callback)
    {
        try {
            // Verify collection exists before querying
            if (!\Statamic\Facades\Collection::find($collection)) {
                $this->warn("Collection '{$collection}' not found. Skipping.");
                return;
            }

            $this->info("Processing {$collection}...");

            // Simple iteration over all entries in collection
            $query = Entry::query()->where('collection', $collection);

            $count = $query->count();
            if ($count === 0)
                return;

            $bar = $this->output->createProgressBar($count);
            $bar->start();

            $query->chunk(max(10, min(100, $count)), function ($entries) use ($callback, $bar) {
                foreach ($entries as $entry) {
                    try {
                        $callback($entry);
                    } catch (\Throwable $e) {
                        // Log error but continue
                    }
                    $bar->advance();
                }
            });

            $bar->finish();
            $this->newLine();

        } catch (\Throwable $e) {
            $this->error("Error processing collection '{$collection}': " . $e->getMessage());
        }
    }
}
