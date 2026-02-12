<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Services;

class ActivityPubTypes
{
    // Default types are defined here but can be extended via register()
    protected static array $types = [];

    /**
     * Register a new ActivityPub type from an addon.
     *
     * @param string $key The internal key (e.g. 'Question')
     * @param string $label The human-readable label (e.g. 'Poll/Question')
     * @param string|null $controller The fully qualified controller class name.
     * @param string|null $slug Optional slug override.
     * @param array $collections Related Statamic collections (e.g. ['polls']).
     */
    public static function register(string $key, string $label, ?string $controller = null, ?string $slug = null, array $collections = []): void
    {
        self::$types[$key] = [
            'label' => $label,
            'controller' => $controller,
            'slug' => $slug ?? strtolower($key) . 's',
            'collections' => $collections,
        ];
    }

    /**
     * Modify an existing ActivityPub type.
     * Useful for addons to override specific properties (like controller) without re-registering everything.
     *
     * @param string $key The internal key (e.g. 'Question')
     * @param array $overrides Key-value pair of properties to override (e.g. ['controller' => NewController::class])
     */
    public static function modify(string $key, array $overrides): void
    {
        if (isset(self::$types[$key])) {
            self::$types[$key] = array_merge(self::$types[$key], $overrides);
        }
    }

    public static function getCollections(string $key): array
    {
        return self::$types[$key]['collections'] ?? [];
    }

    public static function getController(string $key): ?string
    {
        return self::$types[$key]['controller'] ?? null;
    }

    public function getConfig(): array
    {
        return self::$types;
    }

    public function all(): array
    {
        return self::$types;
    }

    // Helper for legacy support or simpler lists
    public function getOptions(): array
    {
        return array_map(fn($item) => $item['label'], self::$types);
    }
}
