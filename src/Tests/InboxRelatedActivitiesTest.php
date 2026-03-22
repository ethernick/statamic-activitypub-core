<?php

namespace Ethernick\ActivityPubCore\Tests;

use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class InboxRelatedActivitiesTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->setupCollections(['actors', 'notes', 'activities']);

        // Ensure blueprints exist in sandbox
        if (!\Statamic\Facades\Blueprint::find('collections/notes/note')) {
            \Statamic\Facades\Blueprint::make('note')->setNamespace('collections.notes')->save();
        }
        if (!\Statamic\Facades\Blueprint::find('collections/activities/activity')) {
            \Statamic\Facades\Blueprint::make('activity')->setNamespace('collections.activities')->save();
        }

        \Statamic\Facades\Blink::flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_activities_endpoint_returns_related_activities()
    {
        $user = User::make()->id('test-admin')->email('admin@example.com')->makeSuper();
        $user->save();
        $this->actingAs($user);

        // 1. Create a Note (mark as external to prevent auto-generation of Create activity)
        $noteId = 'http://example.com/notes/123';
        $note = Entry::make()
            ->collection('notes')
            ->slug('test-note')
            ->data([
                'content' => 'Original Content',
                'activitypub_id' => $noteId,
                'actor' => ['test-actor'],
                'is_internal' => false // Prevent auto-generation
            ])
            ->published(true);
        $note->save();

        // 2. Create a RELATED Activity (Update activity for the note)
        $relatedActivity = Entry::make()
            ->collection('activities')
            ->slug('test-related-activity')
            ->data([
                'type' => 'Update',
                'object' => $noteId, // Matching the Note's AP ID
                'activitypub_id' => 'http://example.com/activities/888',
                'actor' => ['test-actor']
            ])
            ->published(true);
        $relatedActivity->save();

        // --- Verify Activities Endpoint returns related activities ---
        $activitiesResponse = $this->get(cp_route('activitypub.inbox.activities', ['id' => $note->id()]));
        $activitiesResponse->assertOk();
        $activitiesData = $activitiesResponse->json('data');

        // Should contain the Update activity
        $this->assertCount(1, $activitiesData);
        $this->assertEquals('Update', $activitiesData[0]['type']);
    }
}
