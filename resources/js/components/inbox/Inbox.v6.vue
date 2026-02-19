<template>
    <div class="max-w-5xl mx-auto">
        <inbox-title
            :current-filter="filter"
            :can-create-note="!!createNoteUrl"
            :show-new-dropdown="showNewDropdown"
            @filter-change="setFilter"
            @create-note="createNote"
            @toggle-dropdown="toggleNewDropdown"
            @create-poll="createPollAndClose"
        />

        <div class="@container/panel relative bg-gray-150 dark:bg-gray-950/35 dark:inset-shadow-2xs dark:inset-shadow-black w-full rounded-2xl mb-8 max-[600px]:p-1.25 p-1.75 [&:has(>[data-ui-panel-header])]:pt-0 focus-none starting-style-transition mb-6">
        <div class="h-auto visible transition-[height,visibility] duration-[250ms,2s]">
        <div class="bg-white dark:bg-gray-850 rounded-xl ring ring-gray-200 dark:ring-x-0 dark:ring-b-0 dark:ring-gray-700/80 shadow-ui-md px-4 sm:px-4.5 py-5 space-y-2">
        <inbox-feed
            :notes="notes"
            :loading="loading"
            :pagination="{ page, lastPage, total, perPage }"
            :permissions="{ update: !!updateNoteUrl, delete: !!deleteUrl }"
            :actors="localActors"
            :active-reply-id="activeReplyId"
            :reply-form="replyForm"
            :sending-reply="sendingReply"
            @page-change="changePage"
            @per-page-change="changePerPage"
            @update:activeReplyId="activeReplyId = $event"
            @update:replyForm="updateReplyForm"
            @submit-reply="submitReply"
            @reply="toggleReply"
            @boost="toggleBoost"
            @quote="openQuoteModal"
            @like="toggleLike"
            @history="viewHistory"
            @thread="viewThread"
            @json="viewJson"
            @lightbox="params => openLightbox(params.attachments, params.index)"
            @delete="deleteItem"
            @edit="editNote"
            @vote="handleVote"
        />
        </div></div></div>
        
        <!-- Lightbox -->
        <div v-if="lightbox.open" class="fixed inset-0 z-[9999] bg-black/90 flex flex-col items-center justify-center" @click.self="closeLightbox">
            <button class="absolute top-4 right-4 text-white text-3xl" @click="closeLightbox">&times;</button>
             <button class="absolute left-8 text-white text-3xl p-4 bg-white/10 rounded-full hover:bg-white/20" @click="lightboxNav(-1)">&lsaquo;</button>
             <button class="absolute right-8 text-white text-3xl p-4 bg-white/10 rounded-full hover:bg-white/20" @click="lightboxNav(1)">&rsaquo;</button>
            <img :src="lightbox.images[lightbox.index].url" class="max-w-[90vw] max-h-[85vh] object-contain">
            <div class="text-white mt-4">{{ lightbox.images[lightbox.index].description }}</div>
        </div>

        <!-- JSON Drawer Stack -->
        <inbox-json-stack
            :open="jsonModal.open"
            :content="jsonModal.content"
            @close="closeJsonModal"
        />

        <!-- Create/Edit Note Stack -->
        <inbox-note-form
            :open="isCreatingNote"
            :form="newNote"
            :actors="localActors"
            :is-editing="!!editingNoteId"
            :loading="creating"
            :preview-url="markdownPreviewUrl"
            @close="closeNoteModal"
            @submit="submitNote"
        />

        <!-- Create Poll Stack -->
        <inbox-poll-form
            :open="isCreatingPoll"
            :form="newPoll"
            :actors="localActors"
            :loading="creating"
            @close="closePollModal"
            @submit="submitPoll"
        />

        <!-- Quote Stack -->
        <inbox-quote-form
            :open="isCreatingQuote"
            :form="newQuote"
            :actors="localActors"
            :quoted-note="quotedNote"
            :loading="creating"
            @close="closeQuoteModal"
            @submit="submitQuote"
        />

        <!-- History Stack -->
        <inbox-history-stack
            :open="isViewingHistory"
            :note-id="historyNote ? historyNote.id : null"
            :api-url="apiUrl"
            @close="closeHistoryModal"
            @view-json="viewJson"
        />

        <!-- Thread Drawer Stack -->
        <inbox-thread-stack
            :open="isViewingThread"
            :note-id="threadNote ? threadNote.id : null"
            :api-url="apiUrl"
            :active-reply-id="activeReplyId"
            :reply-form="replyForm"
            :sending-reply="sendingReply"
            :actors="localActors"
            :permissions="{ update: !!updateNoteUrl, delete: !!deleteUrl }"
            @close="closeThreadModal"
            @update:activeReplyId="activeReplyId = $event"
            @update:replyForm="updateReplyForm"
            @submit-reply="submitReply"
            @reply="toggleReply"
            @boost="toggleBoost"
            @like="toggleLike"
            @history="viewHistory"
            @thread="viewThread"
            @json="viewJson"
            @lightbox="params => openLightbox(params.attachments, params.index)"
            @delete="deleteItem"
            @edit="editNote"
        />
    </div>
