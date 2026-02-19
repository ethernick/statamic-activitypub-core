<template>
    <inbox-stack :open="open" @closed="$emit('close')" title="Activity History" inset>
        <div v-if="loading" class="py-8 text-center text-gray-500">
            Loading history...
        </div>
        <div v-else-if="activities.length === 0" class="py-8 text-center text-gray-500">
            No related activities found.
        </div>
        <div v-else class="flex flex-col divide-y dark:divide-dark-800">
            <div v-for="act in activities" :key="act.id" class="p-4 hover:bg-gray-50 dark:hover:bg-dark-800 transition-colors">
                <div class="flex gap-3">
                    <div class="flex-shrink-0">
                        <img :src="act.actor.avatar || 'https://www.gravatar.com/avatar/?d=mp'" loading="lazy" class="w-10 h-10 rounded-full bg-gray-200 object-cover">
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-sm">{{ act.actor.name }}</span>
                                <span class="text-xs text-gray-500">{{ act.type }}</span>
                            </div>
                            <span class="text-xs text-gray-400">{{ act.date_human }}</span>
                        </div>
                        <div class="text-sm mt-1 text-gray-700 dark:text-gray-300" v-html="act.content"></div>

                        <div class="mt-2 flex items-center gap-3">
                            <button v-if="act.activitypub_json" class="flex items-center gap-1 text-xs text-gray-400 hover:text-blue-500 transition-colors" @click="$emit('view-json', act)" title="View JSON">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
                                </svg>
                            </button>
                            <a v-if="act.object" :href="typeof act.object === 'string' ? act.object : act.object.id" target="_blank" class="flex items-center gap-1 text-xs text-gray-400 hover:text-blue-500 transition-colors" title="View Source">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                </svg>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </inbox-stack>
</template>

<script>
import InboxStack from './InboxStack.vue';

export default {
    components: {
        InboxStack
    },
    props: {
        open: {
            type: Boolean,
            required: true
        },
        noteId: {
            type: [String, Number],
            default: null
        },
        apiUrl: {
            type: String,
            required: true
        }
    },
    data() {
        return {
            loading: false,
            activities: []
        };
    },
    watch: {
        open(val) {
            if (val && this.noteId) {
                this.fetchHistory();
            }
        },
        noteId(val) {
            if (this.open && val) {
                this.fetchHistory();
            }
        }
    },
    methods: {
        fetchHistory() {
            this.loading = true;
            this.activities = [];

            fetch(`${this.apiUrl}/history/${this.noteId}`)
                .then(res => res.json())
                .then(data => {
                    this.activities = data;
                })
                .finally(() => {
                    this.loading = false;
                });
        }
    }
}
</script>
