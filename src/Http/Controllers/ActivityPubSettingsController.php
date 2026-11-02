<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Http\Controllers;

use Statamic\Http\Controllers\Controller;
use Statamic\Facades\Collection;
use Illuminate\Http\Request;
use Statamic\Facades\File;
use Statamic\Facades\YAML;
use Ethernick\ActivityPubCore\Services\ActivityPubTypes;
use Ethernick\ActivityPubCore\Services\ActivityPubSettings;

class ActivityPubSettingsController extends Controller
{
    public function index(ActivityPubTypes $types): mixed
    {
        $collections = Collection::all();
        $taxonomies = \Statamic\Facades\Taxonomy::all();
        $settings = $this->getSettings();

        return view('activitypub::settings', [
            'collections' => $collections,
            'taxonomies' => $taxonomies,
            'settings' => $settings,
            'types' => $types->getOptions(),
            'logsUrl' => cp_route('activitypub.logs'),
            'autoBlockLogsUrl' => cp_route('activitypub.auto-blocks.logs'),
            'clearAutoBlockLogsUrl' => cp_route('activitypub.auto-blocks.clear'),
            'resolveHandleUrl' => cp_route('activitypub.auto-blocks.resolve'),
            'blocklistUrl' => cp_route('activitypub.blocklist.index'),
            'blockDomainUrl' => cp_route('activitypub.blocklist.domain'),
            'blockUserUrl' => cp_route('activitypub.blocklist.user'),
            'deleteBlockUrl' => url(cp_route('activitypub.blocklist.index')),
            'runMaintenanceUrl' => cp_route('activitypub.maintenance.run'),
            'extraTabs' => ActivityPubSettings::getTabs(),
        ]);
    }

    public function update(Request $request): mixed
    {
        $data = $request->validate([
            'collections' => 'array',
            'types' => 'array',
            'federated' => 'array',
            'allow_quotes' => 'nullable|boolean',
            'retention_activities' => 'nullable|integer|min:0',
            'retention_entries' => 'nullable|integer|min:0',
            'inbox_batch_size' => 'nullable|integer|min:1',
            'outbox_batch_size' => 'nullable|integer|min:1',
            'schedule_interval' => 'nullable|integer|min:1|max:60',
            'sync_interval_hours' => 'nullable|integer|min:0|max:48',
            'hashtags' => 'array',
            'retention_auto_blocks' => 'nullable|integer|min:0',
            'retention_logs' => 'nullable|integer|min:1',
            'extra' => 'array',
        ]);

        $settings = [];
        $settings['allow_quotes'] = (bool) ($data['allow_quotes'] ?? false);

        $settings['retention_activities'] = (int) ($data['retention_activities'] ?? 2);
        $settings['retention_entries'] = (int) ($data['retention_entries'] ?? 30);
        $settings['retention_logs'] = (int) ($data['retention_logs'] ?? config('activitypub.retention.logs', 7));
        $settings['retention_auto_blocks'] = (int) ($data['retention_auto_blocks'] ?? config('activitypub.retention.auto_blocks', 7));
        $settings['inbox_batch_size'] = (int) ($data['inbox_batch_size'] ?? 50);
        $settings['outbox_batch_size'] = (int) ($data['outbox_batch_size'] ?? 50);
        $settings['schedule_interval'] = (int) ($data['schedule_interval'] ?? 1);
        $settings['sync_interval_hours'] = (int) ($data['sync_interval_hours'] ?? 1);

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

        if (isset($data['extra']) && is_array($data['extra'])) {
            foreach ($data['extra'] as $key => $value) {
                $settings[$key] = $value;
            }
        }

        $this->saveSettings($settings);

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Settings saved.']);
        }

