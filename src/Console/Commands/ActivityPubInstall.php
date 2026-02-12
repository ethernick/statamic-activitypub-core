<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Statamic\Facades\Collection;
use Statamic\Facades\File;
use Statamic\Facades\YAML;
use Statamic\Facades\User;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
class ActivityPubInstall extends Command
{
    protected $signature = 'activitypub:install';
    protected $description = 'Install and configure ActivityPub collections.';

    public function handle(): void
    {
        $this->info('Installing ActivityPub...');

        // Step 1: Set up database queue infrastructure
        $this->setupQueueTables();

        // Step 2: Run ActivityPub-specific migrations
        $this->runActivityPubMigrations();

        // Step 3: Publish frontend assets
        $this->publishAssets();

        $settingsPath = resource_path('settings/activitypub.yaml');
        $settings = [];

        if (File::exists($settingsPath)) {
            $settings = YAML::parse(File::get($settingsPath));
        }

        $this->ensureTaxonomy();

        $requiredTypes = [
            'Person' => [
                'default_name' => 'Actors',
                'handle_suggestion' => 'actors',
            ],
            'Note' => [
                'default_name' => 'Notes',
                'handle_suggestion' => 'notes',
            ],
            'Activity' => [
                'default_name' => 'Activities',
                'handle_suggestion' => 'activities',
            ],
        ];

        foreach ($requiredTypes as $type => $config) {
            $this->configureType($type, $config, $settings);
        }

        File::put($settingsPath, YAML::dump($settings));

        // Configure User Blueprint if Person type has a collection
        $personHandle = null;
        foreach ($settings as $handle => $config) {
            if (is_array($config) && ($config['type'] ?? '') === 'Person') {
                $personHandle = $handle;
                break;
            }
        }

        if ($personHandle) {
            $this->configureUserBlueprint($personHandle);
            $this->createFirstProfile($personHandle);
        }

        $this->info('ActivityPub installation complete!');
        $this->comment('Now go! Go wildly into the fediverse!');
    }

    protected function configureUserBlueprint(string $collectionHandle): void
    {
        $blueprintPath = resource_path('blueprints/user.yaml');

        if (!File::exists($blueprintPath)) {
            $this->warn("User blueprint not found at [{$blueprintPath}]. Skipping Actor field configuration.");
            return;
        }

        $blueprint = YAML::parse(File::get($blueprintPath));
        $fields = $blueprint['fields'] ?? [];

        // Check if 'actors' field already exists
        foreach ($fields as $field) {
            if (($field['handle'] ?? '') === 'actors') {
                // $this->info("User blueprint already has an 'actors' field.");
                return;
            }
        }

        $this->info("Adding 'actors' field to User blueprint...");

        $fields[] = [
            'handle' => 'actors',
            'field' => [
                'type' => 'entries',
                'collections' => [$collectionHandle],
                'display' => 'Actors',
                'mode' => 'select',
            ],
        ];

        $blueprint['fields'] = $fields;
        File::put($blueprintPath, YAML::dump($blueprint));
        $this->info("User blueprint updated.");
    }

    protected function createFirstProfile(string $handle): void
    {
        $this->line('');
        if (!$this->confirm('Would you like to create your first ActivityPub profile now?', true)) {
            return;
        }

        $users = User::all();

        if ($users->isEmpty()) {
            $this->warn("No users found. Please create a user in Statamic first.");
            return;
        }

        $user = $users->first();

        if ($users->count() > 1) {
            $userOptions = $users->mapWithKeys(function ($u) {
                return [$u->email() . ' (' . $u->name() . ')' => $u->id()];
            })->all();

            $selectedLabel = $this->choice('Select the user to associate with this profile', array_keys($userOptions));
            $userId = $userOptions[$selectedLabel];
            $user = User::find($userId);
        }

        $this->info("Creating profile for user: {$user->name()}");

        $name = $this->ask("Enter the Display Name for your profile", $user->name());
        $slug = \Illuminate\Support\Str::slug($this->ask("Enter the Handle for your profile (this is how people will find you)", \Illuminate\Support\Str::slug($user->name())));

        $this->info("Creating entry...");

        try {
            $entry = Entry::make()
                ->collection($handle)
                ->slug($slug)
                ->data([
                    'title' => $name,
                ]);

            $entry->save();

            $this->info("Entry created: {$entry->id()}");

            $this->info("Linking to user...");
            // Associate with user
            // Check if actors field is array or single? 
            // Based on blueprint we added 'type: entries', 'mode: select' usually implies multiple support unless max_items: 1.
            // But let's assume valid array saves.
            $user->set('actors', [$entry->id()])->save();

            $domain = Site::default()->url();

            // Fallback if site url is relative or empty
            if (empty($domain) || $domain === '/') {
                $domain = config('app.url');
            }

            // Strip protocol
            $domain = preg_replace('#^https?://#', '', $domain);
            $domain = rtrim($domain, '/');

            $fullHandle = "@{$slug}@{$domain}";

            $this->line('');
            $this->info("Profile created successfully!");
            $this->comment("Your ActivityPub handle is: <options=bold>{$fullHandle}</>");

        } catch (\Exception $e) {
            $this->error("Failed to create profile: " . $e->getMessage());
        }
    }

