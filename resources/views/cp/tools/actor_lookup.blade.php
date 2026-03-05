@extends('statamic::layout')
@section('title', $title)

@section('content')
    <div class="flex items-center mb-6">
        <h1 class="flex-1">{{ $title }}</h1>
    </div>

    <activity-pub-actor-lookup lookup-url="{{ cp_route('activitypub.actor-lookup.lookup') }}"></activity-pub-actor-lookup>

@endsection