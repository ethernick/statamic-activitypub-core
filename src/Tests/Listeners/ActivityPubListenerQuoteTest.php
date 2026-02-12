<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests\Listeners;

use Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Event;
use Statamic\Facades\Entry;
use Ethernick\ActivityPubCore\Jobs\SendQuoteRequest;
use Ethernick\ActivityPubCore\Tests\Concerns\BackupsFiles;
use PHPUnit\Framework\Attributes\Test;

class ActivityPubListenerQuoteTest extends TestCase
{
    use BackupsFiles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupFiles([]);
        Queue::fake();

        // Create activitypub.yaml config
        if (!file_exists(resource_path('settings'))) {
            mkdir(resource_path('settings'), 0755, true);
        }
        file_put_contents(
            resource_path('settings/activitypub.yaml'),
            "notes:\n  enabled: true\n  type: Note\n  federated: true\n"
        );
    }

    protected function tearDown(): void
    {
        $this->restoreBackedUpFiles();

        // Reset ActivityPubListener static caches
        $reflection = new \ReflectionClass(\Ethernick\ActivityPubCore\Listeners\ActivityPubListener::class);
        $settingsCache = $reflection->getProperty('settingsCache');
        $settingsCache->setAccessible(true);
        $settingsCache->setValue(null, null);

        $actorCache = $reflection->getProperty('actorCache');
        $actorCache->setAccessible(true);
        $actorCache->setValue(null, []);

        parent::tearDown();
    }

    #[Test]
    public function it_dispatches_quote_request_only_once_on_create()
    {
        // Create actor
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor')
            ->data([
                'activitypub_id' => 'https://test.com/users/test',
                'is_internal' => true,
            ]);
        $actor->save();

        // Create external note to quote
        $quotedNote = Entry::make()
            ->collection('notes')
            ->slug('external-note')
            ->data([
                'activitypub_id' => 'https://remote.com/notes/123',
                'is_internal' => false,
            ]);
        $quotedNote->save();

        // Create quote - this should trigger listener ONCE
        $quote = Entry::make()
            ->collection('notes')
            ->slug('quote-note')
            ->data([
                'content' => 'My quote',
                'actor' => [$actor->id()],
                'quote_of' => [$quotedNote->id()],
                'is_internal' => true,
            ]);

        // Save should trigger ActivityPubListener
        $quote->save();

        // Verify SendQuoteRequest was dispatched exactly once
        Queue::assertPushed(SendQuoteRequest::class, 1);
        Queue::assertPushed(SendQuoteRequest::class, function ($job) use ($quote) {
            return $job->quoteNoteId === $quote->id();
        });
    }

    #[Test]
    public function it_dispatches_quote_request_when_quote_added_via_edit()
    {
        // Create actor
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor')
            ->data(['activitypub_id' => 'https://test.com/users/test', 'is_internal' => true]);
        $actor->save();

        // Create external note
        $quotedNote = Entry::make()
            ->collection('notes')
            ->slug('external-note')
            ->data([
                'activitypub_id' => 'https://remote.com/notes/123',
                'is_internal' => false,
            ]);
        $quotedNote->save();

        // Create regular note WITHOUT quote_of
        $note = Entry::make()
            ->collection('notes')
            ->slug('regular-note')
            ->data([
                'content' => 'Regular post',
                'actor' => [$actor->id()],
                'is_internal' => true,
            ]);
        $note->save();

        // Clear queue after initial save
        Queue::fake();

        // Now edit to add quote_of
        $note->set('quote_of', [$quotedNote->id()]);
        $note->save();

        // Verify SendQuoteRequest was dispatched
        Queue::assertPushed(SendQuoteRequest::class, 1);
        Queue::assertPushed(SendQuoteRequest::class, function ($job) use ($note) {
            return $job->quoteNoteId === $note->id();
        });
    }

    #[Test]
    public function it_does_not_dispatch_quote_request_for_non_quote_posts()
    {
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor')
            ->data(['activitypub_id' => 'https://test.com/users/test', 'is_internal' => true]);
        $actor->save();

        // Create regular note without quote_of
        $note = Entry::make()
            ->collection('notes')
            ->slug('regular-note')
            ->data([
                'content' => 'Regular post',
                'actor' => [$actor->id()],
                'is_internal' => true,
            ]);
        $note->save();

        // Verify NO SendQuoteRequest was dispatched
        Queue::assertNotPushed(SendQuoteRequest::class);
    }

    #[Test]
    public function it_does_not_dispatch_duplicate_on_subsequent_saves()
    {
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor')
            ->data(['activitypub_id' => 'https://test.com/users/test', 'is_internal' => true]);
        $actor->save();

        $quotedNote = Entry::make()
            ->collection('notes')
            ->slug('external-note')
            ->data([
                'activitypub_id' => 'https://remote.com/notes/123',
                'is_internal' => false,
            ]);
        $quotedNote->save();

        // Create quote
        $quote = Entry::make()
            ->collection('notes')
            ->slug('quote-note')
            ->data([
                'content' => 'My quote',
                'actor' => [$actor->id()],
                'quote_of' => [$quotedNote->id()],
                'is_internal' => true,
            ]);
        $quote->save();

        // First save should dispatch
        Queue::assertPushed(SendQuoteRequest::class, 1);

        // Clear queue
        Queue::fake();

        // Edit the quote (but don't change quote_of)
        $quote->set('content', 'Updated content');
        $quote->save();

        // Should NOT dispatch again since quote_of wasn't added
        Queue::assertNotPushed(SendQuoteRequest::class);
    }

    #[Test]
    public function it_detects_quote_added_from_empty_array_to_populated()
    {
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor')
            ->data(['activitypub_id' => 'https://test.com/users/test', 'is_internal' => true]);
        $actor->save();

        $quotedNote = Entry::make()
            ->collection('notes')
            ->slug('external-note')
            ->data([
                'activitypub_id' => 'https://remote.com/notes/123',
                'is_internal' => false,
            ]);
        $quotedNote->save();

        // Create note with empty quote_of array
        $note = Entry::make()
            ->collection('notes')
            ->slug('note')
            ->data([
                'content' => 'Post',
                'actor' => [$actor->id()],
                'quote_of' => [],
                'is_internal' => true,
            ]);
        $note->save();

        Queue::fake();

        // Add quote
        $note->set('quote_of', [$quotedNote->id()]);
        $note->save();

        // Should dispatch
        Queue::assertPushed(SendQuoteRequest::class, 1);
    }

    #[Test]
    public function it_works_with_different_collection_types()
    {
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor')
            ->data(['activitypub_id' => 'https://test.com/users/test', 'is_internal' => true]);
        $actor->save();

        $quotedNote = Entry::make()
            ->collection('notes')
            ->slug('external-note')
            ->data([
                'activitypub_id' => 'https://remote.com/notes/123',
                'is_internal' => false,
            ]);
        $quotedNote->save();

        // Test with polls collection (if it exists)
        if (\Statamic\Facades\Collection::findByHandle('polls')) {
            $poll = Entry::make()
                ->collection('polls')
                ->slug('quote-poll')
                ->data([
                    'content' => 'Poll quoting',
                    'actor' => [$actor->id()],
                    'quote_of' => [$quotedNote->id()],
                    'is_internal' => true,
                ]);
            $poll->save();

            Queue::assertPushed(SendQuoteRequest::class, function ($job) use ($poll) {
                return $job->quoteNoteId === $poll->id();
            });
        }

        // Test with articles collection (if it exists)
        if (\Statamic\Facades\Collection::findByHandle('articles')) {
            Queue::fake(); // Reset

            $article = Entry::make()
                ->collection('articles')
                ->slug('quote-article')
                ->data([
                    'content' => 'Article quoting',
                    'actor' => [$actor->id()],
                    'quote_of' => [$quotedNote->id()],
                    'is_internal' => true,
                ]);
            $article->save();

            Queue::assertPushed(SendQuoteRequest::class, function ($job) use ($article) {
                return $job->quoteNoteId === $article->id();
            });
        }
    }
}
