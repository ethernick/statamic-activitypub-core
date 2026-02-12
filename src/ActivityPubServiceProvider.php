<?php

// NOTE: strict_types intentionally omitted from ServiceProvider
// Issue: Laravel's event dispatcher can fail to execute listeners when
// the ServiceProvider uses strict_types=1. All other files use strict types.

namespace Ethernick\ActivityPubCore;

use Statamic\Providers\AddonServiceProvider;
use Ethernick\ActivityPubCore\Fieldtypes\ActorSelector;
use Statamic\Statamic;
use Ethernick\ActivityPubCore\Listeners\ActivityPubListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Routing\Router;
use Ethernick\ActivityPubCore\Middleware\NegotiateActivityPubResponse;
use ActivityPhp\Type;

class ActivityPubServiceProvider extends AddonServiceProvider
{
    protected $slug = 'activitypub';

    protected $routes = [
        'web' => __DIR__ . '/routes/web.php',
        'cp' => __DIR__ . '/routes/cp.php',
    ];

    public function register(): void
    {
        // Merge addon config with application config
        $this->mergeConfigFrom(
            __DIR__ . '/../config/activitypub.php',
            'activitypub'
        );

        $this->app->singleton(\Ethernick\ActivityPubCore\Services\ActivityPubTypes::class, function () {
            return new \Ethernick\ActivityPubCore\Services\ActivityPubTypes();
        });

        parent::register();
    }

    public function boot(): void
    {
        // CRITICAL: Register event listeners BEFORE parent::boot()
        // The parent class defers bootEvents() inside Statamic::booted(), which runs
        // AFTER the Event facade is sealed in tests, preventing listeners from registering.
        // By calling bootEvents() here first, we ensure listeners are registered before
        // the facade is sealed, allowing tests to work correctly.

        // Use app instance to track if events have been booted (persists across provider instances)
        if (!app()->has('activitypub.events.booted')) {
            $this->bootEvents();
            app()->instance('activitypub.events.booted', true);
        }

        parent::boot();
        $this->registerAssets();
    }

    public function bootEvents(): AddonServiceProvider
    {
        // Prevent double registration
        if (app()->has('activitypub.events.booted')) {
            return $this;
        }

        return parent::bootEvents();
    }

    // protected $listen_disabled = [...];

    protected function registerAssets(): void
    {
        $isLocalDevelopment = is_dir(base_path('addons/ethernick/ActivityPubCore'));

        if ($isLocalDevelopment) {
            // Development: Vite with hot reload
            \Statamic\Statamic::vite('activitypub', [
                'input' => ['addons/ethernick/ActivityPubCore/resources/js/cp.js', 'addons/ethernick/ActivityPubCore/resources/css/cp.css'],
                'publicDirectory' => 'public',
                'buildDirectory' => 'build',
                'hotFile' => base_path('public/hot'),
            ]);
        } else {
            // Production: publish dist/ assets and register with Statamic.
            // Can't use registerScript()/registerStylesheet() here because they
            // call getAddon() which throws NotBootedException during package:discover.
            // publishes() must be called directly in boot() (not deferred) for
            // vendor:publish to pick them up.
            $distDir = __DIR__ . '/../dist';
            $packageName = 'ethernick/statamic-activitypub-core';

            $this->publishes([
                "$distDir/js/cp.js" => public_path("vendor/$packageName/js/cp.js"),
                "$distDir/css/cp.css" => public_path("vendor/$packageName/css/cp.css"),
            ], 'activitypub');

            \Statamic\Statamic::script($packageName, 'cp.js');
            \Statamic\Statamic::style($packageName, 'cp.css');
        }
    }

