@extends('statamic::layout')
@section('title', $title)

@section('content')

    <header class="mb-6">
        <div class="flex items-center">
            <h1 class="flex-1">{{ $title }}</h1>
        </div>
    </header>

    <div class="card p-0">
        <table class="data-table">
            <tbody>
                @foreach ($tools as $tool)
                    <tr>
                        <td>
                            <div class="flex items-center">
                                <div class="w-8 h-8 mr-4 text-gray-800">
                                    @cp_svg($tool['icon'])
                                </div>
                                <div class="flex-1">
                                    <a href="{{ $tool['url'] }}" class="text-blue text-base font-bold">{{ $tool['title'] }}</a>
                                    <p class="text-sm text-gray pt-1">{{ $tool['description'] }}</p>
                                </div>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

@endsection