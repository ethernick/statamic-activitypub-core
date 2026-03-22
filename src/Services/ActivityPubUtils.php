<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Services;

use Statamic\Facades\Entry;
use Statamic\Facades\File;
use Statamic\Facades\YAML;
use Statamic\Facades\Site;
use Illuminate\Support\Str;

class ActivityPubUtils
{
    /**
     * Get the path to the ActivityPub settings file.
     */
    public static function settingsPath(): string
    {
        return config('activitypub.settings_path', resource_path('settings/activitypub.yaml'));
    }

    public static function blueprintPath(string $path): string
    {
        return config('activitypub.blueprints_path', resource_path('blueprints')) . '/' . ltrim($path, '/');
    }

    /**
     * Get the path to a collection file, allowing for sandbox overrides.
     */
    public static function collectionPath(string $handle): string
    {
        return config('activitypub.collections_path', base_path('content/collections')) . '/' . ltrim($handle, '/') . '.yaml';
    }

    public static function isFederated(string $handle): bool
    {
        $path = self::settingsPath();
        if (!File::exists($path)) {
            return false;
        }
        $settings = YAML::parse(File::get($path));
        $config = $settings[$handle] ?? [];

        if (is_array($config)) {
            return $config['federated'] ?? false;
        }

        return false;
    }

    public static function findLocalEntryByUrl(string $url): mixed
    {
        $entry = Entry::find($url);
        if (!$entry) {
            $entry = Entry::query()->whereIn('collection', ['notes', 'polls', 'articles'])->where('activitypub_id', $url)->first();
        }
        if (!$entry) {
            // Check absolute URL match
            $baseUrl = Site::selected()->absoluteUrl();
            if (Str::startsWith($url, $baseUrl)) {
                $uri = str_replace($baseUrl, '', $url);
                $uri = '/' . ltrim($uri, '/');
                $entry = Entry::findByUri($uri, Site::selected()->handle());
            }
        }
        return $entry;
    }
}