    protected function configureType(string $type, array $config, array &$settings): void
    {
        $this->line('');
        $this->info("Configuring Type: <comment>{$type}</comment>");

        // 1. Check if ANY collection is already mapped to this type
        $existingCollectionHandle = null;
        foreach ($settings as $handle => $collectionConfig) {
            if (is_array($collectionConfig) && ($collectionConfig['type'] ?? '') === $type) {
                $existingCollectionHandle = $handle;
                break;
            }
        }

        if ($existingCollectionHandle) {
            $this->line("Found existing collection configured for {$type}: <info>{$existingCollectionHandle}</info>");

            // Verify it actually exists
            if (!Collection::findByHandle($existingCollectionHandle)) {
                $this->warn("...but the collection [{$existingCollectionHandle}] does not exist in Statamic.");
                $this->warn("Removing invalid configuration.");
                unset($settings[$existingCollectionHandle]);
                // Proceed to create/select
            } else {
                // Ensure enabled/federated are set
                $settings[$existingCollectionHandle]['enabled'] = true;
                $settings[$existingCollectionHandle]['federated'] = true;
                return;
            }
        }

        // 2. Not found, ask user what to do
        $existingCollections = Collection::all();
        $hasCollections = $existingCollections->isNotEmpty();

        $choices = [
            'create' => "Create a new '{$config['default_name']}' collection",
        ];

        if ($hasCollections) {
            $choices['select'] = 'Select an existing collection';
        }

        $option = $this->choice(
            "No collection found for {$type}. What would you like to do?",
            $choices,
            'create'
        );

        $targetHandle = null;

        if ($option === 'create') {
            $name = $this->ask("Enter the name for the {$type} collection", $config['default_name']);
            $handle = \Illuminate\Support\Str::slug($name);

            // Check if collection exists
            if (Collection::findByHandle($handle)) {
                $this->error("Collection with handle [{$handle}] already exists.");

                if ($hasCollections) {
                    $this->warn("Please select it from the list instead.");
                    $option = 'select'; // Switch to select
                } else {
                    // Should not happen if check works, but safe fallback
                    $this->error("Cannot create duplicate collection.");
                    return;
                }
            } else {
                $this->info("Creating collection: {$name} ({$handle})...");
                $collection = Collection::make($handle)
                    ->title($name)
                    ->save();
                $targetHandle = $handle;
            }
        }

        if ($option === 'select') {
            $collections = $existingCollections->mapWithKeys(function ($c) {
                return [$c->handle() => $c->title() . " [{$c->handle()}]"];
            })->toArray();

            $targetHandle = $this->choice('Select a collection', array_keys($collections));
        }

        if ($targetHandle) {
            $settings[$targetHandle] = [
                'enabled' => true,
                'federated' => true,
                'type' => $type,
            ];
            $this->info("Configured [{$targetHandle}] as {$type}.");
            $this->ensureBlueprint($targetHandle, $type);
        }
    }
    protected function ensureTaxonomy(): void
    {
        // 1. Ensure Taxonomy Exists
        $taxonomyPath = base_path('content/taxonomies/activitypub_collections.yaml');
        if (!File::exists($taxonomyPath)) {
            $this->info("Creating 'activitypub_collections' taxonomy...");
            File::put($taxonomyPath, "title: 'ActivityPub Collections'");
        }

        // 2. Ensure Taxonomy Blueprint Exists
        $blueprintDir = resource_path('blueprints/taxonomies/activitypub_collections');
        if (!File::exists($blueprintDir)) {
            File::makeDirectory($blueprintDir, 0755, true);
        }

        $blueprintPath = "{$blueprintDir}/activity_link.yaml";
        if (!File::exists($blueprintPath)) {
            $this->info("Creating 'activity_link' blueprint for taxonomy...");
            // Load stub from package resources
            $stubPath = __DIR__ . '/../../../resources/blueprints/templates/taxonomies/activity_link.yaml';

            if (File::exists($stubPath)) {
                File::copy($stubPath, $blueprintPath);
            } else {
                $this->error("Taxonomy blueprint stub not found at [{$stubPath}]");
            }
        }
    }

