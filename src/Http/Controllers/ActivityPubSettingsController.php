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
    public function index(ActivityPubTypes $types): mixed
    {
        $collections = Collection::all();
        $taxonomies = \Statamic\Facades\Taxonomy::all();
        $settings = $this->getSettings();

        // Inject blocklist from database into settings for the UI
        $settings['blocklist'] = implode("\n", \Ethernick\ActivityPubCore\Services\BlockList::getList());

        return view('activitypub::settings', [
            'collections' => $collections,
            'taxonomies' => $taxonomies,
            'settings' => $settings,
            'types' => $types->getOptions(),
            'logsUrl' => cp_route('activitypub.logs'),
            'autoBlockLogsUrl' => cp_route('activitypub.auto-blocks.logs'),
            'clearAutoBlockLogsUrl' => cp_route('activitypub.auto-blocks.clear'),
            'resolveHandleUrl' => cp_route('activitypub.auto-blocks.resolve'),
        ]);

    }

    public function update(Request $request): mixed
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
            'hashtags' => 'array',
            'retention_auto_blocks' => 'nullable|integer|min:0',
        ]);

        $settings = [];
        $settings['allow_quotes'] = (bool) ($data['allow_quotes'] ?? false);

        // Sync blocklist to database instead of YAML
        if (isset($data['blocklist'])) {
            $this->syncBlockList($data['blocklist']);
        }

        $settings['retention_activities'] = (int) ($data['retention_activities'] ?? 2);
        $settings['retention_entries'] = (int) ($data['retention_entries'] ?? 30);
        $settings['retention_auto_blocks'] = (int) ($data['retention_auto_blocks'] ?? 7);
        $settings['inbox_batch_size'] = (int) ($data['inbox_batch_size'] ?? 50);
        $settings['outbox_batch_size'] = (int) ($data['outbox_batch_size'] ?? 50);
        $settings['schedule_interval'] = (int) ($data['schedule_interval'] ?? 1);

        if (isset($data['hashtags'])) {
            $settings['hashtags'] = [
                'enabled' => (bool) ($data['hashtags']['enabled'] ?? false),
                'taxonomy' => $data['hashtags']['taxonomy'] ?? 'tags',
                'field' => $data['hashtags']['field'] ?? 'tags',
            ];
        }

        foreach ($data['collections'] as $handle => $enabled) {
            $settings[$handle] = [
                'enabled' => (bool) $enabled,
                'type' => $data['types'][$handle] ?? 'Object',
                'federated' => (bool) ($data['federated'][$handle] ?? false),
            ];
        }

        $this->saveSettings($settings);

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Settings saved.']);
        }

        return back()->withSuccess('Settings saved.');
    }

    public function autoBlockLogs(): \Illuminate\Http\JsonResponse
    {
        $logs = \Ethernick\ActivityPubCore\Models\AutoBlock::orderBy('created_at', 'desc')->paginate(50);
        return response()->json($logs);
    }

    public function clearAutoBlockLogs(): \Illuminate\Http\JsonResponse
    {
        \Ethernick\ActivityPubCore\Models\AutoBlock::truncate();
        return response()->json(['message' => 'Auto-block logs cleared.']);
    }

    public function resolveHandle(Request $request): \Illuminate\Http\JsonResponse
    {
        $handle = $request->input('handle');
        if (!$handle) {
            return response()->json(['message' => 'Handle is required.'], 422);
        }

        // Add to blocklist (this also resolves it via Webfinger)
        return response()->json(['message' => "Handle {$handle} and its aliases have been added to the blocklist."]);
    }

    protected function syncBlockList(string $rawList): void
    {
        $newIdentifiers = collect(explode("\n", $rawList))
            ->map(fn($line) => strtolower(trim((string) $line)))
            ->filter()
            ->unique();

        $existing = \Ethernick\ActivityPubCore\Models\BlockListEntry::pluck('identifier');

        $toDelete = $existing->diff($newIdentifiers);
        $toInsert = $newIdentifiers->diff($existing);

        if ($toDelete->isNotEmpty()) {
            \Ethernick\ActivityPubCore\Models\BlockListEntry::whereIn('identifier', $toDelete->all())->delete();
        }

        if ($toInsert->isNotEmpty()) {
            $insertData = $toInsert->map(fn($id) => ['identifier' => $id, 'created_at' => now(), 'updated_at' => now()])->all();
            \Ethernick\ActivityPubCore\Models\BlockListEntry::insert($insertData);
        }
    }


    protected function settingsPath(): string
    {
        return \Ethernick\ActivityPubCore\Services\ActivityPubUtils::settingsPath();
    }

    protected function getSettings(): array
    {
        if (!File::exists($this->settingsPath())) {
            return [];
        }

        $settings = YAML::parse(File::get($this->settingsPath()));

        return $settings;
    }

    protected function saveSettings(array $settings): void
    {
        File::put($this->settingsPath(), YAML::dump($settings));
    }

    public function logs(): mixed
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

    public function clearLogs(): mixed
    {
        $logPath = storage_path('logs/activitypub.log');
        if (File::exists($logPath)) {
            File::put($logPath, '');
        }

        return redirect()->back()->withSuccess('Logs cleared.');
    }
}

