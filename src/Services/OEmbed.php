<?php

namespace Ethernick\ActivityPubCore\Services;

class OEmbed
{
    public static function resolve($content)
    {
        // Find all links in content, skip mention links (class="mention" or class="u-url mention")
        // We want the last non-mention link, which is usually the shared URL
        if (!preg_match_all('/<a[^>]+href="([^"]+)"[^>]*>/i', $content, $matches, PREG_SET_ORDER)) {
            return null;
        }

        // Find the last link that isn't a mention
        $url = null;
        foreach (array_reverse($matches) as $match) {
            // Skip if it's a mention link
            if (strpos($match[0], 'class="mention"') !== false ||
                strpos($match[0], 'class="u-url mention"') !== false) {
                continue;
            }
            $url = $match[1];
            break;
        }

        if (!$url) {
            return null;
        }

        try {
            $embed = new \Embed\Embed();
            $info = $embed->get($url);
            $code = $info->code;

            if ($code && $code->html) {
                return [
                    'type' => 'rich', // Generalized logic
                    'provider' => $info->providerName,
                    'html' => $code->html,
                ];
            }
        } catch (\Exception $e) {
            // Fallback to null (Link Preview)
            return null;
        }

        return null;
    }
}
