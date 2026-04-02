<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Services;

use Statamic\Facades\File;
use Statamic\Facades\YAML;
use Illuminate\Support\Facades\Log;

class BlockList
{
    protected static ?array $blocklist = null;

    /**
     * Check if a domain or a specific actor URL/handle is blocked.
     *
     * @param string $identifier The domain, handle, or full Actor URL.
     * @return bool
     */
    public static function isBlocked(string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));
        $list = static::getList();

        // 1. Direct match (Full URL, handle, or domain)
        if (in_array($identifier, $list)) {
            return true;
        }

        // 2. Domain-level match (if identifier is a URL or handle)
        $domain = $identifier;
        if (str_starts_with($identifier, 'http')) {
            $domain = parse_url($identifier, PHP_URL_HOST);
        } elseif (str_contains($identifier, '@')) {
            $parts = explode('@', ltrim($identifier, '@'));
            $domain = end($parts);
        }

        if (!$domain) {
            return false;
        }

        if (in_array($domain, $list)) {
            return true;
        }

        // 3. Subdomain-level match
        $parts = explode('.', $domain);
        while (count($parts) > 1) {
            array_shift($parts);
            $parent = implode('.', $parts);
            if (in_array($parent, $list)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Programmatically add an identifier to the blocklist.
     * Resolves handles to URLs and aliases if needed.
     *
     * @param string $identifier Handle, URL, or Domain.
     * @param string|null $reason Optional reason for the block (logs it to DB).
     */
    public static function add(string $identifier, ?string $reason = null): void
    {
        $identifier = strtolower(trim($identifier));
        $list = static::getList();
        $toAdd = [$identifier];
        $urls = [];

        // If it's a handle, resolve it to get absolute URLs and aliases
        if (str_contains($identifier, '@') && !str_starts_with($identifier, 'http')) {
            $webfinger = app(\Ethernick\ActivityPubCore\Services\WebfingerService::class)->resolve($identifier);
            if ($webfinger['actor_url']) {
                $toAdd[] = $webfinger['actor_url'];
                $urls = $webfinger['aliases'];
                $toAdd = array_unique(array_merge($toAdd, $urls));
            }
        }

        $newList = array_unique(array_merge($list, $toAdd));

        // If something changed, save it
        if (count($newList) > count($list)) {
            $path = \Ethernick\ActivityPubCore\Services\ActivityPubUtils::settingsPath();
            $settings = [];
            if (File::exists($path)) {
                $settings = YAML::parse(File::get($path));
            }

            $settings['blocklist'] = implode("\n", $newList);
            File::put($path, YAML::dump($settings));

            // Refresh static cache
            static::$blocklist = $newList;

            // Log the block
            if ($reason) {
                static::log($identifier, $reason, array_diff($newList, $list));
            }
        }
    }

    /**
     * Log an automated block to the database.
     */
    public static function log(string $identifier, string $reason, array $urls = []): void
    {
        try {
            \Ethernick\ActivityPubCore\Models\AutoBlock::create([
                'identifier' => $identifier,
                'urls' => $urls,
                'reason' => $reason,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log auto-block: ' . $e->getMessage());
        }
    }

    /**
     * Prune old auto-block logs from the database.
     */
    public static function prune(?int $days = null): int
    {
        if ($days === null) {
            $settings = static::getSettings();
            $days = (int) ($settings['retention_auto_blocks'] ?? 7);
        }

        $cutoff = \Carbon\Carbon::now()->subDays($days);
        return \Ethernick\ActivityPubCore\Models\AutoBlock::where('created_at', '<', $cutoff)->delete();
    }

    protected static function getSettings(): array
    {
        $path = \Ethernick\ActivityPubCore\Services\ActivityPubUtils::settingsPath();
        if (!File::exists($path)) {
            return [];
        }
        return YAML::parse(File::get($path));
    }

    public static function getList(): array
    {
        if (static::$blocklist !== null) {
            return static::$blocklist;
        }

        $settings = static::getSettings();
        $rawList = $settings['blocklist'] ?? '';

        static::$blocklist = collect(explode("\n", $rawList))
            ->map(fn($line) => strtolower(trim((string) $line)))
            ->filter()
            ->values()
            ->all();

        return static::$blocklist;
    }

}
