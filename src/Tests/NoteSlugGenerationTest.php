<?php

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class NoteSlugGenerationTest extends TestCase
{
    // If your tests use database, uncomment this
    // use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure we have a user
        if (!User::all()->first()) {
            User::make()->email('test@example.com')->save();
        }
        User::current(User::all()->first());
    }

    #[Test]
    public function it_ensures_note_slug_and_title_match_id_on_create()
    {
        // 1. Create a note without specifying title/slug (mimicking CP behavior)
        $note = Entry::make()
            ->collection('notes')
            ->data(['content' => 'Test content']);

        // 2. Save the note
        // If recursion exists, this might timeout or crash
        $start = microtime(true);
        $note->save();
        $end = microtime(true);

        // 3. Assertions
        $this->assertEquals($note->id(), $note->slug(), 'Slug should match ID');
        $this->assertEquals($note->id(), $note->get('title'), 'Title should match ID');

        // Check for speed (recursion would be slow) - arbitrary threshold
        $duration = $end - $start;
        // This is a loose check but helpful for regression
        $this->assertLessThan(1.0, $duration, 'Saving took too long, possible recursion loop');

        // Cleanup
        $note->delete();
    }
}
