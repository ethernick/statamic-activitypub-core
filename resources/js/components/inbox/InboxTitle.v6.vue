<template>
    <header class="flex flex-wrap items-center justify-between gap-4 px-2 sm:px-0 py-6 max-md:pb-8 md:py-8">
        <h1 class="text-[25px] leading-[1.25] st-text-legibility font-medium antialiased flex items-center gap-2.5 md:flex-1">Inbox</h1>
        
        <inbox-filter-bar
            :current-filter="currentFilter"
            :can-create-note="canCreateNote"
            :show-new-dropdown="showNewDropdown"
            @filter-change="$emit('filter-change', $event)"
            @create-note="$emit('create-note')"
            @toggle-dropdown="$emit('toggle-dropdown')"
            @create-poll="$emit('create-poll')"
        />
    </header>
</template>

<script>
import InboxFilterBar from './InboxFilterBar.v6.vue';

export default {
    components: {
        InboxFilterBar
    },
    props: {
        currentFilter: {
            type: String,
            default: 'all'
        },
        canCreateNote: {
            type: Boolean,
            default: false
        },
        showNewDropdown: {
            type: Boolean,
            default: false
        }
    }
}
</script>
