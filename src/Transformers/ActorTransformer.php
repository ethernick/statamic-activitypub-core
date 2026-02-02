<?php

namespace Ethernick\ActivityPubCore\Transformers;

use Statamic\Entries\Entry;

class ActorTransformer
{
    public function transform(Entry $entry)
    {
        $url = $this->sanitizeUrl(url('@' . $entry->slug()));
        $handle = $entry->slug();
        $domain = request()->getHost();

        // Load settings to check if quotes are allowed
        $settingsPath = resource_path('settings/activitypub.yaml');
        $allowQuotes = false;

        if (file_exists($settingsPath)) {
            $settings = \Statamic\Facades\YAML::parse(\Statamic\Facades\File::get($settingsPath));
            $allowQuotes = $settings['allow_quotes'] ?? false;
        }

        $data = [
            '@context' => [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1',
                [
                    'manuallyApprovesFollowers' => 'as:manuallyApprovesFollowers',
                    'gts' => 'https://gotosocial.org/ns#',
                    'interactionPolicy' => [
                        '@id' => 'gts:interactionPolicy',
                        '@type' => '@id',
                    ],
                    'canQuote' => [
                        '@id' => 'gts:canQuote',
                        '@type' => '@id',
                    ],
                    'automaticApproval' => [
                        '@id' => 'gts:automaticApproval',
                        '@type' => '@id',
                    ],
                ],
            ],
            'id' => $url,
            'type' => 'Person',
            'preferredUsername' => $handle,
            'name' => $entry->get('title'),
            'summary' => $entry->get('content') ?? '',
            'inbox' => $url . '/inbox',
            'outbox' => $url . '/outbox',
            'followers' => $url . '/followers',
            'following' => $url . '/following',
            'liked' => $url . '/liked',
            'url' => $url,
            'manuallyApprovesFollowers' => false,
            'discoverable' => true,
            'published' => $entry->date() ? $entry->date()->toIso8601String() : $entry->lastModified()->toIso8601String(),
            'publicKey' => [
                'id' => $url . '#main-key',
                'owner' => $url,
                'publicKeyPem' => $entry->get('public_key') ?? '',
            ],
            // 'endpoints' => [
            //     'sharedInbox' => $this->sanitizeUrl(url('/activitypub/sharedInbox')),
            // ],
            'icon' => $this->getIcon($entry),
        ];

        // Add interactionPolicy if quotes are allowed
        if ($allowQuotes) {
            $data['interactionPolicy'] = [
                'canQuote' => [
                    'automaticApproval' => ['https://www.w3.org/ns/activitystreams#Public'],
                ],
            ];
        }

        return $data;
    }

    protected function sanitizeUrl($url)
    {
        return str_replace('://www.', '://', $url);
    }

    protected function getIcon(Entry $entry)
    {
        $staticPath = 'activitypub/avatars/' . $entry->slug() . '.jpg';
        if (file_exists(public_path($staticPath))) {
            return [
                'type' => 'Image',
                'mediaType' => 'image/jpeg',
                'url' => url($staticPath),
            ];
        }

        $avatar = $entry->avatar;
        if ($avatar) {
            // Fallback to original URL if static generation failed
            // $url = \Statamic\Facades\Image::manipulate($avatar, ['w' => 256, 'h' => 256, 'fit' => 'crop_focal']); 
            // Return raw URL if Glide is problematic for now

            return [
                'type' => 'Image',
                'mediaType' => 'image/jpeg',
                'url' => $avatar->url(),
            ];
        }
        return null;
    }
}
