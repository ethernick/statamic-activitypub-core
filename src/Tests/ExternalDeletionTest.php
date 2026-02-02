<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Tests\TestCase;

class ExternalDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    #[Test]
    public function it_does_not_create_delete_activity_for_external_item()
    {
        // 1. Create an external note
        $note = Entry::make()
            ->collection('notes')
            ->slug('external-note')
            ->data([
                'title' => 'External Note',
                'is_internal' => false, // KEY FLAG
                'activitypub_id' => 'https://external.com/note/1',
                'actor' => 'https://external.com/user/1'
            ]);
        $note->save();

        $this->assertNotNull(Entry::find($note->id()));

        // 2. Delete the note
        $note->delete();

        // 3. Assert NO Activity was created
        $activity = Entry::query()
            ->where('collection', 'activities')
            ->where('type', 'Delete')
            ->get()
            ->first(function ($entry) use ($note) {
                $obj = $entry->get('object');
                if (is_array($obj))
                    $obj = $obj[0] ?? null;
                return $obj === $note->id();
            });

        $this->assertNull($activity, 'A Delete activity should NOT have been generated for an external note.');
    }

    #[Test]
    public function it_creates_delete_activity_for_internal_item()
    {
        // 1. Create an internal note
        $note = Entry::make()
            ->collection('notes')
            ->slug('internal-note')
            ->data([
                'title' => 'Internal Note',
                'is_internal' => true,
                // 'actor' => 'local-user-id' // Let system find current user or set explicit
            ]);

        $user = User::make()->email('test@example.com')->makeSuper();
        $user->save();
        $note->set('actor', $user->id());

        $note->save();

        // 2. Delete the note
        $note->delete();

        // 3. Assert Activity WAS created
        $activity = Entry::query()
            ->where('collection', 'activities')
            ->where('type', 'Delete')
            ->get()
            ->first(function ($entry) use ($note) {
                $obj = $entry->get('object');
                if (is_array($obj))
                    $obj = $obj[0] ?? null;
                return $obj === $note->id();
            });

        $this->assertNotNull($activity, 'A Delete activity SHOULD have been generated for an internal note.');
    }
}
