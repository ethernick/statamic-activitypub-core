<?php

namespace Ethernick\ActivityPubCore\Tests;

use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Tests\TestCase;
use Carbon\Carbon;

class InboxSortTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!\Statamic\Facades\Collection::find('notes')) {
            \Statamic\Facades\Collection::make('notes')->save();
        }
    }

    public function test_inbox_api_returns_newest_first()
    {
        $user = User::make()->id('test-user')->email('test-sort@example.com')->makeSuper();
        $user->save();
        $this->actingAs($user);

        // note-1: Newest (should be first)
        $note1 = Entry::make()->collection('notes')->id('note-1')->slug('note-1')
            ->data(['content' => 'Newest', 'date' => Carbon::now()->subHours(1)->format('Y-m-d H:i')])
            ->published(true);
        $note1->save();

        // note-2: Middle
        $note2 = Entry::make()->collection('notes')->id('note-2')->slug('note-2')
            ->data(['content' => 'Middle', 'date' => Carbon::now()->subHours(2)->format('Y-m-d H:i')])
            ->published(true);
        $note2->save();

        // note-3: Oldest (should be last)
        $note3 = Entry::make()->collection('notes')->id('note-3')->slug('note-3')
            ->data(['content' => 'Oldest', 'date' => Carbon::now()->subHours(3)->format('Y-m-d H:i')])
            ->published(true);
        $note3->save();

        $response = $this->get(cp_route('activitypub.inbox.api'));

        $response->assertOk();
        $data = $response->json('data');

        $ids = collect($data)->pluck('id')->take(3)->toArray();

        // Expected ID Sort (Desc): note-3, note-2, note-1
        $this->assertEquals(['note-3', 'note-2', 'note-1'], $ids);
    }
}
