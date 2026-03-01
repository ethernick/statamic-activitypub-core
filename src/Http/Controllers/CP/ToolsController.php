<?php

namespace Ethernick\ActivityPubCore\Http\Controllers\CP;

use Statamic\Http\Controllers\CP\CpController;

class ToolsController extends CpController
{
    public function index()
    {
        $tools = [
            [
                'title' => 'Queue',
                'description' => 'Monitor and flush the background processes powering ActivityPub.',
                'icon' => 'database',
                'url' => cp_route('activitypub.queue.index'),
            ]
        ];

        return view('activitypub::cp.tools.index', [
            'title' => 'ActivityPub Tools',
            'tools' => $tools,
            'icon' => file_get_contents(__DIR__ . '/../../../../resources/svg/asterism.svg'),
        ]);
    }
}