    protected function schedule(\Illuminate\Console\Scheduling\Schedule $schedule): void
    {
        // Load configuration from both settings file and config
        $settingsPath = resource_path('settings/activitypub.yaml');
        $interval = config('activitypub.schedule.interval', 1);
        $maintenanceTime = config('activitypub.schedule.maintenance_time', '02:00');
        $inboxBatchSize = config('activitypub.queue.inbox.batch_size', 50);
        $outboxBatchSize = config('activitypub.queue.outbox.batch_size', 50);
        $outboxTries = config('activitypub.queue.outbox.tries', 3);
        $outboxTimeout = config('activitypub.queue.outbox.timeout', 120);
        $defaultBatchSize = config('activitypub.queue.default.batch_size', 50);
        $defaultMaxTime = config('activitypub.queue.default.max_time', 50);
        $defaultTries = config('activitypub.queue.default.tries', 3);
        $maintenanceTries = config('activitypub.queue.maintenance.tries', 2);
        $maintenanceTimeout = config('activitypub.queue.maintenance.timeout', 600);

        // Allow settings file to override config values (for backwards compatibility)
        if (\Statamic\Facades\File::exists($settingsPath)) {
            $settings = \Statamic\Facades\YAML::parse(\Statamic\Facades\File::get($settingsPath));
            $interval = (int) ($settings['schedule_interval'] ?? $interval);
            $maintenanceTime = $settings['maintenance_time'] ?? $maintenanceTime;
            $inboxBatchSize = (int) ($settings['inbox_batch_size'] ?? $inboxBatchSize);
            $outboxBatchSize = (int) ($settings['outbox_batch_size'] ?? $outboxBatchSize);
        }

        // Ensure interval is valid (1-60) to prevent cron errors
        $interval = max(1, min(60, $interval));
        $cron = "*/{$interval} * * * *";

        // Validate time format H:i
        if (!preg_match('/^\d{2}:\d{2}$/', $maintenanceTime)) {
            $maintenanceTime = '02:00';
        }

        // Calculate max-time based on interval (leave 10 second buffer)
        $maxTime = max(30, ($interval * 60) - 10);

        // ===== QUEUE PROCESSING =====
        // Process ActivityPub outbox queue (replaces activitypub:process-outbox)
        $schedule->command("queue:work database --queue=activitypub-outbox --max-jobs={$outboxBatchSize} --max-time={$maxTime} --tries={$outboxTries} --timeout={$outboxTimeout}")
            ->cron($cron)
            ->withoutOverlapping()
            ->name('ActivityPub Outbox Queue');

        // Process general background jobs
        $schedule->command("queue:work database --queue=default --max-jobs={$defaultBatchSize} --max-time={$defaultMaxTime} --tries={$defaultTries}")
            ->everyMinute()
            ->withoutOverlapping()
            ->name('Default Queue');

        // ===== FILE-BASED PROCESSING (Keep for inbox) =====
        // Process inbox - keep file-based for fast HTTP response
        $schedule->command('activitypub:process-inbox')
            ->cron($cron)
            ->withoutOverlapping()
            ->name('ActivityPub Inbox Processing');

        // ===== MAINTENANCE TASKS =====
        // Queue maintenance tasks (command dispatches them to queue)
        $schedule->command('activitypub:maintenance')
            ->dailyAt($maintenanceTime)
            ->name('Queue ActivityPub Maintenance');

        // Process maintenance queue (runs for 10 minutes max, starting at maintenance time)
        $schedule->command("queue:work database --queue=maintenance --max-time={$maintenanceTimeout} --tries={$maintenanceTries} --timeout=300")
            ->dailyAt($maintenanceTime)
            ->name('Process Maintenance Queue');
    }

    protected function registerEvents(): void
    {
        foreach ($this->listen as $event => $listeners) {
            foreach ($listeners as $listener) {
                \Illuminate\Support\Facades\Event::listen($event, $listener);
            }
        }
    }

    protected function registerMiddleware(): void
    {
        foreach ($this->middlewareGroups as $group => $middleware) {
            foreach ($middleware as $m) {
                $this->app['router']->pushMiddlewareToGroup($group, $m);
            }
        }
    }


    protected function registerWidgets(): void
    {
        foreach ($this->widgets as $widget) {
            $widget::register();
        }
    }




