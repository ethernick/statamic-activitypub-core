@extends('statamic::layout')


@section('content')
    <activity-pub-inbox :initial-actors='@json($localActors ?? [])' :api-url="'{{ cp_route('activitypub.inbox.api') }}'"
        :reply-url="'{{ cp_route('activitypub.inbox.reply') }}'" :like-url="'{{ cp_route('activitypub.like') }}'"
        :unlike-url="'{{ cp_route('activitypub.unlike') }}'" :announce-url="'{{ cp_route('activitypub.announce') }}'"
        :undo-announce-url="'{{ cp_route('activitypub.undo-announce') }}'" :create-note-url="'{{ $createNoteUrl }}'"
        :store-note-url="'{{ cp_route('activitypub.inbox.store-note') }}'"
        :store-poll-url="'{{ cp_route('activitypub.inbox.store-poll') }}'"
        :update-note-url="'{{ cp_route('activitypub.inbox.update-note', ['id' => 'ID']) }}'"
        :delete-url="'{{ cp_route('activitypub.inbox.delete') }}'"
        :link-preview-url="'{{ cp_route('activitypub.inbox.link-preview') }}'"
        :batch-link-preview-url="'{{ cp_route('activitypub.inbox.batch-link-preview') }}'"
        :markdown-preview-url="'{{ cp_route('markdown.preview') }}'" :hashtag-field="'{{ $hashtagField }}'"
        :hashtag-taxonomy="'{{ $hashtagTaxonomy }}'" :hashtag-enabled="{{ $hashtagEnabled ? 'true' : 'false' }}"
        :search-terms-url="'{{ cp_route('activitypub.inbox.search-terms') }}'"></activity-pub-inbox>
@endsection