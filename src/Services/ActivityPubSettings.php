<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Services;

class ActivityPubSettings
{
    /**
     * @var array<array{id: string, label: string, component: string, default_settings: array}>
     */
    protected static array $tabs = [];

    public static function registerTab(string $id, string $label, string $component, array $defaultSettings = []): void
    {
        self::$tabs[] = [
            'id' => $id,
            'label' => $label,
            'component' => $component,
            'default_settings' => $defaultSettings,
        ];
    }

    public static function getTabs(): array
    {
        return self::$tabs;
    }
}
