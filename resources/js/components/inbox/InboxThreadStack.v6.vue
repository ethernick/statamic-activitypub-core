<template>
    <inbox-stack :open="open" @closed="$emit('close')" title="Thread" inset>
        <div v-if="loading" class="py-8 text-center text-gray-500">
            Loading thread...
        </div>
        <div v-else-if="items.length === 0" class="py-8 text-center text-gray-500">
            Thread is empty.
        </div>
        <div v-else class="flex flex-col">
            <div v-for="(item, idx) in items" :key="item.id || idx"
                class="relative"
                :class="{'bg-blue-50 dark:bg-dark-800': item.is_focus}">

                <!-- Thread Connector Line -->
                <div v-if="item.depth > 0" class="absolute left-6 top-0 bottom-0 border-l-2 border-gray-200 dark:border-gray-700"
                     :style="{ 'left': (item.depth * 1.5) + 'rem' }"></div>

                <div class="p-4 border-b dark:border-dark-800" :style="{ 'margin-left': (item.depth * 1.5) + 'rem' }">
                    <inbox-note
                        :note="item"
                        :permissions="permissions"
                        @reply="$emit('reply', item)"
                        @boost="$emit('boost', item)"
                        @like="$emit('like', item)"
                        @history="$emit('history', item)"
                        @thread="$emit('thread', item)"
                        @json="$emit('json', item)"
                        @lightbox="$emit('lightbox', { attachments: $event.attachments, index: $event.index })"
                        @delete="$emit('delete', item)"
                        @edit="$emit('edit', item)"
                    >
                        <template #reply-editor>
                            <inbox-reply-form
                                v-if="activeReplyId === item.id"
                                :actors="actors"
                                :actor-id="replyForm.actor_id"
                                :content="replyForm.content"
                                :content-warning="replyForm.content_warning"
                                :loading="sendingReply"
                                @update:actorId="$emit('update:replyForm', { ...replyForm, actor_id: $event })"
                                @update:content="$emit('update:replyForm', { ...replyForm, content: $event })"
                                @update:contentWarning="$emit('update:replyForm', { ...replyForm, content_warning: $event })"
                                @cancel="$emit('update:activeReplyId', null)"
                                @submit="$emit('submit-reply', item)"
                            />
                        </template>
                    </inbox-note>
                </div>
            </div>
        </div>
    </inbox-stack>
</template>

<script>
import InboxStack from './InboxStack.vue';
import InboxNote from './InboxNote.vue';
import InboxReplyForm from './InboxReplyForm.v6.vue';

export default {
    components: {
        InboxStack,
        InboxNote,
        InboxReplyForm
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
        },
        activeReplyId: {
            type: [String, Number],
            default: null
        },
        replyForm: {
            type: Object,
            required: true
        },
        sendingReply: {
            type: Boolean,
            default: false
        },
        actors: {
            type: Array,
            default: () => []
        },
        permissions: {
            type: Object,
            default: () => ({ update: false, delete: false })
        }
    },
    emits: [
        'close',
        'update:activeReplyId',
        'update:replyForm',
        'submit-reply',
        'reply',
        'boost',
        'like',
        'history',
        'thread',
        'json',
        'lightbox',
        'delete',
        'edit'
    ],
    data() {
        return {
            loading: false,
            items: []
        };
    },
    watch: {
        open(val) {
            if (val && this.noteId) {
                this.fetchThread();
            }
        },
        noteId(val) {
            if (this.open && val) {
                this.fetchThread();
            }
        }
    },
    methods: {
        fetchThread() {
            this.loading = true;
            this.items = [];

            fetch(`${this.apiUrl}/thread/${this.noteId}`)
                .then(res => res.json())
                .then(data => {
                    this.items = data;
                })
                .finally(() => {
                    this.loading = false;
                });
        }
    }
}
</script>
