@extends('statamic::layout')
@section('title', $title)

@section('content')
    <div class="flex items-center justify-between mb-3">
        <h1>{{ $title }}</h1>
    </div>

    @foreach($myActors as $localActor)
        <div class="card p-0 mb-4">
            <div class="flex items-center justify-between p-3 border-b">
                <h2 class="font-bold text-lg">{{ $localActor->title }}'s Followers</h2>
            </div>
            
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Actor</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        // Filter followers for this specific local actor
                        $followersForActor = $actors->filter(function($f) use ($localActor) {
                            $theirFollowers = $localActor->get('followed_by_actors', []) ?: [];
                            return in_array($f->id(), $theirFollowers);
                        });
                    @endphp

                    @if($followersForActor->isEmpty())
                        <tr>
                            <td colspan="2" class="text-gray-500 text-center py-4">No followers found on this page.</td>
                        </tr>
                    @else
                        @foreach($followersForActor as $actor)
                            @php
                                $isBlocking = in_array($actor->id(), $localActor->get('blocks', []) ?: []);
                                $isFollowing = in_array($actor->id(), $localActor->get('following_actors', []) ?: []);
                            @endphp
                            <tr>
                                <td>
                                    <div class="flex items-center gap-2">
                                        @if($actor->get('avatar'))
                                           <img src="{{ $actor->augmentedValue('avatar')->value()?->url() }}" class="w-8 h-8 rounded-full object-cover">
                                        @else
                                            <div class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center text-xs text-gray-500">
                                                {{ substr($actor->title, 0, 1) }}
                                            </div>
                                        @endif
                                        <div>
                                            <div class="font-bold">{{ $actor->title }}</div>
                                            <div class="text-xs text-gray-500">{{ $actor->get('activitypub_id') }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-right">
                                    <div class="flex gap-2 justify-end">
                                        {{-- Follow/Unfollow --}}
                                        @if($isFollowing)
                                            <button class="btn-xs btn-default" onclick="performAction('unfollow', '{{ $actor->id() }}', '{{ $localActor->id() }}')">Unfollow</button>
                                        @else
                                            <button class="btn-xs btn-primary" onclick="performAction('follow', '{{ $actor->get('activitypub_id') }}', '{{ $localActor->id() }}')">Follow Back</button>
                                        @endif

                                        {{-- Block/Unblock --}}
                                        @if($isBlocking)
                                            <button class="btn-xs btn-danger" onclick="performAction('unblock', '{{ $actor->id() }}', '{{ $localActor->id() }}')">Unblock</button>
                                        @else
                                            <button class="btn-xs" onclick="performAction('block', '{{ $actor->id() }}', '{{ $localActor->id() }}')">Block</button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    @endif
                </tbody>
            </table>
        </div>
    @endforeach

    <script>
        function performAction(action, targetId, senderId) {
            // Simple confirmation
            // if (!confirm('Are you sure you want to ' + action + '?')) return;

            Statamic.$axios.post('/cp/activitypub/' + action, {
                id: targetId,
                sender: senderId
            }).then(response => {
                Statamic.$toast.success('Action ' + action + ' successful');
                // Reload page to reflect state
                window.location.reload();
            }).catch(error => {
                console.error(error);
                Statamic.$toast.error('Action failed: ' + (error.response?.data?.error || error.message));
            });
        }
    </script>
    
    <div class="p-3">
        {{ $actors->links() }}
    </div>
@endsection
