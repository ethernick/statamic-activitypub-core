<?php

namespace Ethernick\ActivityPubCore\Tests;

use Ethernick\ActivityPubCore\Services\ThreadService;
use Statamic\Facades\Entry;
use Tests\TestCase;

class ThreadServiceTest extends TestCase
{
    public function test_it_propagates_increments_up_the_chain()
    {
        // Grandparent
        $gp = Entry::make()->collection('notes')->id('gp')->data(['reply_count' => 0]);
        $gp->save();

        // Parent (reply to GP)
        $p = Entry::make()->collection('notes')->id('p')->data(['in_reply_to' => 'gp', 'reply_count' => 0]);
        $p->save();

        // Child (reply to P) calls increment on P
        ThreadService::increment('p');

        // Reload
        $gp = Entry::find('gp');
        $p = Entry::find('p');

        $this->assertEquals(1, $p->get('reply_count'));
        $this->assertEquals(1, $gp->get('reply_count'));

        // Add another child to P
        ThreadService::increment('p');

        $gp = Entry::find('gp');
        $p = Entry::find('p');

        $this->assertEquals(2, $p->get('reply_count'));
        $this->assertEquals(2, $gp->get('reply_count'));
    }

    public function test_it_propagates_decrements_up_the_chain()
    {
        // Grandparent -> starts with 2
        $gp = Entry::make()->collection('notes')->id('gp2')->data(['reply_count' => 2]);
        $gp->save();

        // Parent -> starts with 2
        $p = Entry::make()->collection('notes')->id('p2')->data(['in_reply_to' => 'gp2', 'reply_count' => 2]);
        $p->save();

        ThreadService::decrement('p2');

        $gp = Entry::find('gp2');
        $p = Entry::find('p2');

        $this->assertEquals(1, $p->get('reply_count'));
        $this->assertEquals(1, $gp->get('reply_count'));
    }

    public function test_it_resolves_local_urls_in_thread_service()
    {
        // Ensure notes collection has a route
        $collection = \Statamic\Facades\Collection::findByHandle('notes') ?? \Statamic\Facades\Collection::make('notes');
        $collection->routes('/notes/{slug}')->save();

        // 1. Create a parent note with a known URI/Slug
        $slug = 'parent-note-' . time();
        $parent = Entry::make()
            ->collection('notes')
            ->slug($slug)
            ->data(['reply_count' => 0]);
        $parent->save();

        // 2. Construct the full URL
        $url = $parent->absoluteUrl();

        // 3. Call increment using the URL
        ThreadService::increment($url);

        // 4. Verify count increased
        $parent = Entry::find($parent->id());
        $this->assertEquals(1, $parent->get('reply_count'));
    }
}
