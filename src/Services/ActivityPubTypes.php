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
     * @param string|null $slug Optional slug override.
     * @param string|null $controller The fully qualified controller class name.
     * @param array $collections Related Statamic collections (e.g. ['polls']).
     * @param string|null $storeHandler The class name of the CP store handler (saving entries).
     * @param string|null $outboxHandler The class name of the payload formatter (outbox JSON).
     * @param string|null $inboxHandler The class name of the inbox activity handler.
     */
    public static function register(
        string $key, 
        string $label, 
        ?string $slug = null,
        ?string $controller = null, 
        array $collections = [], 
        ?string $storeHandler = null,
        ?string $outboxHandler = null,
        ?string $inboxHandler = null
    ): void
    {
        self::$types[$key] = [
            'label' => $label,
            'slug' => $slug ?? strtolower($key) . 's',
            'controller' => $controller,
            'collections' => $collections,
            'store_handler' => $storeHandler,
            'outbox_handler' => $outboxHandler,
            'inbox_handler' => $inboxHandler,
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

    public static function getOutboxHandler(string $key): ?string
    {
        return self::$types[$key]['outbox_handler'] ?? null;
    }

    public static function getInboxHandler(string $key): ?string
    {
        return self::$types[$key]['inbox_handler'] ?? null;
    }

    public static function getStoreHandler(string $key): ?string
    {
        return self::$types[$key]['store_handler'] ?? null;
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
