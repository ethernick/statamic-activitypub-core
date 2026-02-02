@extends('statamic::layout')

@section('title', 'ActivityPub Logs')

@section('content')
    <header class="mb-6">
        <h1>ActivityPub Logs</h1>
    </header>

    <div class="card p-0">
        <div class="flex justify-between items-center p-4 border-b">
            <h2 class="font-bold">Log File: storage/logs/activitypub.log</h2>
            <form action="{{ cp_route('activitypub.logs.clear') }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-danger btn-sm">Clear Logs</button>
            </form>
        </div>
        <div class="p-4 bg-gray-900 text-gray-200 font-mono text-sm overflow-auto"
            style="max-height: 600px; white-space: pre-wrap;">
            @if(empty($content))
                <span class="text-gray-500 italic">Log file is empty.</span>
            @else
                {{ $content }}
            @endif
        </div>
    </div>
@endsection