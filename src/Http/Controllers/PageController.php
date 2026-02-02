<?php

namespace Ethernick\ActivityPubCore\Http\Controllers;

class PageController extends BaseObjectController
{
    protected function getCollectionSlug()
    {
        return 'pages';
    }

    protected function returnIndexView($actor)
    {
        return (new \Statamic\View\View)
            ->template('activitypub::pages')
            ->layout('layout')
            ->with([
                'actor' => $actor,
                'title' => $actor->get('title') . ' - Pages'
            ]);
    }

    protected function returnShowView($actor, $item)
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
