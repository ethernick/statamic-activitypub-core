<template>
    <div class="card p-0">
        <div class="flex flex-col">
            <!-- Empty State -->
            <div v-if="!loading && notes.length === 0" class="py-8 text-center text-gray-400">
                Inbox is empty.
            </div>

            <!-- Feed -->
            <div class="flex flex-col divide-y">
                <inbox-note
                    v-for="note in notes" 
                    :key="note.id"
                    :note="note"
                    :permissions="permissions"
                    @reply="$emit('reply', $event)"
                    @boost="$emit('boost', $event)"
                    @quote="$emit('quote', $event)"
                    @like="$emit('like', $event)"
                    @history="$emit('history', $event)"
                    @thread="$emit('thread', $event)"
                    @json="$emit('json', $event)"
                    @lightbox="$emit('lightbox', $event)"
                    @delete="$emit('delete', $event)"
                    @edit="$emit('edit', $event)"
                    @vote="$emit('vote', $event)"
                >
                    <template #reply-editor>
                        <inbox-reply-form
                            v-if="activeReplyId === note.id"
                            :actors="actors"
                            :actor-id="replyForm.actor_id"
                            :content="replyForm.content"
                            :content-warning="replyForm.content_warning"
                            :loading="sendingReply"
                            @update:actorId="$emit('update:replyForm', { ...replyForm, actor_id: $event })"
                            @update:content="$emit('update:replyForm', { ...replyForm, content: $event })"
                            @update:contentWarning="$emit('update:replyForm', { ...replyForm, content_warning: $event })"
                            @cancel="$emit('update:activeReplyId', null)"
                            @submit="$emit('submit-reply', note)"
                        />
                    </template>
                </inbox-note>
            </div>

            <!-- Pagination Controls -->
            <div v-if="pagination.total > 0" class="py-6 px-4 sm:px-6 flex items-center justify-between">
                <div class="text-sm text-gray-500">
                    Showing {{ (pagination.page - 1) * pagination.perPage + 1 }} to {{ Math.min(pagination.page * pagination.perPage, pagination.total) }} of {{ pagination.total }} entries
                </div>
                <div class="flex gap-1 items-center">
                    <select :value="pagination.perPage" @change="$emit('per-page-change', parseInt($event.target.value))" class="btn btn-sm pr-8 mr-2">
                        <option :value="25">25 / page</option>
                        <option :value="50">50 / page</option>
                        <option :value="100">100 / page</option>
                    </select>
                    <!-- Prev -->
                    <button class="btn btn-sm" :disabled="pagination.page <= 1 || loading" @click="$emit('page-change', pagination.page - 1)">&larr;</button>
                    
                    <!-- Page Numbers -->
                    <template v-for="(p, i) in paginationRange">
                        <span v-if="p === '...'" :key="'dots'+i" class="px-2 py-1 text-gray-400">...</span>
                        <button 
                            v-else 
                            :key="p" 
                            @click="$emit('page-change', p)"
                            class="btn btn-sm"
                            :class="{ 'bg-blue-600 border-blue-600 font-bold': p === pagination.page }"
                            :disabled="loading"
                        >
                            {{ p }}
                        </button>
                    </template>

                    <!-- Next -->
                    <button class="btn btn-sm" :disabled="pagination.page >= pagination.lastPage || loading" @click="$emit('page-change', pagination.page + 1)">&rarr;</button>
                </div>
            </div>

            <!-- Loading State overlay or placeholder -->
            <div v-if="loading" class="py-12 text-center text-gray-500">
                <svg class="animate-spin h-8 w-8 mx-auto text-gray-400 mb-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span>Loading...</span>
            </div>
        </div>
    </div>
</template>

<script>
import InboxNote from './InboxNote.vue';
import InboxReplyForm from './InboxReplyForm.vue';

export default {
    components: {
        InboxNote,
        InboxReplyForm
    },
    props: {
        notes: {
            type: Array,
            required: true
        },
        loading: {
            type: Boolean,
            default: false
        },
        pagination: {
            type: Object,
            required: true
        },
        permissions: {
            type: Object,
            default: () => ({ update: false, delete: false })
        },
        actors: {
            type: Array,
            default: () => []
        },
        activeReplyId: {
            type: [String, Number],
            default: null
        },
        replyForm: {
            type: Object,
            default: () => ({
                actor_id: null,
                content: '',
                content_warning: ''
            })
        },
        sendingReply: {
            type: Boolean,
            default: false
        }
    },
    computed: {
        paginationRange() {
            const range = [];
            const delta = 2; // Number of pages around current page
            const left = this.pagination.page - delta;
            const right = this.pagination.page + delta + 1;
            
            for (let i = 1; i <= this.pagination.lastPage; i++) {
                if (i === 1 || i === this.pagination.lastPage || (i >= left && i < right)) {
                    range.push(i);
                }
            }
            
            // Add dots
            const withDots = [];
            let l;
            for (let i of range) {
                if (l) {
                    if (i - l === 2) {
                        withDots.push(l + 1);
                    } else if (i - l !== 1) {
                        withDots.push('...');
                    }
                }
                withDots.push(i);
                l = i;
            }
            return withDots;
        }
    }
}
</script>
