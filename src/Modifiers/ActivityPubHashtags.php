<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Modifiers;

use Statamic\Modifiers\Modifier;
use Statamic\Facades\Term;
use Statamic\Facades\YAML;
use Statamic\Facades\File;
use Statamic\Support\Str;

class ActivityPubHashtags extends Modifier
{
    protected static $handle = 'activitypub_hashtags';

    /**
     * Linkify hashtags in content to native Statamic taxonomy pages.
     *
     * Usage: {{ content | activitypub_hashtags }}
     *
     * @param mixed $value
     * @param array $params
     * @param array $context
     * @return mixed
     */
    public function index($value, $params, $context)
    {
        if (!$value || !is_string($value)) {
            return $value;
        }

        $settings = $this->getSettings();
        $hashtagSettings = $settings['hashtags'] ?? [];

        if (!($hashtagSettings['enabled'] ?? false)) {
            return $value;
        }

        $taxonomy = $hashtagSettings['taxonomy'] ?? 'tags';

        // Regex for hashtags: #word (not preceded by non-whitespace, not purely numeric)
        // Negative lookahead for things inside < > to avoid replacing inside existing HTML tags
        $pattern = '/(?<!\S)#(?!\d+\b)([A-Za-z0-9_]+)(?![^<]*>)/u';

        return preg_replace_callback($pattern, function ($matches) use ($taxonomy) {
            $tagName = $matches[1];
            $slug = (string) Str::slug($tagName);

            // Try to find the local term URL
            $term = Term::find($taxonomy . '::' . $slug);
            $url = $term ? $term->uri() : "/{$taxonomy}/{$slug}";

            return '<a href="' . $url . '" class="hashtag" rel="tag">#' . $tagName . '</a>';
        }, $value);
    }

    protected function getSettings(): array
    {
        $path = resource_path('settings/activitypub.yaml');

        if (!File::exists($path)) {
            return [];
        }

        return YAML::parse(File::get($path));
    }
}
