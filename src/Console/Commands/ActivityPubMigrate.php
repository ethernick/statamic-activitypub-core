<?php

namespace Ethernick\ActivityPubCore\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ActivityPubMigrate extends Command
{
    protected $signature = 'activitypub:migrate';
    protected $description = 'Run pending ActivityPub migrations/backfills.';

    protected $migrationPath = 'addons/ethernick/ActivityPubCore/src/Console/Commands/Migrations';
    protected $historyPath = 'app/activitypub/migrations.json';

    public function handle()
    {
        $this->info('Checking for pending ActivityPub migrations...');

        $executed = $this->getExecutedMigrations();
        $pending = $this->getPendingMigrations($executed);

        if (empty($pending)) {
            $this->info('Nothing to migrate. All caught up.');
            return 0;
        }

        $this->info('Found ' . count($pending) . ' pending migrations.');

        foreach ($pending as $class => $file) {
            $this->runMigration($class);
            $executed[] = $class;
            $this->saveExecutedMigrations($executed);
        }

        $this->info('All migrations executed successfully.');
        return 0;
    }

    protected function getExecutedMigrations()
    {
        if (File::exists(storage_path($this->historyPath))) {
            return json_decode(File::get(storage_path($this->historyPath)), true) ?? [];
        }
        return [];
    }

    protected function saveExecutedMigrations(array $executed)
    {
        // Ensure directory exists
        $dir = dirname(storage_path($this->historyPath));
        if (!File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        File::put(storage_path($this->historyPath), json_encode($executed, JSON_PRETTY_PRINT));
    }

    protected function getPendingMigrations(array $executed)
    {
        $path = base_path($this->migrationPath);
        if (!File::exists($path)) {
            return [];
        }

        $files = File::files($path);
        $migrations = [];

        foreach ($files as $file) {
            $filename = $file->getFilename();
            // Expect MYYYYMMDD.php or M_YYYY_MM_DD.php etc.
            // Just matching .php files is probably safest, or specific prefix
            if (str_ends_with($filename, '.php')) {
                $className = 'Ethernick\\ActivityPubCore\\Console\\Commands\\Migrations\\' . $file->getFilenameWithoutExtension();

                // Check if executed (we track by Class Name or Filename - let's use Class Name)
                if (!in_array($className, $executed)) {
                    $migrations[$className] = $file->getRealPath();
                }
            }
        }

        // Sort by class name / filename naturally (dates should sort correctly)
        ksort($migrations);

        return $migrations;
    }

    protected function runMigration($className)
    {
        $this->info("Running migration: {$className}");

        // We can instantiate and run, or call via Artisan if they are registered commands.
        // Since we didn't force them to be registered in Kernel, we should instantiate them.
        // However, standard Commands work best when called via Artisan call() if registered.
        // If they are NOT registered, we can manually run them.

        // Let's try to resolve it from container. If it's not bound, we make it.
        try {
            $command = app($className);
            $command->setLaravel($this->laravel);
            $command->setApplication($this->getApplication());
            $this->getOutput()->write($command->run(
                new \Symfony\Component\Console\Input\ArrayInput([]),
                $this->getOutput()
            ));
        } catch (\Exception $e) {
            $this->error("Failed to run $className: " . $e->getMessage());
            throw $e;
        }
    }
}