        return back()->withSuccess('Settings saved.');
    }

    public function blocklistEntries(Request $request): \Illuminate\Http\JsonResponse
    {
        $query = \Ethernick\ActivityPubCore\Models\BlockListEntry::query();

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where('identifier', 'like', "%{$search}%");
        }

        $type = $request->input('type');
        if ($type === 'domain') {
            $query->where('identifier', 'not like', '%@%')
                  ->where('identifier', 'not like', 'http%');
        } elseif ($type === 'user') {
            $query->where(function ($q) {
                $q->where('identifier', 'like', '%@%')
                  ->orWhere('identifier', 'like', 'http%');
            });
        }

        $perPage = (int) $request->input('per_page', 25);
        if ($perPage < 5) $perPage = 25;
        if ($perPage > 100) $perPage = 100;

        $entries = $query->orderBy('created_at', 'desc')->paginate($perPage);

        // Append computed type
        $entries->getCollection()->transform(function ($item) {
            $id = $item->identifier;
            $isUser = str_contains($id, '@') || str_starts_with($id, 'http');
            $item->type = $isUser ? 'user' : 'domain';
            return $item;
        });

        return response()->json($entries);
    }

    public function blockDomain(Request $request): \Illuminate\Http\JsonResponse
    {
        $rawDomains = $request->input('domain') ?? $request->input('domains');
        if (is_array($rawDomains)) {
            $lines = $rawDomains;
        } else {
            $lines = explode("\n", (string) $rawDomains);
        }

        $validDomains = [];
        foreach ($lines as $line) {
            $domain = trim(strtolower((string) $line));
            $domain = preg_replace('#^https?://#', '', $domain);
            $domain = rtrim($domain, '/');

            if ($domain && !str_contains($domain, '/') && !str_contains($domain, '@')) {
                $validDomains[] = $domain;
            }
        }

        $validDomains = array_unique($validDomains);

        if (empty($validDomains)) {
            return response()->json(['message' => 'Please provide one or more valid domain names (e.g., example.com).'], 422);
        }

        $addedCount = 0;
        foreach ($validDomains as $domain) {
            $entry = \Ethernick\ActivityPubCore\Models\BlockListEntry::firstOrCreate(['identifier' => $domain]);
            if ($entry->wasRecentlyCreated) {
                $addedCount++;
            }
        }

        \Ethernick\ActivityPubCore\Services\BlockList::reset();

        $message = count($validDomains) === 1
            ? "Domain {$validDomains[0]} blocked successfully."
            : "Successfully processed " . count($validDomains) . " domains ({$addedCount} new blocks added).";

        return response()->json([
            'message' => $message,
            'count' => count($validDomains)
        ]);
    }

    public function blockUser(Request $request): \Illuminate\Http\JsonResponse
    {
        $rawHandles = $request->input('handle') ?? $request->input('handles');
        if (is_array($rawHandles)) {
            $lines = $rawHandles;
        } else {
            $lines = explode("\n", (string) $rawHandles);
        }

        $handles = [];
        foreach ($lines as $line) {
            $h = trim((string) $line);
            if ($h !== '') {
                $handles[] = $h;
            }
        }

        $handles = array_unique($handles);

        if (empty($handles)) {
            return response()->json(['message' => 'Please provide one or more handles or actor URLs.'], 422);
        }

        foreach ($handles as $handle) {
            \Ethernick\ActivityPubCore\Services\BlockList::add($handle, 'Manual Block');
        }

        \Ethernick\ActivityPubCore\Services\BlockList::reset();

        $message = count($handles) === 1
            ? "User {$handles[0]} and any associated aliases have been blocked."
            : "Successfully blocked " . count($handles) . " users and their aliases.";

        return response()->json([
            'message' => $message,
            'count' => count($handles)
        ]);
    }

    public function deleteBlock(int $id): \Illuminate\Http\JsonResponse
    {
        $entry = \Ethernick\ActivityPubCore\Models\BlockListEntry::find($id);
        if (!$entry) {
            return response()->json(['message' => 'Block entry not found.'], 404);
        }

        $entry->delete();
        \Ethernick\ActivityPubCore\Services\BlockList::reset();

        return response()->json(['message' => 'Entry removed from blocklist.']);
    }

    public function runMaintenance(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            // Extend execution time limit for large dataset pruning & warming
            @set_time_limit(300);

            // Ensure the command is registered in Artisan even if running under FPM/web server
            $consoleKernel = app(\Illuminate\Contracts\Console\Kernel::class);
            $consoleKernel->registerCommand(app(\Ethernick\ActivityPubCore\Console\Commands\ActivityPubClean::class));

            \Illuminate\Support\Facades\Artisan::call('activitypub:clean');
            $output = trim(\Illuminate\Support\Facades\Artisan::output());

            return response()->json([
                'message' => 'Maintenance executed successfully.',
                'output' => $output
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Manual maintenance run failed: ' . $e->getMessage());
            return response()->json([
                'message' => 'Maintenance failed: ' . $e->getMessage()
            ], 500);
        }
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

        \Ethernick\ActivityPubCore\Services\BlockList::add($handle, 'Manual Block');
        \Ethernick\ActivityPubCore\Services\BlockList::reset();

        return response()->json(['message' => "Handle {$handle} and its aliases have been added to the blocklist."]);
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
        // Support daily rotated log fallback if single path does not exist
        if (!File::exists($logPath)) {
            $todayLog = storage_path('logs/activitypub-' . date('Y-m-d') . '.log');
            if (File::exists($todayLog)) {
                $logPath = $todayLog;
            }
        }

        $content = '';
        if (File::exists($logPath)) {
            $size = (int) @filesize($logPath);
            if ($size > 0) {
                // Read last 512 KB at most to prevent PHP memory exhaustion
                $maxBytes = 512 * 1024;
                $offset = max(0, $size - $maxBytes);
                $fp = @fopen($logPath, 'r');
                if ($fp) {
                    if ($offset > 0) {
                        fseek($fp, $offset);
                        // Skip partial first line
                        fgets($fp);
                    }
                    $content = fread($fp, $maxBytes) ?: '';
                    fclose($fp);
                }
            }
        }

        return view('activitypub::logs', [
            'content' => $content,
            'logFile' => basename($logPath),
        ]);
    }

    public function clearLogs(): mixed
    {
        $logPath = storage_path('logs/activitypub.log');
        if (File::exists($logPath)) {
            File::put($logPath, '');
        }

        $todayLog = storage_path('logs/activitypub-' . date('Y-m-d') . '.log');
        if (File::exists($todayLog)) {
            File::put($todayLog, '');
        }

        return redirect()->back()->withSuccess('Logs cleared.');
    }
}

