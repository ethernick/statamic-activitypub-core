<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Http\Handlers;

use Ethernick\ActivityPubCore\Contracts\StoreHandlerInterface;
use Illuminate\Http\Request;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\File;
use Statamic\Facades\YAML;
use Ethernick\ActivityPubCore\Services\ActivityPubUtils;

class NoteStoreHandler implements StoreHandlerInterface
{
    public function store(Request $request): EntryContract
    {
        $request->validate([
            'content' => 'required|string',
            'actor' => 'required|string',
            'date' => 'nullable|string',
            'content_warning' => 'nullable|string',
            'quote_of' => 'nullable|string',
            'tags' => 'nullable|array',
        ]);

        $actor = Entry::find($request->input('actor'));
        if (!$actor) {
            throw new \Exception('Actor not found');
        }

        $date = \Illuminate\Support\Carbon::parse($request->input('date', now()));
        $quoteOf = $request->input('quote_of');

        $path = ActivityPubUtils::settingsPath();
        $settings = File::exists($path) ? YAML::parse(File::get($path)) : [];
        $hashtagField = $settings['hashtags']['field'] ?? 'tags';

        $entry = Entry::make()
            ->collection('notes')
            ->published(true)
            ->data([
                'content' => $request->input('content'),
                'actor' => [$actor->id()],
                'date' => $date->format('Y-m-d H:i'),
                'sensitive' => $request->filled('content_warning'),
                'summary' => $request->input('content_warning'),
                'quote_of' => $quoteOf ? [$quoteOf] : null,
                $hashtagField => $request->input('tags', []),
                'is_internal' => true,
                'quote_authorization_status' => $quoteOf ? 'pending' : null,
            ]);

        $entry->save();

        return $entry;
    }
}