    protected $listen = [
        \Statamic\Events\EntrySaving::class => [
            \Ethernick\ActivityPubCore\Listeners\GenerateActorKeys::class,
            \Ethernick\ActivityPubCore\Listeners\GenerateActorAvatar::class,
            \Ethernick\ActivityPubCore\Listeners\EnsureNoteIdIsSlug::class,
            \Ethernick\ActivityPubCore\Listeners\ActivityPubListener::class,
            \Ethernick\ActivityPubCore\Listeners\AutoGenerateActivityListener::class,
        ],
        \Statamic\Events\EntryBlueprintFound::class => [
            \Ethernick\ActivityPubCore\Listeners\FixUriValidationOnUpdate::class,
            \Ethernick\ActivityPubCore\Listeners\ActivityPubListener::class,
        ],
        // \Statamic\Events\TermBlueprintFound::class => [
        //     \Ethernick\ActivityPubCore\Listeners\ActivityPubListener::class,
        // ],
        \Statamic\Events\TermSaving::class => [
            \Ethernick\ActivityPubCore\Listeners\ActivityPubListener::class,
        ],
        \Statamic\Events\EntrySaved::class => [
            \Ethernick\ActivityPubCore\Listeners\ActivityPubListener::class,
            \Ethernick\ActivityPubCore\Listeners\AutoGenerateActivityListener::class,
        ],
        \Statamic\Events\EntryDeleted::class => [
            \Ethernick\ActivityPubCore\Listeners\AutoGenerateActivityListener::class,
        ],
    ];

    protected $middlewareGroups = [
        'web' => [
            NegotiateActivityPubResponse::class,
        ],
    ];

