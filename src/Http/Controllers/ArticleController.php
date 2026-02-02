<?php

namespace Ethernick\ActivityPubCore\Http\Controllers;

use Ethernick\ActivityPubCore\Contracts\ActivityHandlerInterface;
use Statamic\Facades\Entry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Ethernick\ActivityPubCore\Services\ActorResolver;

class ArticleController extends BaseObjectController implements ActivityHandlerInterface
{
    public static function getHandledActivityTypes(): array
    {
        return [
            'Create:Article',
            'Update:Article',
            'Delete:Article',
        ];
    }

    protected function getCollectionSlug()
    {
        return 'articles'; // Assuming 'articles' collection exists
    }

    protected function returnIndexView($actor)
    {
        return (new \Statamic\View\View)
            ->template('activitypub::articles')
            ->layout('layout')
            ->with([
                'actor' => $actor,
                'title' => $actor->get('title') . ' - Articles'
            ]);
    }

    protected function returnShowView($actor, $item)
    {
        return (new \Statamic\View\View)
            ->template('activitypub::article')
            ->layout('layout')
            ->with([
                'actor' => $actor,
                'article' => $item,
                'title' => $item->get('title') ?? 'Article'
            ]);
    }

    // --- Activity Handlers ---

    public function handleCreate(array $payload, $localActor, $externalActor)
    {
        Log::info("ArticleController: Handling Create Article");
        $object = $payload['object'] ?? null;
        if (!$object)
            return false;

        if ($externalActor && !$externalActor->id())
            $externalActor->save();

        $following = $localActor->get('following_actors', []) ?: [];
        $to = $object['to'] ?? [];
        $cc = $object['cc'] ?? [];
        if (!is_array($to))
            $to = [$to];
        if (!is_array($cc))
            $cc = [$cc];
        $addressed = array_merge($to, $cc);
        $myApId = $localActor->get('activitypub_id') ?: $localActor->absoluteUrl();
        $isMentioned = in_array($myApId, $addressed);

        $inReplyTo = $object['inReplyTo'] ?? null;
        $isReplyToKnown = false;
        if ($inReplyTo) {
            if (is_array($inReplyTo))
                $inReplyTo = $inReplyTo['id'] ?? $inReplyTo['url'] ?? $inReplyTo[0] ?? null;
            if (is_string($inReplyTo)) {
                $isReplyToKnown = Entry::query()->whereIn('collection', ['notes', 'articles'])->where('activitypub_id', $inReplyTo)->exists();
            }
        }

        if (in_array($externalActor->id(), $following) || $isMentioned || $isReplyToKnown) {
            $this->createArticleEntry($object, $externalActor);
            return true;
        } else {
            Log::info("ArticleController: Ignoring Create from non-followed/irrelevant actor");
        }
        return false;
    }

    public function handleUpdate(array $payload, $localActor, $externalActor)
    {
        Log::info("ArticleController: Handling Update Article");
        $object = $payload['object'] ?? null;
        if (!$object)
            return false;

        $id = $object['id'] ?? null;
        if (!$id)
            return false;

        $existing = Entry::query()->where('collection', 'articles')->where('activitypub_id', $id)->first();

        // Allowed if connected or existing
        $following = $localActor->get('following_actors', []) ?: [];
        $followedBy = $localActor->get('followed_by_actors', []) ?: [];
        $isConnected = in_array($externalActor->id(), $following) || in_array($externalActor->id(), $followedBy);

        if ($isConnected || $existing) {
            $this->updateArticleEntry($object, $externalActor);
            return true;
        }
        return false;
    }

    public function handleDelete(array $payload, $localActor, $externalActor)
    {
        $object = $payload['object'] ?? null;
        $objectId = is_string($object) ? $object : ($object['id'] ?? null);
        if (!$objectId)
            return false;

        $existing = Entry::query()->where('collection', 'articles')->where('activitypub_id', $objectId)->first();
        if ($existing) {
            $existingActor = $existing->get('actor');
            if (is_array($existingActor))
                $existingActor = $existingActor[0] ?? null;

            if ($existingActor === $externalActor->id()) {
                $existing->delete();
                return true;
            }
        }
        return false;
    }

    protected function createArticleEntry($object, $authorActor)
    {
        $id = $object['id'] ?? null;
        if ($id) {
            $existing = Entry::query()->where('collection', 'articles')->where('activitypub_id', $id)->first();
            if ($existing)
                return $existing;
        }

        $uuid = (string) Str::uuid();
        $content = $object['content'] ?? '';
        $dateStr = $object['published'] ?? $object['updated'] ?? null;
        $date = $dateStr ? \Illuminate\Support\Carbon::parse($dateStr) : now();
        $published = $date->toIso8601String();

        $title = $object['name'] ?? 'Untitled Article'; // Articles usually have names
        $summary = $object['summary'] ?? null;
        $sensitive = $object['sensitive'] ?? false;
        if (empty($summary) && $sensitive)
            $summary = 'Sensitive Content';

        $entry = Entry::make()
            ->collection('articles')
            ->id($uuid)
            ->slug($uuid)
            ->date($date)
            ->data([
                'title' => $title,
                'content' => $content,
                'actor' => $authorActor->id(),
                'date' => $published,
                'activitypub_id' => $id,
                'activitypub_json' => json_encode($object),
                'is_internal' => false,
                'sensitive' => $sensitive,
                'summary' => $summary,
            ]);

        // Mentions
        $mentioned = [];
        if (isset($object['tag']) && is_array($object['tag'])) {
            foreach ($object['tag'] as $tag) {
                if (($tag['type'] ?? '') === 'Mention' && isset($tag['href'])) {
                    $mentioned[] = $tag['href'];
                }
            }
        }
        if (!empty($mentioned)) {
            $entry->set('mentioned_urls', array_values(array_unique($mentioned)));
        }

        $entry->save();
        return $entry;
    }

    protected function updateArticleEntry($object, $externalActor)
    {
        $id = $object['id'] ?? null;
        if (!$id)
            return;
        $entry = Entry::query()->where('collection', 'articles')->where('activitypub_id', $id)->first();

        if ($entry) {
            $actorId = $entry->get('actor');
            if (is_array($actorId))
                $actorId = $actorId[0] ?? null;
            if ($actorId !== $externalActor->id())
                return;

            if (isset($object['content']))
                $entry->set('content', $object['content']);
            if (isset($object['name']))
                $entry->set('title', $object['name']);
            if (isset($object['summary']))
                $entry->set('summary', $object['summary']);

            $entry->set('activitypub_json', json_encode($object));
            $entry->save();
            Log::info("ArticleController: Updated article $id");
        }
    }
}
