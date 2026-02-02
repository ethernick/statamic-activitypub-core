@extends('statamic::layout')
@section('title', $title)

@section('content')
    <div class="flex items-center justify-between mb-3">
        <h1>{{ $title }}</h1>
    </div>

    <div class="card p-4 mb-4">
        <h2 class="font-bold mb-2">Find People to Follow</h2>
        <div class="flex gap-2">
            <input type="text" id="actor-search-input" class="input-text flex-1" placeholder="nick@whoisnick.com">
            <button id="actor-search-btn" class="btn-primary">Search</button>
        </div>
        <div id="search-results" class="mt-4 hidden">
            <!-- Results will be injected here -->
        </div>
    </div>

    @foreach($myActors as $localActor)
        <div class="card p-0 mb-4">
            <div class="flex items-center justify-between p-3 border-b">
                <h2 class="font-bold text-lg">{{ $localActor->title }}'s Following</h2>
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
                        // Filter "Following" for this specific local actor
                        $followingForActor = $actors->filter(function($f) use ($localActor) {
                            $followingIds = $localActor->get('following_actors', []) ?: [];
                            return in_array($f->id(), $followingIds);
                        });
                    @endphp

                    @if($followingForActor->isEmpty())
                        <tr>
                            <td colspan="2" class="text-gray-500 text-center py-4">Not following anyone yet.</td>
                        </tr>
                    @else
                        @foreach($followingForActor as $actor)
                            @php
                                $isBlocking = in_array($actor->id(), $localActor->get('blocks', []) ?: []);
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
                                        {{-- Unfollow --}}
                                        <button class="btn-xs btn-default" onclick="performAction('unfollow', '{{ $actor->id() }}', '{{ $localActor->id() }}')">Unfollow</button>
                                        
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
            if (action === 'unfollow' && !confirm('Are you sure you want to unfollow this actor?')) return;
            if (action === 'block' && !confirm('Are you sure you want to block this actor? They will be unable to interact with you.')) return;

            Statamic.$axios.post('/cp/activitypub/' + action, {
                id: targetId,
                sender: senderId
            }).then(response => {
                Statamic.$toast.success('Action ' + action + ' successful');
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

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const btn = document.getElementById('actor-search-btn');
            if (!btn) return;

            btn.addEventListener('click', () => {
                const handle = document.getElementById('actor-search-input').value;
                if (!handle) return;

                const resultsDiv = document.getElementById('search-results');
                resultsDiv.innerHTML = '<div class="loading loading-basic"><span class="icon icon-circular-graph animation-spin"></span> Searching...</div>';
                resultsDiv.classList.remove('hidden');

                fetch('{{ cp_route('activitypub.search') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({ handle })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        resultsDiv.innerHTML = `<div class="text-red-500">${data.error}</div>`;
                        return;
                    }

                    let html = `
                        <div class="flex items-center justify-between border p-3 rounded bg-gray-50">
                            <div class="flex items-center gap-3">
                                ${data.avatar ? `<img src="${data.avatar}" class="w-10 h-10 rounded-full object-cover">` : `<div class="w-10 h-10 rounded-full bg-gray-200 flex items-center justify-center text-gray-500 font-bold text-lg">${data.name.charAt(0)}</div>`}
                                <div>
                                <div class="font-bold text-lg">${data.name}</div>
                                <div class="text-sm text-gray-500 mb-1">${data.id}</div>
                                ${data.is_following ? 
                                    `<span class="text-green-600 flex items-center gap-1 text-sm"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg> Following</span>` 
                                    : (data.is_pending ? 
                                        `<span class="text-yellow-600 flex items-center gap-1 text-sm"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd" /></svg> Pending</span>` 
                                        : '')}
                            </div>
                            </div>
                            <div>
                                ${!data.is_following && !data.is_pending ? 
                                    `<button onclick="followActor('${data.id}', this)" class="btn">Follow</button>` 
                                    : ''}
                            </div>
                        </div>
                    `;
                    resultsDiv.innerHTML = html;
                })
                .catch(error => {
                    resultsDiv.innerHTML = `<div class="text-red-500">Error: ${error.message}</div>`;
                });
            });
        });

        function followActor(id, btn) {
            // Disable button and show spinner
            const originalContent = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="icon icon-circular-graph animation-spin w-4 h-4 mr-1"></span> Processing...';
            btn.classList.add('opacity-75', 'cursor-not-allowed');

            fetch('{{ cp_route('activitypub.follow') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ id })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Replace button with success message
                    const container = btn.parentElement;
                    container.innerHTML = `
                        <div class="flex flex-col items-center animate-pulse">
                            <span class="text-green-600 flex items-center gap-1 font-bold">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                                Request Sent!
                            </span>
                            <span class="text-xs text-gray-500 mt-1">Waiting for approval...</span>
                        </div>
                    `;
                    // Optional: could reload after a delay, but user might want to see the success state
                    setTimeout(() => location.reload(), 2000);
                } else {
                    alert('Error: ' + data.error);
                    // Reset button
                    btn.disabled = false;
                    btn.innerHTML = originalContent;
                    btn.classList.remove('opacity-75', 'cursor-not-allowed');
                }
            })
            .catch(error => {
                alert('Error: ' + error.message);
                btn.disabled = false;
                btn.innerHTML = originalContent;
                btn.classList.remove('opacity-75', 'cursor-not-allowed');
            });
        }
    </script>
@endsection
