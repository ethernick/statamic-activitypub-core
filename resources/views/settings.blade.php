@extends('statamic::layout')
@section('title', 'ActivityPub Settings')

@section('content')
    <activity-pub-settings :initial-settings='@json($settings)' :collections='@json($collections)'
        :taxonomies='@json($taxonomies)' :types='@json($types)' save-url="{{ cp_route('activitypub.settings.update') }}"
        logs-url="{{ $logsUrl }}" auto-block-logs-url="{{ $autoBlockLogsUrl }}" 
        clear-auto-block-logs-url="{{ $clearAutoBlockLogsUrl }}" resolve-handle-url="{{ $resolveHandleUrl }}"
        :extra-tabs='@json($extraTabs)'>
    </activity-pub-settings>

@endsection