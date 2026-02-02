<?php

namespace Ethernick\ActivityPubCore\Services;

use Statamic\Facades\File;
use Statamic\Facades\YAML;

class BlockList
{
    protected static $blocklist = null;

    public static function isBlocked($domain)
    {
        $domain = strtolower(trim($domain));
        $list = static::getList();

        if (in_array($domain, $list)) {
            return true;
        }

        // Check subdomains
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

    public static function getList()
    {
        if (static::$blocklist !== null) {
            return static::$blocklist;
        }

        $path = resource_path('settings/activitypub.yaml');

        $rawList = '';
        if (File::exists($path)) {
            $settings = YAML::parse(File::get($path));
            $rawList = $settings['blocklist'] ?? '';
        }

        if (empty($rawList)) {
            $rawList = '';
        }

        static::$blocklist = collect(explode("\n", $rawList))
            ->map(fn($line) => strtolower(trim($line)))
            ->filter()
            ->values()
            ->all();

        return static::$blocklist;
    }
}