    protected function ensureBlueprint(string $handle, string $type): void
    {
        $blueprintDir = resource_path("blueprints/collections/{$handle}");
        if (!File::exists($blueprintDir)) {
            File::makeDirectory($blueprintDir, 0755, true);
        }

        $blueprintPath = "{$blueprintDir}/{$handle}.yaml";

        $targetFields = $this->getBlueprintStub($type);

        if (!File::exists($blueprintPath)) {
            $this->info("Creating default blueprint for {$type} at [{$blueprintPath}]...");
            File::put($blueprintPath, YAML::dump($targetFields));
            return;
        }

        // Update existing blueprint with missing fields
        $this->info("Updating existing blueprint at [{$blueprintPath}]...");
        $blueprint = YAML::parse(File::get($blueprintPath));

        // Flatten existing fields for easy checking
        $existingHandles = [];
        foreach ($blueprint['tabs'] ?? [] as $tab) {
            foreach ($tab['sections'] ?? [] as $section) {
                foreach ($section['fields'] ?? [] as $field) {
                    $existingHandles[] = $field['handle'] ?? null;
                }
            }
        }

        // We will just append missing fields to the "Sidebar" tab, or "Main" if simpler.
        // For simplicity, let's append valid fields to the 'main' tab's first section if they don't exist.
        // A robust merge is complex, so we will do a best-effort "add missing fields" approach.

        $fieldsToAdd = [];

        // Flatten target stub to find what we need
        foreach ($targetFields['tabs'] as $tabName => $tab) {
            foreach ($tab['sections'] as $section) {
                foreach ($section['fields'] as $field) {
                    if (!in_array($field['handle'], $existingHandles)) {
                        $fieldsToAdd[] = $field;
                    }
                }
            }
        }

        if (empty($fieldsToAdd)) {
            $this->line("Blueprint already has all required fields.");
            return;
        }

        $this->info("Adding " . count($fieldsToAdd) . " missing fields to blueprint...");

        // Ensure sidebar tab exists
        if (!isset($blueprint['tabs']['sidebar'])) {
            $blueprint['tabs']['sidebar'] = ['display' => 'Sidebar', 'sections' => [['fields' => []]]];
        }

        // Add to first section of sidebar
        if (!isset($blueprint['tabs']['sidebar']['sections'][0]['fields'])) {
            $blueprint['tabs']['sidebar']['sections'][0]['fields'] = [];
        }

        foreach ($fieldsToAdd as $field) {
            $blueprint['tabs']['sidebar']['sections'][0]['fields'][] = $field;
        }

        File::put($blueprintPath, YAML::dump($blueprint));
        $this->info("Blueprint updated.");
    }

    protected function getBlueprintStub(string $type): array
    {
        $filename = null;
        if ($type === 'Person')
            $filename = 'actors.yaml';
        if ($type === 'Note')
            $filename = 'notes.yaml';
        if ($type === 'Activity')
            $filename = 'activities.yaml';

        if (!$filename)
            return [];

        $stubPath = __DIR__ . "/../../../resources/blueprints/templates/collections/{$filename}";

        if (File::exists($stubPath)) {
            return YAML::parse(File::get($stubPath));
        }

        $this->error("Blueprint template for {$type} not found at [{$stubPath}]");
        return [];
    }

    protected function setupQueueTables(): void
    {
        $this->line('');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('  Setting up Queue Infrastructure');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->line('');

        // Check if jobs table already exists
        $hasJobsTable = Schema::hasTable('jobs');
        $hasFailedJobsTable = Schema::hasTable('failed_jobs');

        if ($hasJobsTable && $hasFailedJobsTable) {
            $this->comment('✓ Queue tables already exist, skipping setup.');
            return;
        }

        $this->info('ActivityPub requires Laravel\'s database queue for federation.');
        $this->line('Creating queue tables...');
        $this->line('');

        if (!$hasJobsTable) {
            $this->comment('→ Creating jobs table migration...');
            $this->call('queue:table');
        }

        if (!$hasFailedJobsTable) {
            $this->comment('→ Creating failed_jobs table migration...');
            $this->call('queue:failed-table');
        }

        $this->line('');
        $this->comment('→ Running database migrations...');
        $this->call('migrate', ['--force' => true]);

        $this->line('');
        $this->info('✓ Queue infrastructure setup complete!');
    }

    protected function runActivityPubMigrations(): void
    {
        $this->line('');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('  Running ActivityPub Migrations');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->line('');

        $this->info('Checking for pending ActivityPub data migrations...');
        $this->call('activitypub:migrate');

        $this->line('');
        $this->info('✓ ActivityPub migrations complete!');
    }

    protected function publishAssets(): void
    {
        // Check if we're in local development (skip asset publishing)
        $isLocalDevelopment = is_dir(base_path('addons/ethernick/ActivityPubCore'));

        if ($isLocalDevelopment) {
            $this->line('');
            $this->comment('→ Local development detected, skipping asset publishing.');
            $this->comment('  Assets will be served via Vite.');
            return;
        }

        $this->line('');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('  Publishing Frontend Assets');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->line('');

        $this->info('Publishing ActivityPub Control Panel assets...');
        $this->call('vendor:publish', [
            '--tag' => 'activitypub',
            '--force' => true,
        ]);

        $this->line('');
        $this->info('✓ Frontend assets published!');
    }
}
