<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Services;

class ActivityPubNav
{
    /**
     * @var array
     */
    protected static array $items = [];

    /**
     * Register a navigation item.
     *
     * @param string $key Unique identifier for the item (e.g. 'inbox', 'polls')
     * @param array $config Configuration (label, route, icon, section, order, parent)
     */
    public static function register(string $key, array $config): void
    {
        self::$items[$key] = array_merge([
            'label' => $key,
            'route' => null,
            'icon' => null,
            'section' => 'ActivityPub',
            'order' => 100,
            'parent' => null,
        ], $config);
    }

    /**
     * Get all registered items.
     *
     * @return array
     */
    public static function all(): array
    {
        return self::$items;
    }

    /**
     * Get items organized in a tree and sorted by order/label.
     *
     * @return array
     */
    public static function ordered(): array
    {
        $tree = [];
        $children = [];

        // Sort items by order first, then by label
        $sortedItems = self::$items;
        uasort($sortedItems, function ($a, $b) {
            if ($a['order'] === $b['order']) {
                return strcasecmp($a['label'], $b['label']);
            }
            return $a['order'] <=> $b['order'];
        });

        // Separate top-level items and children
        foreach ($sortedItems as $key => $item) {
            if ($item['parent'] && isset(self::$items[$item['parent']])) {
                $children[$item['parent']][$key] = $item;
            } else {
                $tree[$key] = array_merge($item, ['children' => []]);
            }
        }

        // Attach children to parents
        foreach ($children as $parentKey => $parentChildren) {
            if (isset($tree[$parentKey])) {
                $tree[$parentKey]['children'] = $parentChildren;
            }
        }

        return $tree;
    }

    /**
     * Clear all registered items (mostly for testing).
     */
    public static function clear(): void
    {
        self::$items = [];
    }
}
