<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests\Concerns;

use Illuminate\Support\Facades\File;
use Statamic\Facades\Stache;

trait ProvidesSandbox
{
    /**
     * Path to the sandbox directory.
     */
    protected string $sandboxPath;

    /**
     * Initialize the sandbox environment.
     */
    protected function setupSandbox(): void
    {

        // Clear ActivityPubListener caches
        if (class_exists(\Ethernick\ActivityPubCore\Listeners\ActivityPubListener::class)) {
            \Ethernick\ActivityPubCore\Listeners\ActivityPubListener::clearSettingsCache();
            
            // Also clear actor cache if possible (it was static)
            $reflection = new \ReflectionClass(\Ethernick\ActivityPubCore\Listeners\ActivityPubListener::class);
            $actorCache = $reflection->getProperty('actorCache');
            $actorCache->setAccessible(true);
            $actorCache->setValue(null, []);
        }
        $this->sandboxPath = base_path('.test/sandbox');

        // Always start with a clean sandbox
        if (File::exists($this->sandboxPath)) {
            File::deleteDirectory($this->sandboxPath);
        }

        // Create the sandbox structure
        $this->ensureSandboxDirectories();

        // Seed from production before overriding paths, so we can copy FROM production
        $this->seedFromProduction();

        // Override application paths
        $this->overrideAppPaths();
        
        // Inject sandbox paths into Addon config for absolute isolation
        config(['activitypub.settings_path' => $this->sandboxPath . '/resources/settings/activitypub.yaml']);
        config(['activitypub.blueprints_path' => $this->sandboxPath . '/resources/blueprints']);

        // Ensure host is set for absolute URL generation
        config(['app.url' => 'http://localhost']);

        // Redirect Statamic's own blueprint and settings paths
        config(['statamic.system.blueprints_path' => $this->sandboxPath . '/resources/blueprints']);
        config(['statamic.system.settings_path' => $this->sandboxPath . '/resources/settings']);

        // Robust isolation: Flush all possible state/caches
        $this->flushState();
        
        $this->sandboxInitialized = true;
    }

    /**
     * Flush all possible state and caches to prevent cross-test contamination.
     */
    protected function flushState(): void
    {
        Stache::clear();
        // Flush all Statamic caches
        Stache::clear();
        \Statamic\Facades\Blink::flush();
        \Illuminate\Support\Facades\Cache::flush();
        
        // Reset singleton-like caches in the listener if they exist
        if (class_exists(\Ethernick\ActivityPubCore\Listeners\ActivityPubListener::class)) {
            $reflection = new \ReflectionClass(\Ethernick\ActivityPubCore\Listeners\ActivityPubListener::class);
            if ($reflection->hasProperty('actorCache')) {
                $prop = $reflection->getProperty('actorCache');
                $prop->setAccessible(true);
                $prop->setValue(null, []);
            }
        }
    }

    /**
     * Ensure required directories exist in the sandbox.
     */
    protected function ensureSandboxDirectories(): void
    {
        $directories = [
            '',
            'resources/settings',
            'resources/blueprints',
            'resources/views/vendor/statamic',
            'resources/views/testing',
            'content/collections',
            'content/taxonomies',
            'storage/framework/cache',
            'storage/framework/sessions',
            'storage/framework/testing',
            'storage/framework/views',
            'storage/statamic',
            'storage/app/activitypub/inbox',
        ];

        foreach ($directories as $dir) {
            $path = $this->sandboxPath . ($dir ? '/' . $dir : '');
            if (!File::exists($path)) {
                File::makeDirectory($path, 0755, true);
            }
        }
    }

    /**
     * Override Laravel and Statamic paths to point at the sandbox.
     */
    protected function overrideAppPaths(): void
    {
        // Force Laravel to use the sandbox resources path
        // Since useResourcePath might not exist in this Laravel version, we use reflection
        $reflection = new \ReflectionClass($this->app);
        if ($reflection->hasProperty('resourcePath')) {
            $prop = $reflection->getProperty('resourcePath');
            $prop->setAccessible(true);
            $prop->setValue($this->app, $this->sandboxPath . '/resources');
        }
        
        $this->app->instance('path.resources', $this->sandboxPath . '/resources');

        // Override base path for content/taxonomies in config (for future store initializations)
        $this->app['config']->set('statamic.stache.stores.collections.directory', $this->sandboxPath . '/content/collections');
        $this->app['config']->set('statamic.stache.stores.taxonomies.directory', $this->sandboxPath . '/content/taxonomies');
        $this->app['config']->set('statamic.stache.stores.navigation.directory', $this->sandboxPath . '/content/navigation');
        $this->app['config']->set('statamic.stache.stores.globals.directory', $this->sandboxPath . '/content/globals');
        
        // Storage path for Stache indexes
        $this->app->useStoragePath($this->sandboxPath . '/storage');

        // Forcefully redirect repositories that might have been initialized
        if (class_exists(\Statamic\Facades\Blueprint::class)) {
            \Statamic\Facades\Blueprint::setDirectory($this->sandboxPath . '/resources/blueprints');
        }
        if (class_exists(\Statamic\Facades\Fieldset::class)) {
            \Statamic\Facades\Fieldset::setDirectory($this->sandboxPath . '/resources/fieldsets');
        }

        // Forcefully redirect ALREADY INITIALIZED Stache stores
        if (class_exists(\Statamic\Facades\Stache::class)) {
            $stache = \Statamic\Facades\Stache::instance();
            $stache->stores()->each(function ($store) {
                $key = $store->key();
                $sandboxPath = match($key) {
                    'collections' => $this->sandboxPath . '/content/collections',
                    'entries' => $this->sandboxPath . '/content/collections',
                    'taxonomies' => $this->sandboxPath . '/content/taxonomies',
                    'terms' => $this->sandboxPath . '/content/taxonomies',
                    'navigation' => $this->sandboxPath . '/content/navigation',
                    'collection-trees' => $this->sandboxPath . '/content/collections',
                    'nav-trees' => $this->sandboxPath . '/content/navigation',
                    'globals' => $this->sandboxPath . '/content/globals',
                    'global-variables' => $this->sandboxPath . '/content/globals',
                    'users' => $this->sandboxPath . '/content/users',
                    'assets' => $this->sandboxPath . '/content/assets',
                    'asset-containers' => $this->sandboxPath . '/content/assets',
                    'blueprints' => $this->sandboxPath . '/resources/blueprints',
                    default => null
                };

                if ($sandboxPath) {
                    $store->directory($sandboxPath);
                    $store->clear();
                }
            });
        }
    }