</template>

<style>


/* Button styles */
.btn {
    display: inline-flex;
    align-items: center;
    font-size: 0.875rem;
    font-weight: 500;
    line-height: 1.25rem;
    padding: 0.5rem 1rem;
    height: 2.375rem;
    border-radius: 0.25rem;
    border: 1px solid #D3DDE7;
    background: linear-gradient(180deg, #fff, #f9fafb);
    background-clip: padding-box;
    color: #1f2937;
    cursor: pointer;
    outline: none;
}
.btn:hover:not(:disabled) {
    background: linear-gradient(180deg, #f3f4f6, #eef2ff);
}
.btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
.btn-sm {
    padding: 0.25rem 0.5rem;
    height: 2rem;
}
.btn-primary {
    color: #fff;
    background: linear-gradient(to bottom, #3b82f6, #2563eb);
    background-clip: padding-box;
    border: 1px solid #1d4ed8;
    border-bottom-color: #1e40af;
    box-shadow: inset 0 1px 0 0 #60a5fa, 0 1px 0 0 rgba(25,30,35,.05), 0 3px 2px -1px rgba(30,58,138,.15);
}
.btn-primary:hover:not(:disabled) {
    background: linear-gradient(to bottom, #2563eb, #1d4ed8);
}
.btn-primary:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

/* Button dark mode */
html.dark .btn,
html.is-dark .btn,
html.isdark .btn {
    background: linear-gradient(180deg, #2d2d2d, #262626);
    border-color: #444;
    color: #e5e5e5;
}
html.dark .btn:hover:not(:disabled),
html.is-dark .btn:hover:not(:disabled),
html.isdark .btn:hover:not(:disabled) {
    background: linear-gradient(180deg, #3a3a3a, #333);
}
html.dark .btn-primary,
html.is-dark .btn-primary,
html.isdark .btn-primary {
    background: linear-gradient(to bottom, #2563eb, #1d4ed8);
    border-color: #1e40af;
    border-bottom-color: #1e3a8a;
    color: #fff;
}
html.dark .btn-primary:hover:not(:disabled),
html.is-dark .btn-primary:hover:not(:disabled),
html.isdark .btn-primary:hover:not(:disabled) {
    background: linear-gradient(to bottom, #1d4ed8, #1e40af);
}
</style>

<script>
import InboxNote from './InboxNote.vue';
import InboxStack from './InboxStack.vue';
import InboxJsonStack from './InboxJsonStack.v6.vue';
import InboxHistoryStack from './InboxHistoryStack.vue';
import InboxThreadStack from './InboxThreadStack.v6.vue';
import InboxTitle from './InboxTitle.v6.vue';
import InboxFeed from './InboxFeed.v6.vue';
import InboxNoteForm from './InboxNoteForm.v6.vue';
import InboxPollForm from './InboxPollForm.v6.vue';
import InboxQuoteForm from './InboxQuoteForm.v6.vue';
import InboxReplyForm from './InboxReplyForm.v6.vue';

export default {
    components: {
        InboxNote,
        InboxStack,
        InboxJsonStack,
        InboxHistoryStack,
        InboxThreadStack,
        InboxTitle,
        InboxFeed,
        InboxNoteForm,
        InboxPollForm,
        InboxQuoteForm,
        InboxReplyForm
    },
    props: {
        initialActors: {
            type: Array,
            default: () => []
        },
        apiUrl: {
            type: String,
            required: true
        },
        replyUrl: {
            type: String,
            required: true
        },
        likeUrl: {
            type: String,
            required: true
        },
        unlikeUrl: {
            type: String,
            required: true
        },
        announceUrl: {
            type: String,
            required: true
        },
        undoAnnounceUrl: {
            type: String,
            required: true
        },
        createNoteUrl: {
            type: String,
            default: null
        },
        storeNoteUrl: {
            type: String,
            default: null
        },
        storePollUrl: {
            type: String,
            default: null
        },
        deleteUrl: {
            type: String,
            default: null
        },
        updateNoteUrl: {
            type: String,
            default: null
        },
        linkPreviewUrl: {
            type: String,
            default: null
        },
        markdownPreviewUrl: {
            type: String,
            default: null
        },
        batchLinkPreviewUrl: {
            type: String,
            default: null
        }
    },
    data() {
        return {
            notes: [],
            page: 1,
            lastPage: 1,
            total: 0,
            perPage: parseInt(localStorage.getItem('activitypub_per_page')) || 25,
            loading: false,
            filter: 'all',
            error: null,
            localActors: this.initialActors,
            activeReplyId: null,
            replyForm: {
                content: '',
                content_warning: '',
                actor_id: this.initialActors[0]?.id || null
            },
            sendingReply: false,
            lightbox: {
                open: false,
                images: [],
                index: 0
            },
            jsonModal: {
                open: false,
                content: ''
            },
            isCreatingNote: false,
            newNote: {
                content: '',
                content_warning: '',
                actor: null
            },
            isCreatingPoll: false,
            newPoll: {
                actor: null,
                content: '',
                multiple_choice: false,
                options: ['', '']
            },
            isCreatingQuote: false,
            quotedNote: null,
            newQuote: {
                actor: null,
                content: '',
                content_warning: '',
                quote_of: null,
            },
            showNewDropdown: false,
            creating: false,
            editingNoteId: null,
            isViewingHistory: false,
            historyNote: null,

            isViewingThread: false,
            threadNote: null
        }
    },
    mounted() {
        document.title = 'Inbox ‹ ActivityPub ‹ Statamic';
        this.loadNotes();
    },
    methods: {
        updateReplyForm(newVal) {
            this.replyForm = newVal;
        },
        loadNotes() {
            if (this.loading) return;
            
            this.loading = true;
            this.error = null;
            
            this.$axios.get(this.apiUrl, {
                params: {
                    page: this.page,
                    per_page: this.perPage,
                    filter: this.filter
                }
            })
            .then(response => {
                const data = response.data;
                const newNotes = data.data.map(note => ({
                    ...note,
                    showContent: !note.sensitive
                })).sort((a, b) => {
                    // Sort by Date DESC, then ID DESC
                    const dateA = new Date(a.date).getTime();
                    const dateB = new Date(b.date).getTime();
                    if (dateA !== dateB) return dateB - dateA;
                    
                    // Fallback to ID comparison (assuming string IDs)
                    if (a.id < b.id) return 1;
                    if (a.id > b.id) return -1;
                    return 0;
                });
                
                this.notes = newNotes;
                this.total = data.meta.total;
                this.lastPage = data.meta.last_page;
                
                // Batch Enrichment (Link Previews + OEmbed)
                const needsEnrichmentIds = newNotes
                    .filter(n => n.needs_preview && !n.link_preview && !n.oembed)
                    .map(n => n.id);

                if (needsEnrichmentIds.length > 0 && this.batchLinkPreviewUrl) {
                    this.fetchBatchPreviews(needsEnrichmentIds);
                }
            })
            .catch(err => {
                this.error = err.response && err.response.data.message ? err.response.data.message : (err.message || 'Unknown error');
            })
            .finally(() => {
                this.loading = false;
            });
        },
        fetchBatchPreviews(ids) {
            this.$axios.post(this.batchLinkPreviewUrl, { note_ids: ids })
            .then(response => {
                 const data = response.data;
                 this.notes = this.notes.map(note => {
                     if (data[note.id]) {
                         return { ...note, link_preview: data[note.id] };
                     }
                     return note;
                 });
            })
            .catch(console.error);
        },
        changePage(p) {
            if (p < 1 || p > this.lastPage) return;
            this.page = p;
            this.loadNotes();
            this.$nextTick(() => window.scrollTo({ top: 0, behavior: 'smooth' }));
        },
        changePerPage(val) {
            this.perPage = val;
            this.page = 1;
            localStorage.setItem('activitypub_per_page', val);
            this.loadNotes();
            this.$nextTick(() => window.scrollTo({ top: 0, behavior: 'smooth' }));
        },
        setFilter(f) {
            this.filter = f;
            this.page = 1;
            this.loadNotes();
        },
        retry() {
            this.loadNotes();
        },
        toggleNewDropdown() {
            this.showNewDropdown = !this.showNewDropdown;
        },
        createNote() {
            this.isCreatingNote = true;
            this.editingNoteId = null;
            this.newNote = {
                content: '',
                content_warning: '',
                actor: this.localActors[0]?.id || null
            };
        },
        editNote(note) {
            this.isCreatingNote = true;
            this.editingNoteId = note.id;
            this.newNote = {
                content: note.content_raw || note.content,
                content_warning: note.summary || '',
                actor: note.actor.id
            };
        },
        closeNoteModal() {
            this.isCreatingNote = false;
            this.editingNoteId = null;
        },
        submitNote() {
            if (this.creating) return;

            const url = this.editingNoteId ? this.updateNoteUrl.replace('ID', this.editingNoteId) : this.storeNoteUrl;
            const method = this.editingNoteId ? 'PUT' : 'POST';

            if (!this.newNote.content.trim()) return;

            this.creating = true;
            this.creating = true;
            this.$axios[method.toLowerCase()](url, this.newNote)
            .then(() => {
                this.closeNoteModal();
                this.loadNotes();
            })
            .catch(err => {
                const message = err.response && err.response.data.message ? err.response.data.message : err.message;
                alert(message);
            })
            .finally(() => {
                this.creating = false;
            });
        },
        createPollAndClose() {
             this.showNewDropdown = false;
             this.isCreatingPoll = true;
             this.newPoll = {
                actor: this.localActors[0]?.id || null,
                content: '',
                multiple_choice: false,
                options: ['', '']
            };
        },
        closePollModal() {
            this.isCreatingPoll = false;
        },
        addOption() {
            this.newPoll.options.push('');
        },
        removeOption(idx) {
            this.newPoll.options.splice(idx, 1);
        },
        submitPoll() {
            if (this.creating) return;
            if (!this.newPoll.content.trim()) return;
            const opts = this.newPoll.options.filter(o => o.trim());
            if (opts.length < 2 && opts.length > 0) {
                 alert('A poll needs at least 2 options, or none for open-ended.');
                 return;
            }

            this.creating = true;
            this.creating = true;
            this.$axios.post(this.storePollUrl, {
                actor: this.newPoll.actor,
                content: this.newPoll.content,
                options: opts,
                multiple_choice: this.newPoll.multiple_choice
            })
            .then(() => {
                this.closePollModal();
                this.loadNotes();
            })
            .catch(e => {
                const message = e.response && e.response.data.message ? e.response.data.message : e.message;
                alert(message);
            })
            .finally(() => this.creating = false);
        },
        
        // Reply Logic
        toggleReply(note) {
            if (this.activeReplyId === note.id) {
                this.activeReplyId = null;
            } else {
                this.activeReplyId = note.id;
                this.replyForm = {
                    content: '',
                    content_warning: '',
                    actor_id: this.localActors[0]?.id || null
                };
            }
        },
        submitReply(note) {
            if (this.sendingReply) return;
            if (!this.replyForm.content.trim()) return;

            this.sendingReply = true;
            this.sendingReply = true;
            this.$axios.post(this.replyUrl, {
                in_reply_to: note.id,
                content: this.replyForm.content,
                content_warning: this.replyForm.content_warning,
                actor: this.replyForm.actor_id
            })
            .then(() => {
                this.activeReplyId = null;
                this.loadNotes();
            })
            .catch(e => {
                const message = e.response && e.response.data.message ? e.response.data.message : e.message;
                alert(message);
            })
            .finally(() => this.sendingReply = false);
        },

        // Quote
        openQuoteModal(note) {
            this.quotedNote = note;
            this.isCreatingQuote = true;
            this.newQuote = {
                actor: this.localActors[0]?.id || null,
                content: '',
                content_warning: '',
                quote_of: note.id
            };
        },
        closeQuoteModal() {
            this.isCreatingQuote = false;
            this.quotedNote = null;
        },
        submitQuote() {
            if (this.creating) return;
            this.creating = true;
            this.creating = true;
            this.$axios.post(this.storeNoteUrl, this.newQuote)
            .then(() => {
                this.closeQuoteModal();
                this.loadNotes();
            })
            .catch(e => {
                const message = e.response && e.response.data.message ? e.response.data.message : e.message;
                alert(message);
            })
            .finally(() => this.creating = false);
        },

        // Boost
        toggleBoost(note) {
            const wasBoosted = note.boosted_by_user;
             const url = wasBoosted ? this.undoAnnounceUrl : this.announceUrl;
             this.$axios.post(url, {
                object: note.id,
                actor: this.localActors[0].id
             })
             .then(response => {
                 const data = response.data;
                 note.boosted_by_user = !wasBoosted;
                 if (data.counts) note.counts = data.counts;
             });
        },

        // Like 
        toggleLike(note) {

            const wasLiked = note.liked_by_user;
            const url = wasLiked ? this.unlikeUrl : this.likeUrl;
            
            this.$axios.post(url, {
                object: note.id,
                actor: this.localActors[0].id 
            })
            .then(response => {
                const data = response.data;
                note.liked_by_user = !wasLiked;
                if (data.counts) note.counts = data.counts;
                else {
                    note.counts.likes = (note.counts.likes || 0) + (wasLiked ? -1 : 1);
                }
            });
        },

        // Vote
        handleVote(payload) {

             const { note, option, callback } = payload;
             this.$axios.post(this.storeNoteUrl + '/vote', {
                 poll: note.id,
                 choices: [option.name],
                 actor: this.localActors[0].id
             })
             .then(response => {
                 callback(true);
             })
             .catch(() => callback(false));
        },

        // Delete
        deleteItem(note) {
            if (!confirm('Are you sure you want to delete this note?')) return;
            
            this.$axios.delete(this.deleteUrl, { 
                data: { id: note.id } 
            })
            .then(() => {
                this.notes = this.notes.filter(n => n.id !== note.id);
            });
        },

        // Lightbox
        openLightbox(images, index) {
            this.lightbox.images = images;
            this.lightbox.index = index;
            this.lightbox.open = true;
        },
        closeLightbox() {
            this.lightbox.open = false;
        },
        lightboxNav(dir) {
            let newIndex = this.lightbox.index + dir;
            if (newIndex < 0) newIndex = this.lightbox.images.length - 1;
            if (newIndex >= this.lightbox.images.length) newIndex = 0;
            this.lightbox.index = newIndex;
        },

        // JSON
        viewJson(note) {
            this.jsonModal.content = note.activitypub_json || note;
            this.jsonModal.open = true;
        },
        closeJsonModal() {
            this.jsonModal.open = false;
        },


        // History
        viewHistory(note) {
            this.isViewingHistory = true;
            this.historyNote = note;
        },
        closeHistoryModal() {
             this.isViewingHistory = false;
             this.historyNote = null;
        },

        // Thread
        viewThread(note) {
            this.isViewingThread = true;
            this.threadNote = note;
        },
        closeThreadModal() {
            this.isViewingThread = false;
            this.isViewingThread = false;
            this.threadNote = null;
        }
    }
}
</script>
