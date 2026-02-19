@extends('statamic::layout')
@section('title', 'ActivityPub Settings')

@section('content')
    <activity-pub-settings :initial-settings='@json($settings)' :collections='@json($collections)'
        :taxonomies='@json($taxonomies)' :types='@json($types)' save-url="{{ cp_route('activitypub.settings.update') }}"
        logs-url="{{ cp_route('activitypub.logs') }}"></activity-pub-settings>
@endsection