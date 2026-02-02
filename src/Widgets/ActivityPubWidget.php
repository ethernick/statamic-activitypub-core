<?php

namespace Ethernick\ActivityPubCore\Widgets;

use Statamic\Widgets\Widget;

class ActivityPubWidget extends Widget
{
    protected static $handle = 'activitypub';

    public function html()
    {
        return view('activitypub::widgets.activitypub', [
            'title' => 'Into the Fediverse',
            'description' => 'Welcome to ActivityPub. Follow, and be followed. Discuss, post, toot, boost, and more! Share, and be shared.',
            'link' => cp_route('activitypub.inbox.index'),
            'button_title' => 'Inbox',
            'button_description' => 'Find out wazzzzup!',
        ]);
    }
}
