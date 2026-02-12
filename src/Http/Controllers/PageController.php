<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Http\Controllers;

class PageController extends BaseObjectController
{
    protected function getCollectionSlug(): string
    {
        return 'pages';
    }

    protected function returnIndexView(mixed $actor): mixed
    {
        return (new \Statamic\View\View)
            ->template('activitypub::pages')
            ->layout('layout')
            ->with([
                'actor' => $actor,
                'title' => $actor->get('title') . ' - Pages'
            ]);
    }

    protected function returnShowView(mixed $actor, mixed $item): mixed
    {
        return (new \Statamic\View\View)
            ->template('activitypub::page')
            ->layout('layout')
            ->with([
                'actor' => $actor,
                'page' => $item,
                'title' => $item->get('title') ?? 'Page'
            ]);
    }
}