    protected $commands = [
        \Ethernick\ActivityPubCore\Console\Commands\RegenerateActivityPubJson::class,
        \Ethernick\ActivityPubCore\Console\Commands\ProcessInbox::class,
        \Ethernick\ActivityPubCore\Console\Commands\ProcessOutbox::class,
        \Ethernick\ActivityPubCore\Console\AcceptQuoteRequest::class,
        \Ethernick\ActivityPubCore\Console\Commands\VerifyActivityPub::class,
        \Ethernick\ActivityPubCore\Console\Commands\ActivityPubClean::class,
        \Ethernick\ActivityPubCore\Console\Commands\DiagnoseThreads::class,
        \Ethernick\ActivityPubCore\Console\Commands\ActivityPubMaintenance::class,
        \Ethernick\ActivityPubCore\Console\Commands\ActivityPubMigrate::class,
        \Ethernick\ActivityPubCore\Console\Commands\DiagnoseBadData::class,
        \Ethernick\ActivityPubCore\Console\Commands\ActivityPubInstall::class,
        \Ethernick\ActivityPubCore\Console\Commands\TestOEmbed::class,
        // Migration commands are auto-discovered by activitypub:migrate
    ];

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands($this->commands);
        }
    }

    protected $widgets = [
        \Ethernick\ActivityPubCore\Widgets\ActivityPubWidget::class,
    ];

    // NOTE: If fieldtypes are in src/Fieldtypes (or addons/ethernick/ActivityPubCore/Fieldtypes), Statamic 5.28+ autoloading might handle them, 
    // but explicit registration is safe. The original code called ::register() manually.
    // AddonServiceProvider usually expects class names in $fieldtypes array.
    protected $fieldtypes = [
        \Ethernick\ActivityPubCore\Fieldtypes\ActorSelector::class,
        // \Ethernick\ActivityPubCore\Fieldtypes\ActivityPubEntries::class,
    ];

    protected $actions = [
        \Ethernick\ActivityPubCore\Actions\FollowAction::class,
        \Ethernick\ActivityPubCore\Actions\UnfollowAction::class,
        \Ethernick\ActivityPubCore\Actions\ResendActivityAction::class,
    ];

    public function bootAddon(): void
    {
        // Register custom ActivityPub types
        // Core Types
        \Ethernick\ActivityPubCore\Services\ActivityPubTypes::register('Object', 'Generic Object', \Ethernick\ActivityPubCore\Http\Controllers\GenericObjectController::class, 'objects');
        \Ethernick\ActivityPubCore\Services\ActivityPubTypes::register('Page', 'Page', null, 'pages', ['pages']);
        \Ethernick\ActivityPubCore\Services\ActivityPubTypes::register('Note', 'Note', \Ethernick\ActivityPubCore\Http\Controllers\NoteController::class, 'notes', ['notes']);

        \Ethernick\ActivityPubCore\Services\ActivityPubTypes::register('Person', 'Person (Actor)', null, 'people');
        //\Ethernick\ActivityPubCore\Services\ActivityPubTypes::register('Organization', 'Organization (Actor)', null, 'organizations');
        \Ethernick\ActivityPubCore\Services\ActivityPubTypes::register('Activity', 'Activity', null, 'activities');
        \Ethernick\ActivityPubCore\Services\ActivityPubTypes::register('OrderedCollection', 'OrderedCollection', null, 'ordered-collections');

        // Dynamically Register Controllers from Types
        \Ethernick\ActivityPubCore\Services\ActivityDispatcher::registerControllersFromTypes();

        // Dynamically Register Controllers (Classic Discovery - Optional if types cover it)
        $this->registerActivityControllers();

        // Register Views
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'activitypub');

        // Manually register Actions
        \Ethernick\ActivityPubCore\Actions\FollowAction::register();
        \Ethernick\ActivityPubCore\Actions\UnfollowAction::register();
        \Ethernick\ActivityPubCore\Actions\ResendActivityAction::register();

        // Register Nav
        \Statamic\Facades\CP\Nav::extend(function ($nav) {
            $icon = file_get_contents(__DIR__ . '/../resources/svg/asterism.svg');

            $nav->create('Inbox')
                ->section('ActivityPub')
                ->icon($icon)
                ->route('activitypub.inbox.index')
                ->children([
                    //'Inbox' => cp_route('activitypub.inbox.index'),

                    'Following' => cp_route('activitypub.following.index'),
                    'Followers' => cp_route('activitypub.followers.index'),
                ]);

            $nav->settings('ActivityPub')
                ->route('activitypub.settings.index')
                ->icon($icon);

            // Reorder ActivityPub section to be before Fields
            $reflection = new \ReflectionClass($nav);
            $property = $reflection->getProperty('items');
            $property->setAccessible(true);
            $items = $property->getValue($nav);

            $activityPubItems = [];
            $otherItems = [];
            $fieldsIndex = null;

            foreach ($items as $index => $item) {
                if ($item->section() === 'ActivityPub') {
                    $activityPubItems[] = $item;
                } else {
                    $otherItems[] = $item;
                    if ($fieldsIndex === null && $item->section() === 'Fields') {
                        $fieldsIndex = count($otherItems) - 1;
                    }
                }
            }

            if ($fieldsIndex !== null && !empty($activityPubItems)) {
                array_splice($otherItems, $fieldsIndex, 0, $activityPubItems);
                $property->setValue($nav, $otherItems);
            }
        });

    }

    protected function registerActivityControllers(): void
    {
        $directory = __DIR__ . '/Http/Controllers';
        $namespace = 'Ethernick\\ActivityPubCore\\Http\\Controllers\\';

        // Simple Scan
        if (!is_dir($directory))
            return;

        $files = scandir($directory);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..')
                continue;
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'php')
                continue;

            $className = $namespace . pathinfo($file, PATHINFO_FILENAME);
            if (class_exists($className)) {
                if (is_subclass_of($className, \Ethernick\ActivityPubCore\Contracts\ActivityHandlerInterface::class)) {
                    \Ethernick\ActivityPubCore\Services\ActivityDispatcher::registerController($className);
                }
            }
        }
    }
}
