@extends('statamic::layout')
@section('title', 'ActivityPub Settings')

@section('content')
    <div class="flex items-center justify-between mb-3">
        <h1>ActivityPub Settings</h1>
    </div>

    <form action="{{ cp_route('activitypub.settings.update') }}" method="POST">
        @csrf
        <div class="card p-0">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Collection</th>
                        <th>ActivityPub Type</th>
                        <th class="text-center">
                            Publish
                            <span class="ml-1 text-gray-500 cursor-help inline-block align-middle"
                                v-tooltip="'Allow items to be read by ActivityPub (JSON format)'">
                                @cp_svg('icons/regular/info-circle', 'w-4 h-4')
                            </span>
                        </th>
                        <th class="text-right">
                            Federate
                            <span class="ml-1 text-gray-500 cursor-help inline-block align-middle"
                                v-tooltip="'Send to followers (outbox) AND receive from people you follow (inbox)'">
                                @cp_svg('icons/regular/info-circle', 'w-4 h-4')
                            </span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($collections as $collection)
                        @php
                            $config = $settings[$collection->handle()] ?? [];
                            if (!is_array($config)) {
                                $config = [];
                            }
                            $enabled = $config['enabled'] ?? false;
                            $type = $config['type'] ?? 'Object';
                        @endphp
                        <tr>
                            <td>
                                <div class="flex items-center">
                                    <div class="w-4 h-4 mr-2">
                                        @if($collection->cascade('icon'))
                                            {!! $collection->cascade('icon') !!}
                                        @else
                                            @cp_svg('icons/light/content-writing')
                                        @endif
                                    </div>
                                    {{ $collection->title() }}
                                </div>
                            </td>
                            <td>
                                <div class="select-input-container">
                                    <select name="types[{{ $collection->handle() }}]" class="select-input">
                                        @foreach($types as $value => $label)
                                            <option value="{{ $value }}" {{ $type === $value ? 'selected' : '' }}>{{ $label }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </td>
                            <td class="text-right">
                                <div>
                                    <input type="hidden" name="collections[{{ $collection->handle() }}]" value="0">
                                    <input type="checkbox" name="collections[{{ $collection->handle() }}]" value="1" {{ $enabled ? 'checked' : '' }}>
                                </div>
                            </td>
                            <td class="text-center">
                                <div class="flex justify-center">
                                    <input type="hidden" name="federated[{{ $collection->handle() }}]" value="0">
                                    <input type="checkbox" name="federated[{{ $collection->handle() }}]" value="1" {{ ($config['federated'] ?? false) ? 'checked' : '' }}>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="card p-0">
            <h2 class="mt-4 mb-2 py-2 px-4">Taxonomies</h2>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Taxonomy</th>
                        <th>ActivityPub Type</th>
                        <th class="text-right">Publish</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($taxonomies as $taxonomy)
                        @php
                            $config = $settings[$taxonomy->handle()] ?? [];
                            if (!is_array($config)) {
                                $config = [];
                            }
                            $enabled = $config['enabled'] ?? false;
                            $type = $config['type'] ?? 'Object';
                        @endphp
                        <tr>
                            <td>
                                <div class="flex items-center">
                                    <div class="w-4 h-4 mr-2">
                                        @cp_svg('icons/light/tags')
                                    </div>
                                    {{ $taxonomy->title() }}
                                </div>
                            </td>
                            <td>
                                <div class="select-input-container">
                                    <select name="types[{{ $taxonomy->handle() }}]" class="select-input">
                                        @foreach($types as $value => $label)
                                            <option value="{{ $value }}" {{ $type === $value ? 'selected' : '' }}>{{ $label }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </td>
                            <td class="text-right">
                                <div>
                                    <input type="hidden" name="collections[{{ $taxonomy->handle() }}]" value="0">
                                    <input type="checkbox" name="collections[{{ $taxonomy->handle() }}]" value="1" {{ $enabled ? 'checked' : '' }}>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <!-- General Settings Section -->
        <div class="card p-0 mt-4">
            <h2 class="mt-4 mb-2 py-2 px-4">General Settings</h2>
            <p class="text-sm text-gray-600 mb-4 px-4">Configure general ActivityPub behavior.</p>

            <div class="px-4 py-6">
                <div class="ap-grid" style="align-items: center;">
                    <div>
                        <label>Allow Quotes</label>
                        <p>Allow others to quote boost your activities. When enabled, adds "canQuote" permission to outgoing posts.</p>
                    </div>
                    <div>
                        <input type="hidden" name="allow_quotes" value="0">
                        <input type="checkbox" name="allow_quotes" value="1"
                            {{ ($settings['allow_quotes'] ?? false) ? 'checked' : '' }}>
                    </div>
                </div>
            </div>
        </div>

        <div class="card p-0 mt-4">
            <h2 class="mt-4 mb-2 py-2 px-4">Inbox/Outbox</h2>
            <p class="text-sm text-gray-600 mb-4 px-4">Configure the batch size and frequency for scheduled processing.</p>

            <div class="px-4 py-6">
                <div class="ap-grid">
                    <div>
                        <label>Inbox Batch Size</label>
                        <p>Number of inbox items to process per scheduled run.</p>
                    </div>
                    <div>
                        <input type="number" name="inbox_batch_size" value="{{ $settings['inbox_batch_size'] ?? 50 }}"
                            class="input-text w-full max-w-xs" min="1">
                    </div>
                </div>
            </div>

            <div class="px-4 py-6">
                <div class="ap-grid">
                    <div>
                        <label>Outbox Batch Size</label>
                        <p>Number of outbox items to process per scheduled run.</p>
                    </div>
                    <div>
                        <input type="number" name="outbox_batch_size" value="{{ $settings['outbox_batch_size'] ?? 50 }}"
                            class="input-text w-full max-w-xs" min="1">
                    </div>
                </div>
            </div>

            <div class="px-4 py-6">
                <div class="ap-grid">
                    <div>
                        <label>Schedule Interval (minutes)</label>
                        <p>How often the scheduled process runs (default: 1 minute).</p>
                    </div>
                    <div>
                        <input type="number" name="schedule_interval" value="{{ $settings['schedule_interval'] ?? 1 }}"
                            class="input-text w-full max-w-xs" min="1" max="60">
                    </div>
                </div>
            </div>

            <div class="px-4 py-6">
                <div class="ap-grid" style="align-items: center;">
                    <div>
                        <label>Activity Logs</label>
                        <p>View detailed logs for inbox and outbox processing.</p>
                    </div>
                    <div>
                        <a href="{{ cp_route('activitypub.logs') }}" class="btn">
                            View Activity Logs
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="card p-0 mt-4">
            <h2 class="mt-4 mb-2 py-2 px-4">Maintenance</h2>
            <p class="text-sm text-gray-600 mb-4 px-4">Run daily maintenance and clean out old external data.</p>

            <div class="px-4 py-6">
                <div class="ap-grid">
                    <div>
                        <label>Maintenance Time</label>
                        <p>When to run the daily maintenance script (default: 02:00).</p>
                    </div>
                    <div>
                        <input type="time" name="maintenance_time" value="{{ $settings['maintenance_time'] ?? '02:00' }}"
                            class="input-text w-full max-w-xs">
                    </div>
                </div>
            </div>

            <div class="px-4 py-6">
                <div class="ap-grid">
                    <div>
                        <label>Clean Activities</label>
                        <p>How many days to keep external Activities (e.g. Create, Update, Like) before deleting them.</p>
                    </div>
                    <div>
                        <input type="number" name="retention_activities"
                            value="{{ $settings['retention_activities'] ?? 2 }}" class="input-text w-full max-w-xs">
                    </div>
                </div>
            </div>

            <div class="px-4 py-6">
                <div class="ap-grid">
                    <div>
                        <label>Clean Objects</label>
                        <p>How many days to keep other external entries (e.g. Notes, Articles) before deleting them.</p>
                    </div>
                    <div>
                        <input type="number" name="retention_entries" value="{{ $settings['retention_entries'] ?? 30 }}"
                            class="input-text w-full max-w-xs">
                    </div>
                </div>
            </div>
        </div>
        <div class="card p-0">
            <h2 class="mt-4 mb-2 py-2 px-4">Block List</h2>
            <p class="text-sm text-gray-600 mb-2 px-4">One domain per line. Subdomains are automatically blocked if the
                parent
                domain is listed. If you're unsure, <a
                    href="https://github.com/gardenfence/blocklist/blob/main/gardenfence.txt" target="_blank"
                    class="text-blue-500 hover:underline">GardenFence</a> has a good block list to begin with.</p>
            <div class="form-group">
                <textarea name="blocklist" class="input-text w-full h-64 font-mono text-sm"
                    placeholder="example.com">{{ $settings['blocklist'] ?? '' }}</textarea>
            </div>
        </div>

        <div class="flex justify-center mt-3">
            <button type="submit" class="btn-primary">Save Settings</button>
        </div>
    </form>
@endsection