    /**
     * Cleanup the sandbox.
     */
    protected function teardownSandbox(): void
    {
        // We might want to keep it between tests in the same run for speed,
        // but it's safer to wipe it or at least ensure it's clean next time.
        // File::deleteDirectory($this->sandboxPath);
    }
    
    /**
     * Helper to "seed" the sandbox with a specific file from production if needed.
     */
    protected function seedSandboxFile(string $relativePath): void
    {
        $source = base_path($relativePath);
        $target = $this->sandboxPath . '/' . $relativePath;
        
        if (File::exists($source)) {
            $targetDir = dirname($target);
            if (!File::exists($targetDir)) {
                File::makeDirectory($targetDir, 0755, true);
            }
            
            if (File::isDirectory($source)) {
                File::copyDirectory($source, $target);
            } else {
                File::copy($source, $target);
            }
        }
    }

    /**
     * Seed the sandbox with production settings and blueprints for a "Zero-Touch" environment.
     */
    protected function seedFromProduction(): void
    {
        // Seed Settings
        if (File::exists(base_path('resources/settings'))) {
            $this->seedSandboxFile('resources/settings');
        }

        // Seed Blueprints (Collections only for now to keep it lean)
        if (File::exists(base_path('resources/blueprints/collections'))) {
            $this->seedSandboxFile('resources/blueprints/collections');
        }

        // Seed Sites configuration (Critical for URL generation)
        // Override with absolute URL so absoluteUrl() works in tests
        $sitesPath = $this->sandboxPath . '/resources/sites.yaml';
        File::put($sitesPath, "default:\n  name: Test Site\n  url: 'http://localhost'\n  locale: en");

        // Seed Users (for actor-user relationships)
        if (File::exists(base_path('resources/users'))) {
            $this->seedSandboxFile('resources/users');
        }
        
        // Seed specific addon templates to the sandbox blueprints if needed
        // This ensures tests run against the structural source of truth.
        $coreTemplates = base_path('addons/ethernick/ActivityPubCore/resources/blueprints/templates/collections');
        if (File::exists($coreTemplates)) {
            $targetDir = $this->sandboxPath . '/resources/blueprints/collections';
            File::ensureDirectoryExists($targetDir);
            
            foreach (File::files($coreTemplates) as $file) {
                $name = $file->getFilenameWithoutExtension();
                $dest = "{$targetDir}/{$name}";
                File::ensureDirectoryExists($dest);
                File::copy($file->getPathname(), "{$dest}/{$name}.yaml");
            }
        }
        
        $this->flushState();
    }

    /**
     * Helper to ensure standard collections exist with correct settings.
     */
    protected function setupCollections(array $handles = ['actors', 'activities', 'notes']): void
    {
        foreach ($handles as $handle) {
            $collection = \Statamic\Facades\Collection::find($handle) ?? \Statamic\Facades\Collection::make($handle);
            
            // Actors is usually not dated, others are in ActivityPub
            if (!in_array($handle, ['actors', 'users'])) {
                $collection->dated(true);
            }

            // Use routes() (plural) — the setter. route() (singular) is a getter!
            if ($handle === 'actors') {
                $collection->routes('/actor/{slug}');
            } elseif ($handle === 'notes') {
                $collection->routes('/notes/{slug}');
            } elseif ($handle === 'activities') {
                $collection->routes('/activities/{slug}');
            } elseif ($handle === 'polls') {
                $collection->routes('/polls/{slug}');
            } elseif ($handle === 'articles') {
                $collection->routes('/articles/{slug}');
            } elseif ($handle === 'places') {
                $collection->routes('/places/{osm_type}/{osm_id}');
            }

            $collection->save();
        }
    }
}
