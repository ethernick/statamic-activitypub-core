<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Console\Commands\Migrations;

use Illuminate\Console\Command;
use Statamic\Facades\File;
use Statamic\Facades\YAML;
use Ethernick\ActivityPubCore\Models\BlockListEntry;
use Ethernick\ActivityPubCore\Services\ActivityPubUtils;

class M20260408_MigrateBlockListToDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'activitypub:migrate:blocklist-to-db';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate ActivityPub blocklist from settings.yaml to the database.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info("Starting migration of blocklist to database...");

        $path = ActivityPubUtils::settingsPath();
        if (!File::exists($path)) {
            $this->info("Settings file not found at [{$path}]. Nothing to migrate.");
            return 0;
        }

        $settings = YAML::parse(File::get($path));
        $rawList = $settings['blocklist'] ?? '';

        if (empty($rawList)) {
            $this->info("Blocklist is empty in settings. Nothing to migrate.");
            return 0;
        }

        $identifiers = collect(explode("\n", $rawList))
            ->map(fn($line) => strtolower(trim((string) $line)))
            ->filter()
            ->unique()
            ->values();

        $count = 0;
        $total = $identifiers->count();

        $this->info("Found {$total} entries to migrate.");

        foreach ($identifiers as $identifier) {
            // Use updateOrCreate to prevent duplicates if some are already there
            BlockListEntry::updateOrCreate(['identifier' => $identifier]);
            $count++;
        }

        $this->info("Successfully migrated {$count} entries to the database.");

        // Clear the blocklist from YAML
        $this->info("Clearing blocklist from settings.yaml...");
        unset($settings['blocklist']);
        
        // Use YAML::dump to save back
        File::put($path, YAML::dump($settings));
        
        $this->info("Migration complete.");

        return 0;
    }
}
