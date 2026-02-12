<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Http\Controllers;

use Statamic\Http\Controllers\Controller;
use Statamic\Facades\Collection;
use Illuminate\Http\Request;
use Statamic\Facades\File;
use Statamic\Facades\YAML;
use Ethernick\ActivityPubCore\Services\ActivityPubTypes;

class ActivityPubSettingsController extends Controller
{
    public function index(ActivityPubTypes $types)
    {
        $collections = Collection::all();
        $taxonomies = \Statamic\Facades\Taxonomy::all();
        $settings = $this->getSettings();

        return view('activitypub::settings', [
            'collections' => $collections,
            'taxonomies' => $taxonomies,
            'settings' => $settings,
            'types' => $types->getOptions(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'collections' => 'array',
            'types' => 'array',
            'federated' => 'array',
            'allow_quotes' => 'nullable|boolean',
            'blocklist' => 'nullable|string',
            'retention_activities' => 'nullable|integer|min:0',
            'retention_entries' => 'nullable|integer|min:0',
            'inbox_batch_size' => 'nullable|integer|min:1',
            'outbox_batch_size' => 'nullable|integer|min:1',
            'schedule_interval' => 'nullable|integer|min:1|max:60',
        ]);

        $settings = [];
        $settings['allow_quotes'] = (bool) ($data['allow_quotes'] ?? false);
        if (isset($data['blocklist'])) {
            $settings['blocklist'] = $data['blocklist'];
        }
        $settings['retention_activities'] = (int) ($data['retention_activities'] ?? 2);
        $settings['retention_entries'] = (int) ($data['retention_entries'] ?? 30);
        $settings['inbox_batch_size'] = (int) ($data['inbox_batch_size'] ?? 50);
        $settings['outbox_batch_size'] = (int) ($data['outbox_batch_size'] ?? 50);
        $settings['schedule_interval'] = (int) ($data['schedule_interval'] ?? 1);

        foreach ($data['collections'] as $handle => $enabled) {
            $settings[$handle] = [
                'enabled' => (bool) $enabled,
                'type' => $data['types'][$handle] ?? 'Object',
                'federated' => (bool) ($data['federated'][$handle] ?? false),
            ];
        }

        $this->saveSettings($settings);

        return back()->withSuccess('Settings saved.');
    }

    protected function getSettingsPath(): string
    {
        return resource_path('settings/activitypub.yaml');
    }

    protected function getSettings(): array
    {
        if (!File::exists($this->getSettingsPath())) {
            return [];
        }

        $settings = YAML::parse(File::get($this->getSettingsPath()));

        return $settings;
    }

    protected function saveSettings(array $settings): void
    {
        File::put($this->getSettingsPath(), YAML::dump($settings));
    }

    public function logs()
    {
        $logPath = storage_path('logs/activitypub.log');
        $content = '';
        if (File::exists($logPath)) {
            $content = File::get($logPath);
        }

        return view('activitypub::logs', [
            'content' => $content
        ]);
    }

    public function clearLogs()
    {
        $logPath = storage_path('logs/activitypub.log');
        if (File::exists($logPath)) {
            File::put($logPath, '');
        }

        return redirect()->back()->withSuccess('Logs cleared.');
    }
}

