@extends('statamic::layout')
@section('title', 'Queue Status')

@section('content')

    <header class="mb-6 flex justify-between items-center">
        <h1>Queue Status</h1>
    </header>

    <queue-status></queue-status>

@endsection