@extends('statamic::layout')

@section('title', $title)

@section('content')
    <div class="flex items-center justify-center h-full">
        <div class="text-center">
            <h1 class="mb-4">{{ $title }}</h1>
            <p class="text-gray-600">Coming soon...</p>
        </div>
    </div>
@endsection