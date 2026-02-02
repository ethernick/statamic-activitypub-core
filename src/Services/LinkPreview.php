<?php

namespace Ethernick\ActivityPubCore\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LinkPreview
{
    public static function extractUrl($html)
    {
        if (empty($html)) {
            return null;
        }

        // Improved regex to capture attributes and content
        if (preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attributes = $match[1];
                $rawContent = $match[2];

                // Extract href
                $url = null;
                if (preg_match('/href=["\']([^"\']+)["\']/i', $attributes, $hrefMatch)) {
                    $url = $hrefMatch[1];
                }

                if (!$url)
                    continue;

                // Check Class for 'mention' or 'hashtag'
                if (preg_match('/class=["\']([^"\']*)["\']/i', $attributes, $classMatch)) {
                    $classes = $classMatch[1];
                    if (preg_match('/\b(mention|hashtag)\b/i', $classes)) {
                        continue;
                    }
                }

                // Check Content for @ or # (decoding entities first)
                $content = trim(strip_tags(html_entity_decode($rawContent)));

                if (str_starts_with($content, '@') || str_starts_with($content, '#')) {
                    continue;
                }

                return $url;
            }
        }

        return null;
    }

    public static function fetch($url)
    {
        try {
            // Fetch content with a timeout
            $response = Http::timeout(5)->get($url);

            if ($response->failed()) {
                return null;
            }

            $html = $response->body();
            if (empty($html)) {
                return null;
            }

            // Extract Meta Tags
            $data = [];

            // OpenGraph
            if (preg_match('/<meta[^>]+property="og:title"[^>]+content="([^"]+)"/i', $html, $matches)) {
                $data['title'] = htmlspecialchars_decode($matches[1]);
            }
            if (preg_match('/<meta[^>]+property="og:description"[^>]+content="([^"]+)"/i', $html, $matches)) {
                $data['description'] = htmlspecialchars_decode($matches[1]);
            }
            if (preg_match('/<meta[^>]+property="og:image"[^>]+content="([^"]+)"/i', $html, $matches)) {
                $data['image'] = $matches[1];
            }
            if (preg_match('/<meta[^>]+property="og:site_name"[^>]+content="([^"]+)"/i', $html, $matches)) {
                $data['site_name'] = htmlspecialchars_decode($matches[1]);
            }
            $data['url'] = $url;

            // Twitter Fallbacks
            if (empty($data['title']) && preg_match('/<meta[^>]+name="twitter:title"[^>]+content="([^"]+)"/i', $html, $matches)) {
                $data['title'] = htmlspecialchars_decode($matches[1]);
            }
            if (empty($data['description']) && preg_match('/<meta[^>]+name="twitter:description"[^>]+content="([^"]+)"/i', $html, $matches)) {
                $data['description'] = htmlspecialchars_decode($matches[1]);
            }
            if (empty($data['image']) && preg_match('/<meta[^>]+name="twitter:image"[^>]+content="([^"]+)"/i', $html, $matches)) {
                $data['image'] = $matches[1];
            }

            // HTML Title Fallback
            if (empty($data['title']) && preg_match('/<title>([^<]+)<\/title>/i', $html, $matches)) {
                $data['title'] = htmlspecialchars_decode($matches[1]);
            }

            // Domain fallback for site_name
            if (empty($data['site_name'])) {
                $parsed = parse_url($url);
                $data['site_name'] = $parsed['host'] ?? '';
            }

            // Must have at least a title or description to be worth showing
            if (empty($data['title']) && empty($data['description'])) {
                return null;
            }

            // Ensure image URL is absolute
            if (!empty($data['image']) && str_starts_with($data['image'], '/')) {
                $parsed = parse_url($url);
                $base = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? '');
                $data['image'] = $base . $data['image'];
            }

            return $data;

        } catch (\Exception $e) {
            Log::error('LinkPreview fetch failed: ' . $e->getMessage());
            return null;
        }
    }
}
