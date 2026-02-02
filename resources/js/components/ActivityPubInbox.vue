<template>
    <div class="max-w-3xl mx-auto">
        <div class="flex items-center justify-between mb-3">
            <h1>Inbox</h1>
            
            <div class="flex rounded-lg p-1 gap-1 border ap-filter-container items-center">
                <button 
                    v-for="f in ['all', 'activities', 'mentions']" 
                    :key="f"
                    @click="setFilter(f)"
                    class="px-3 py-1 rounded-md text-sm font-medium transition-colors capitalize ap-filter-btn"
                    :class="filter === f ? 'active' : 'inactive'"
                >
                    {{ f }}
                </button>
                <div class="btn-group relative flex items-center ml-2" v-if="createNoteUrl">
                    <button type="button" @click="createNote" class="btn-primary !rounded-r-none pr-3 focus:z-10">
                        New Note
                    </button>
                    <button type="button" @click="toggleNewDropdown" class="btn-primary !rounded-l-none px-2 border-l border-white/20 -ml-px flex items-center h-full focus:z-10">
                        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                        </svg>
                    </button>
                    <div v-if="showNewDropdown" class="absolute right-0 bg-white dark:bg-dark-550 border border-gray-100 dark:border-dark-900 shadow-popover rounded-md z-50 py-1" style="top: 2.75em; width: 100%; text-align: left;">
                        <a href="#" @click.prevent="createPollAndClose" class="block px-4 py-2 text-sm hover:bg-gray-100 dark:hover:bg-dark-600">New Poll</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="card p-0">
            <div class="flex flex-col">
                <!-- Errors -->
                <div v-if="error" class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <strong class="font-bold">Error!</strong>
                    <span class="block sm:inline">{{ error }}</span>
                    <button class="underline ml-2" @click="retry">Retry</button>
                </div>

                <!-- Empty State -->
                <div v-if="!loading && notes.length === 0" class="py-8 text-center text-gray-400">
                    Inbox is empty.
                </div>

                <!-- Feed -->
                <div class="flex flex-col divide-y">
                    <activity-pub-note
                        v-for="note in notes" 
                        :key="note.id"
                        :note="note"
                        :permissions="{ update: !!updateNoteUrl, delete: !!deleteUrl }"
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
                    >
                        <template #reply-editor>
                            <!-- Reply Editor -->
                            <div v-if="activeReplyId === note.id" class="mt-4 p-4 rounded-lg border shadow-sm animate-fade-in-down ap-reply-card">
                                <div class="flex flex-col gap-3">
                                        <div class="flex items-center gap-2">
                                        <label class="text-xs text-gray-500 uppercase font-bold">Reply As:</label>
                                        <select v-model="replyActorId" class="text-sm rounded-md shadow-sm ap-reply-input">
                                            <option v-for="actor in localActors" :key="actor.id" :value="actor.id">{{ actor.name }} ({{ actor.handle }})</option>
                                        </select>
                                        </div>
                                    <div class="mb-3">
                                        <input type="text" v-model="replyContentWarning" placeholder="Content Warning (optional)" class="input-text w-full text-sm">
                                    </div>
                                        <div class="min-h-[150px]">
                                        <markdown-fieldtype
                                            :value="replyContent"
                                            :config="{
                                                cheatsheet: true,
                                                container: 'assets',
                                                buttons: ['bold', 'italic', 'unorderedlist', 'orderedlist', 'quote', 'link', 'image', 'table'],
                                                type: 'markdown'
                                            }"
                                            :meta="{
                                                previewUrl: markdownPreviewUrl
                                            }"
                                            @input="val => replyContent = val"
                                        />
                                        </div>
                                        <div class="flex justify-end gap-2 mt-3">
                                            <button type="button" @click="activeReplyId = null" class="btn">Cancel</button>
                                            <button type="button" @click="submitReply(note)" class="btn-primary" :disabled="sendingReply">
                                                {{ sendingReply ? 'Sending...' : 'Reply' }}
                                            </button>
                                        </div>
                                </div>
                            </div>
                        </template>
                    </activity-pub-note>
                </div>

                <!-- Loading / End States -->
                <!-- Pagination Controls -->
                 <div v-if="total > 0" class="py-6 px-4 sm:px-6 flex items-center justify-between">
                    <div class="text-sm text-gray-500">
                        Showing {{ (page - 1) * perPage + 1 }} to {{ Math.min(page * perPage, total) }} of {{ total }} entries
                    </div>
                    <div class="flex gap-1 items-center">
                        <select v-model="perPage" @change="changePerPage" class="btn btn-sm pr-8 mr-2">
                            <option :value="25">25 / page</option>
                            <option :value="50">50 / page</option>
                            <option :value="100">100 / page</option>
                        </select>
                        <!-- Prev -->
                        <button class="btn btn-sm" :disabled="page <= 1 || loading" @click="changePage(page - 1)">&larr;</button>
                        
                        <!-- Page Numbers -->
                        <template v-for="(p, i) in paginationRange">
                            <span v-if="p === '...'" :key="'dots'+i" class="px-2 py-1 text-gray-400">...</span>
                            <button 
                                v-else 
                                :key="p" 
                                @click="changePage(p)"
                                class="btn btn-sm"
                                :class="{ 'bg-blue-600 border-blue-600 font-bold': p === page }"
                                :disabled="loading"
                            >
                                {{ p }}
                            </button>
                        </template>

                        <!-- Next -->
                        <button class="btn btn-sm" :disabled="page >= lastPage || loading" @click="changePage(page + 1)">&rarr;</button>
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
        
        <!-- Lightbox -->
        <div v-if="lightbox.open" class="fixed inset-0 z-[9999] bg-black/90 flex flex-col items-center justify-center" @click.self="closeLightbox">
            <button class="absolute top-4 right-4 text-white text-3xl" @click="closeLightbox">&times;</button>
             <button class="absolute left-8 text-white text-3xl p-4 bg-white/10 rounded-full hover:bg-white/20" @click="lightboxNav(-1)">&lsaquo;</button>
             <button class="absolute right-8 text-white text-3xl p-4 bg-white/10 rounded-full hover:bg-white/20" @click="lightboxNav(1)">&rsaquo;</button>
            <img :src="lightbox.images[lightbox.index].url" class="max-w-[90vw] max-h-[85vh] object-contain">
            <div class="text-white mt-4">{{ lightbox.images[lightbox.index].description }}</div>
        </div>

        <!-- JSON Drawer Stack -->
        <stack name="json-stack" v-if="jsonModal.open" @closed="closeJsonModal">
            <div class="h-full w-full bg-white dark:bg-dark-900 flex flex-col">
                <div class="p-4 border-b dark:border-dark-900 flex justify-between items-center bg-gray-100 dark:bg-dark-800">
                    <h2 class="text-lg font-medium">ActivityPub JSON</h2>
                    <button @click="closeJsonModal" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">&times;</button>
                </div>
                <div class="p-0 overflow-auto flex-1 bg-gray-50 dark:bg-dark-900">
                     <vue-json-pretty 
                        :data="jsonModal.content"
                        :deep="4"
                        :show-double-quotes="true"
                        :show-line="true"
                        :show-length="true"
                        :highlight-mouseover-node="true">
                     </vue-json-pretty>
                </div>
                <div class="p-4 border-t dark:border-dark-900 bg-gray-100 dark:bg-dark-800 flex justify-end">
                     <button @click="closeJsonModal" class="btn">Close</button>
                </div>
            </div>
        </stack>

        <!-- Create/Edit Note Stack -->
        <stack name="create-note-stack" v-if="isCreatingNote" @closed="closeNoteModal">
            <div class="h-full w-full bg-white dark:bg-dark-900 flex flex-col">
                
                <div class="p-4 border-b dark:border-dark-900 flex justify-between items-center bg-gray-100 dark:bg-dark-800">
                    <h2 class="text-lg font-medium">{{ editingNoteId ? 'Edit Note' : 'Create Note' }}</h2>
                    <button @click="closeNoteModal" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">&times;</button>
                </div>

                <div class="p-6 flex-1 overflow-y-auto">
                    <div class="mb-5">
                        <label class="block text-sm font-bold mb-2">Post As</label>
                        <select v-model="newNote.actor" class="input-text w-full" :disabled="!!editingNoteId">
                            <option v-for="actor in localActors" :key="actor.id" :value="actor.id">{{ actor.name }} ({{ actor.handle }})</option>
                        </select>
                    </div>

                    <div class="mb-5 h-full flex flex-col">
                        <label class="block text-sm font-bold mb-2">Content</label>
                        <div class="mb-3">
                            <input type="text" v-model="newNote.content_warning" placeholder="Content Warning (optional)" class="input-text w-full">
                        </div>
                        <div class="flex-1 min-h-[200px]">
                            <markdown-fieldtype
                                :value="newNote.content"
                                :config="{
                                    cheatsheet: true,
                                    container: 'assets',
                                    cheatsheet: true,
                                    container: 'assets',
                                    buttons: ['bold', 'italic', 'unorderedlist', 'orderedlist', 'quote', 'link', 'image', 'table'],
                                    type: 'markdown'
                                }"
                                :meta="{
                                    previewUrl: markdownPreviewUrl
                                }"
                                @input="val => newNote.content = val"
                            />
                        </div>
                    </div>
                </div>

                <div class="p-4 border-t dark:border-dark-900 bg-gray-100 dark:bg-dark-800 flex justify-end gap-3">
                    <button class="btn" @click="closeNoteModal">Cancel</button>
                    <button class="btn-primary" @click="submitNote" :disabled="creating">
                        {{ creating ? 'Saving...' : (editingNoteId ? 'Update' : 'Create') }}
                    </button>
                </div>

            </div>

        </stack>

        <!-- Create Poll Stack -->
        <stack name="create-poll-stack" v-if="isCreatingPoll" @closed="closePollModal">
            <div class="h-full w-full bg-white dark:bg-dark-900 flex flex-col">
                <div class="p-4 border-b dark:border-dark-900 flex justify-between items-center bg-gray-100 dark:bg-dark-800">
                    <h2 class="text-lg font-medium">Create Poll</h2>
                    <button @click="closePollModal" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">&times;</button>
                </div>

                <div class="p-6 flex-1 overflow-y-auto">
                    <div class="mb-5">
                        <label class="block text-sm font-bold mb-2">Post As</label>
                        <select v-model="newPoll.actor" class="input-text w-full">
                            <option v-for="actor in localActors" :key="actor.id" :value="actor.id">{{ actor.name }} ({{ actor.handle }})</option>
                        </select>
                    </div>

                    <div class="mb-5">
                        <label class="block text-sm font-bold mb-2">Question</label>
                        <textarea v-model="newPoll.content" class="input-text w-full" rows="3" placeholder="Ask a question..."></textarea>
                    </div>

                    <div class="mb-5">
                        <label class="flex items-center space-x-2 cursor-pointer">
                            <input type="checkbox" v-model="newPoll.multiple_choice" class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                            <span class="text-sm font-bold">Allow Multiple Choices (Checkboxes)</span>
                        </label>
                    </div>

                    <div class="mb-5">
                        <label class="block text-sm font-bold mb-2">Options</label>
                        <div class="text-xs text-gray-500 mb-2">Leave options empty for an open-ended question.</div>
                        <div class="space-y-2">
                             <div v-for="(opt, idx) in newPoll.options" :key="idx" class="flex items-center gap-2">
                                <input type="text" v-model="newPoll.options[idx]" class="input-text w-full text-sm" placeholder="Option text">
                                <button v-if="newPoll.options.length > 2" @click="removeOption(idx)" class="text-red-500 hover:text-red-700">&times;</button>
                             </div>
                        </div>
                        <button @click="addOption" class="mt-2 text-sm text-blue-600 hover:text-blue-800">+ Add Option</button>
                    </div>

                </div>

                <div class="p-4 border-t dark:border-dark-900 bg-gray-100 dark:bg-dark-800 flex justify-end gap-3">
                    <button class="btn" @click="closePollModal">Cancel</button>
                    <button class="btn-primary" @click="submitPoll" :disabled="creating">
                        {{ creating ? 'Creating...' : 'Create Poll' }}
                    </button>
                </div>
            </div>
        </stack>

        <!-- Quote Stack -->
        <stack name="quote-stack" v-if="isCreatingQuote" @closed="closeQuoteModal">
            <div class="h-full w-full bg-white dark:bg-dark-900 flex flex-col">

                <!-- Header -->
                <div class="p-4 border-b dark:border-dark-900 flex justify-between items-center bg-gray-100 dark:bg-dark-800">
                    <h2 class="text-lg font-medium">Quote Post</h2>
                    <button @click="closeQuoteModal"
                        class="text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 text-2xl leading-none">
                        ×
                    </button>
                </div>

                <!-- Content -->
                <div class="p-6 flex-1 overflow-y-auto">
                    <!-- Original Note Preview -->
                    <div class="mb-6 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                        <div class="text-xs text-gray-500 dark:text-gray-400 mb-2">Quoting:</div>
                        <activity-pub-note
                            v-if="quotedNote"
                            :note="quotedNote"
                            :permissions="{ update: false, delete: false }"
                            @reply="() => {}"
                            @boost="() => {}"
                            @quote="() => {}"
                            @like="() => {}"
                        />
                    </div>

                    <!-- Post As Selector -->
                    <div class="mb-5">
                        <label class="block text-sm font-bold mb-2 text-gray-700 dark:text-gray-300">Post As</label>
                        <select v-model="newQuote.actor" class="input-text w-full">
                            <option v-for="actor in localActors" :key="actor.id" :value="actor.id">
                                {{ actor.name }} ({{ actor.handle }})
                            </option>
                        </select>
                    </div>

                    <!-- Content Warning -->
                    <div class="mb-5">
                        <label class="block text-sm font-bold mb-2 text-gray-700 dark:text-gray-300">
                            Content Warning (Optional)
                        </label>
                        <input
                            type="text"
                            v-model="newQuote.content_warning"
                            class="input-text w-full"
                            placeholder="Content Warning (optional)">
                    </div>

                    <!-- Quote Commentary -->
                    <div class="mb-5">
                        <label class="block text-sm font-bold mb-2 text-gray-700 dark:text-gray-300">
                            Your Commentary
                        </label>
                        <markdown-fieldtype
                            :value="newQuote.content"
                            :config="{
                                cheatsheet: true,
                                container: 'assets',
                                buttons: ['bold', 'italic', 'unorderedlist', 'orderedlist', 'quote', 'link', 'image', 'table'],
                                type: 'markdown'
                            }"
                            @input="val => newQuote.content = val"
                        />
                    </div>
                </div>

                <!-- Footer -->
                <div class="p-4 border-t dark:border-dark-900 bg-gray-100 dark:bg-dark-800 flex justify-end gap-3">
                    <button class="btn" @click="closeQuoteModal">Cancel</button>
                    <button class="btn-primary" @click="submitQuote" :disabled="creating">
                        {{ creating ? 'Posting...' : 'Quote' }}
                    </button>
                </div>
            </div>
        </stack>

        <!-- History Stack -->
        <stack name="history-stack" v-if="isViewingHistory" @closed="closeHistoryModal">
            <div class="h-full w-full bg-white dark:bg-dark-900 flex flex-col">
                <div class="p-4 border-b dark:border-dark-900 flex justify-between items-center bg-gray-100 dark:bg-dark-800">
                    <h2 class="text-lg font-medium">Activity History</h2>
                    <button @click="closeHistoryModal" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">&times;</button>
                </div>
                
                <div class="flex-1 overflow-y-auto p-0">
                    <div v-if="loadingHistory" class="py-8 text-center text-gray-500">
                        Loading history...
                    </div>
                    <div v-else-if="relatedActivities.length === 0" class="py-8 text-center text-gray-500">
                        No related activities found.
                    </div>
                    <div v-else class="flex flex-col divide-y dark:divide-dark-800">
                         <div v-for="act in relatedActivities" :key="act.id" class="p-4 hover:bg-gray-50 dark:hover:bg-dark-800 transition-colors">
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
                                         <button v-if="act.activitypub_json" class="flex items-center gap-1 text-xs text-gray-400 hover:text-blue-500 transition-colors" @click="viewJson(act)" title="View JSON">
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
                </div>
            </div>
        </stack>

        <!-- Thread Drawer Stack -->
        <stack name="thread-stack" v-if="isViewingThread" @closed="closeThreadModal">
            <div class="h-full w-full bg-white dark:bg-dark-900 flex flex-col">
                <div class="p-4 border-b dark:border-dark-900 flex justify-between items-center bg-gray-100 dark:bg-dark-800">
                    <h2 class="text-lg font-medium">Thread</h2>
                    <button @click="closeThreadModal" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">&times;</button>
                </div>
                
                <div class="flex-1 overflow-y-auto p-0">
                    <div v-if="loadingThread" class="py-8 text-center text-gray-500">
                        Loading thread...
                    </div>
                    <div v-else-if="threadItems.length === 0" class="py-8 text-center text-gray-500">
                        Thread is empty.
                    </div>
                    <div v-else class="flex flex-col">
                         <div v-for="(item, idx) in threadItems" :key="item.id || idx" 
                            class="relative"
                            :class="{'bg-blue-50 dark:bg-dark-800': item.is_focus}">
                             
                             <!-- Thread Connector Line -->
                             <div v-if="item.depth > 0" class="absolute left-6 top-0 bottom-0 border-l-2 border-gray-200 dark:border-gray-700"
                                  :style="{ 'left': (item.depth * 1.5) + 'rem' }"></div>

                             <div class="p-4 border-b dark:border-dark-800" :style="{ 'margin-left': (item.depth * 1.5) + 'rem' }">
                                <activity-pub-note 
                                    :note="item" 
                                    :permissions="{ update: !!updateNoteUrl, delete: !!deleteUrl }"
                                    @reply="toggleReply"
                                    @boost="toggleBoost"
                                    @like="toggleLike"
                                    @history="viewHistory"
                                    @thread="viewThread"
                                    @json="viewJson"
                                    @lightbox="params => openLightbox(params.attachments, params.index)"
                                    @delete="deleteItem"
                                    @edit="editNote"
                                >
                                <!-- ... slots ... -->
                                    <template #reply-editor>
                                        <div v-if="activeReplyId === item.id" class="mt-4 p-4 rounded-lg border shadow-sm animate-fade-in-down ap-reply-card">
                                            <div class="flex flex-col gap-3">
                                                 <div class="flex items-center gap-2">
                                                    <label class="text-xs text-gray-500 uppercase font-bold">Reply As:</label>
                                                    <select v-model="replyActorId" class="text-sm rounded-md shadow-sm ap-reply-input">
                                                        <option v-for="actor in localActors" :key="actor.id" :value="actor.id">{{ actor.name }} ({{ actor.handle }})</option>
                                                    </select>
                                                 </div>
                                                <div class="mb-3">
                                                    <input type="text" v-model="replyContentWarning" placeholder="Content Warning (optional)" class="input-text w-full text-sm">
                                                </div>
                                                 <div class="min-h-[150px]">
                                                    <markdown-fieldtype
                                                        :value="replyContent"
                                                        :config="{
                                                            cheatsheet: true,
                                                            container: 'assets',
                                                            buttons: ['bold', 'italic', 'unorderedlist', 'orderedlist', 'quote', 'link', 'image', 'table']
                                                        }"
                                                        @input="val => replyContent = val"
                                                    />
                                                 </div>
                                                 <div class="flex justify-end gap-2 mt-3">
                                                     <button type="button" @click="activeReplyId = null" class="btn">Cancel</button>
                                                     <button type="button" @click="submitReply(item)" class="btn-primary" :disabled="sendingReply">
                                                         {{ sendingReply ? 'Sending...' : 'Reply' }}
                                                     </button>
                                                 </div>
                                            </div>
                                        </div>
                                    </template>
                                </activity-pub-note>
                             </div>
                             


                         </div>
                    </div>
                </div>
            </div>
        </stack>
    </div>
</template>

<style>
.ap-filter-container {
    background-color: #e5e7eb; /* gray-200 */
    border-color: #e5e7eb;
}
html.dark .ap-filter-container,
html.is-dark .ap-filter-container,
html.isdark .ap-filter-container {
    background-color: #171717; /* neutral-900 */
    border-color: #262626; /* neutral-800 */
}

/* Light Mode Defaults */
.ap-filter-btn.active {
    background-color: white;
    color: #111827; /* gray-900 */
    box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
}
.ap-filter-btn.inactive {
    color: #6b7280; /* gray-500 */
}
.ap-filter-btn.inactive:hover {
    color: #374151; /* gray-700 */
}

/* Dark Mode Overrides */
html.dark .ap-filter-btn.active,
html.is-dark .ap-filter-btn.active,
html.isdark .ap-filter-btn.active {
    background-color: #404040; /* neutral-700 */
    color: #f5f5f5; /* neutral-100 */
}
html.dark .ap-filter-btn.inactive,
html.is-dark .ap-filter-btn.inactive,
html.isdark .ap-filter-btn.inactive {
    color: #a3a3a3; /* neutral-400 */
}
html.dark .ap-filter-btn.inactive:hover,
html.is-dark .ap-filter-btn.inactive:hover,
html.isdark .ap-filter-btn.inactive:hover {
    color: #e5e5e5; /* neutral-200 */
}

/* Reply Editor Styles */
.ap-reply-card {
    background-color: white;
    border-color: #e5e7eb; /* gray-200 */
}
.ap-reply-input {
    background-color: white;
    border-color: #d1d5db; /* gray-300 */
    color: #111827; /* gray-900 */
}

/* Reply Editor Dark Mode */
html.dark .ap-reply-card,
html.is-dark .ap-reply-card,
html.isdark .ap-reply-card {
    background-color: #262626; /* neutral-800 */
    border-color: #404040; /* neutral-700 */
}

html.dark .ap-reply-input,
html.is-dark .ap-reply-input,
html.isdark .ap-reply-input {
    background-color: #171717; /* neutral-900 */
    border-color: #404040; /* neutral-700 */
    color: #d4d4d4; /* neutral-300 */
}

/* JSON Drawer Styles */
.activitypub-json-drawer {
    height: calc(100vh - 3.25rem);
    right: 0;
    width: 40vw;
    margin-top: 3.25rem;
    background-color: white;
    --tw-shadow: 0 0 0 1px rgba(49, 49, 93, .05), 0 2px 5px 0 rgba(49, 49, 93, .075), 0 1px 3px 0 rgba(49, 49, 93, .15);
    --tw-shadow-colored: 0 0 0 1px var(--tw-shadow-color), 0 2px 5px 0 var(--tw-shadow-color), 0 1px 3px 0 var(--tw-shadow-color);
    box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), var(--tw-shadow);
}

/* Statamic Dark Mode Overrides - Non-scoped for reliability */
html.is-dark .activitypub-json-drawer,
html.dark .activitypub-json-drawer,
html.isdark .activitypub-json-drawer {
    background-color: #262626 !important; /* neutral-800 */
    color: #d4d4d4; /* neutral-300 */
}

html.is-dark .activitypub-json-drawer .border-b,
html.is-dark .activitypub-json-drawer .border-t,
html.dark .activitypub-json-drawer .border-b,
html.dark .activitypub-json-drawer .border-t,
html.isdark .activitypub-json-drawer .border-b,
html.isdark .activitypub-json-drawer .border-t {
    border-color: #404040 !important; /* neutral-700 */
}

html.is-dark .activitypub-json-drawer h3,
html.dark .activitypub-json-drawer h3,
html.isdark .activitypub-json-drawer h3 {
    color: white;
}

html.is-dark .activitypub-json-drawer button.text-gray-500:hover,
html.dark .activitypub-json-drawer button.text-gray-500:hover,
html.isdark .activitypub-json-drawer button.text-gray-500:hover {
    color: #d4d4d4 !important; /* neutral-300 */
}

html.is-dark .activitypub-json-drawer .bg-gray-50,
html.dark .activitypub-json-drawer .bg-gray-50,
html.isdark .activitypub-json-drawer .bg-gray-50 {
    background-color: #171717 !important; /* neutral-900 */
}

html.is-dark .vjs-tree,
html.dark .vjs-tree,
html.isdark .vjs-tree {
    color: #d4d4d4 !important;
}
</style>

<script>
import VueJsonPretty from 'vue-json-pretty';
import 'vue-json-pretty/lib/styles.css';
import ActivityPubNote from './ActivityPubNote.vue';

export default {
    components: {
        VueJsonPretty,
        ActivityPubNote
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
            total: 0,
            perPage: parseInt(localStorage.getItem('activitypub_per_page')) || 25,
            loading: false,
            loading: false,
            filter: 'all',
            error: null,
            localActors: this.initialActors,
            activeReplyId: null,
            replyContent: '',
            replyContentWarning: '',
            replyActorId: this.initialActors[0]?.id || null,
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
            relatedActivities: [],
            loadingHistory: false,
            isViewingThread: false,
            threadItems: [],
            loadingThread: false
        }
    },
    computed: {
        paginationRange() {
            const range = [];
            const delta = 2; // Number of pages around current page
            const left = this.page - delta;
            const right = this.page + delta + 1;
            
            for (let i = 1; i <= this.lastPage; i++) {
                if (i === 1 || i === this.lastPage || (i >= left && i < right)) {
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
    },
    mounted() {
        this.loadNotes();
    },
    methods: {
        loadNotes() {
            if (this.loading) return;
            
            this.loading = true;
            this.error = null;
            
            fetch(`${this.apiUrl}?page=${this.page}&per_page=${this.perPage}&filter=${this.filter}`)
                .then(res => {
                     if (!res.ok) throw new Error('Failed to load');
                     return res.json();
                })
                .then(data => {
                    const newNotes = data.data.map(note => ({
                        ...note,
                        showContent: !note.sensitive
                    }));
                    
                    this.notes = newNotes;
                    this.total = data.meta.total;
                    this.lastPage = data.meta.last_page;
                    
                    // Batch Enrichment (Link Previews + OEmbed)
                    const needsEnrichmentIds = newNotes
                        .filter(n => n.needs_preview && !n.link_preview && !n.oembed)
                        .map(n => n.id);

                    if (needsEnrichmentIds.length > 0 && this.batchLinkPreviewUrl) {
                        this.fetchBatchEnrichment(needsEnrichmentIds);
                    } else if (needsEnrichmentIds.length > 0 && this.linkPreviewUrl) {
                        // Fallback if batch url is missing (legacy)
                        newNotes.forEach(note => {
                            if (note.needs_preview && !note.link_preview) {
                                this.fetchLinkPreview(note);
                            }
                        });
                    }
                })
                .catch(err => {
                    this.error = err.message;
                    this.notes = [];
                    this.total = 0;
                })
                .finally(() => {
                    this.loading = false;
                });
        },
        changePage(p) {
            if (p < 1 || p > this.lastPage || p === this.page) return;
            this.page = p;
            this.notes = []; // Clear notes to force scroll reset and remove "append" feel
            this.loadNotes();
            window.scrollTo(0, 0);
        },
        changePerPage() {
            localStorage.setItem('activitypub_per_page', this.perPage);
            this.page = 1;
            this.loadNotes();
        },
        fetchBatchEnrichment(ids) {
            // Use new batch-enrichment endpoint (falls back to batch-link-preview for backwards compatibility)
            fetch(this.batchLinkPreviewUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': StatamicConfig.csrfToken
                },
                body: JSON.stringify({ note_ids: ids })
            })
            .then(res => res.json())
            .then(data => {
                if (data.data) {
                    const enrichments = data.data; // Map of id => { oembed, link_preview }
                    this.notes.forEach(note => {
                        if (enrichments[note.id]) {
                            const enrichment = enrichments[note.id];

                            // Apply OEmbed if available
                            if (enrichment.oembed) {
                                note.oembed = enrichment.oembed;
                            }

                            // Apply link preview if available (and no oembed)
                            if (enrichment.link_preview && !enrichment.oembed) {
                                note.link_preview = enrichment.link_preview;
                            }
                        }
                    });
                }
            })
            .catch(e => console.error('Enrichment error:', e));
        },

        // Legacy method for backwards compatibility
        fetchBatchLinkPreviews(ids) {
            return this.fetchBatchEnrichment(ids);
        },
        setFilter(filter) {
            if (this.filter === filter) return;
            this.filter = filter;
            this.page = 1;
            this.notes = [];
            this.notes = [];
            this.loadNotes();
        },
        retry() {
            this.loadNotes();
        },
        toggleReply(note) {
            if (this.activeReplyId === note.id) {
                this.activeReplyId = null;
            } else {
                this.activeReplyId = note.id;
                this.replyContent = note.actor.handle ? `[${note.actor.handle}](${note.actor.url}) ` : '';
                this.replyContentWarning = '';
            }
        },
        submitReply(note) {
            if (!this.replyContent.trim()) return;
            
            this.sendingReply = true;
            fetch(this.replyUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': StatamicConfig.csrfToken
                },
                body: JSON.stringify({
                    content: this.replyContent,
                    content_warning: this.replyContentWarning,
                    actor: this.replyActorId,
                    in_reply_to: note.id
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    this.activeReplyId = null;
                    this.replyContent = '';
                    this.replyContentWarning = '';
                    this.$toast.success('Reply sent!');
                } else {
                    this.$toast.error(data.message || 'Error sending reply');
                }
            })
            .catch(err => {
                this.$toast.error('Network error');
            })
            .finally(() => {
                this.sendingReply = false;
            });
        },
        handleVote({ note, option, callback }) {
            // Use logic similar to submitReply but without modal
            const actorId = this.replyActorId || (this.localActors.length ? this.localActors[0].id : null);
            
            if (!actorId) {
                this.$toast.error('No local actor selected');
                callback(false);
                return;
            }

            fetch(this.replyUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': StatamicConfig.csrfToken
                },
                body: JSON.stringify({
                    content: option.name, // Vote matches option name
                    content_warning: '',
                    actor: actorId,
                    in_reply_to: note.id
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    this.$toast.success('Vote submitted!');
                    callback(true);
                } else {
                    this.$toast.error(data.message || 'Error submitting vote');
                    callback(false);
                }
            })
            .catch(err => {
                this.$toast.error('Network error');
                callback(false);
            });
        },
        openLightbox(attachments, index) {
            this.lightbox.images = attachments;
            this.lightbox.index = index;
            this.lightbox.open = true;
            document.body.style.overflow = 'hidden';
        },
        closeLightbox() {
            this.lightbox.open = false;
            document.body.style.overflow = '';
        },
        lightboxNav(dir) {
            let newIndex = this.lightbox.index + dir;
            if (newIndex < 0) newIndex = this.lightbox.images.length - 1;
            if (newIndex >= this.lightbox.images.length) newIndex = 0;
            this.lightbox.index = newIndex;
        },
        toggleLike(note) {
            const isLiked = note.liked_by_user;
            const url = isLiked ? this.unlikeUrl : this.likeUrl;
            
            // Optimistic update
            note.liked_by_user = !isLiked;
            note.counts.likes += isLiked ? -1 : 1;
            
            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': StatamicConfig.csrfToken
                },
                body: JSON.stringify({
                    object_url: note.actions.view || (note.id ? window.location.origin + '/activitypub/notes/' + note.id : '')
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.status !== 'success' && data.status !== 'ignored') {
                     note.liked_by_user = isLiked;
                     note.counts.likes += isLiked ? 1 : -1;
                     this.$toast.error(data.message || 'Error updating like');
                }
            })
            .catch(err => {
                 note.liked_by_user = isLiked;
                 note.counts.likes += isLiked ? 1 : -1;
                 this.$toast.error('Network error');
            });
        },
        toggleBoost(note) {
            const isBoosted = note.boosted_by_user;
            const url = isBoosted ? this.undoAnnounceUrl : this.announceUrl;
            
            note.boosted_by_user = !isBoosted;
            note.counts.boosts += isBoosted ? -1 : 1;
            
            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': StatamicConfig.csrfToken
                },
                body: JSON.stringify({
                    object_url: note.actions.view || (note.id ? window.location.origin + '/activitypub/notes/' + note.id : '')
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.status !== 'success' && data.status !== 'ignored') {
                     note.boosted_by_user = isBoosted;
                     note.counts.boosts += isBoosted ? 1 : -1;
                     this.$toast.error(data.message || 'Error updating boost');
                } else {
                     this.$toast.success(isBoosted ? 'Unboosted' : 'Boosted');
                }
            })
            .catch(err => {
                 note.boosted_by_user = isBoosted;
                 note.counts.boosts += isBoosted ? 1 : -1;
                 this.$toast.error('Network error');
            });
        },
        viewJson(note) {
            if (note.activitypub_json) {
                try {
                    this.jsonModal.content = JSON.parse(note.activitypub_json);
                    this.jsonModal.open = true;
                    document.body.style.overflow = 'hidden';
                } catch (e) {
                    this.$toast.error('Invalid JSON');
                }
            }
        },
        closeJsonModal() {
            this.jsonModal.open = false;
            document.body.style.overflow = '';
        },
        createNote() {
            if (this.createNoteUrl) {
                this.isCreatingNote = true;
                this.editingNoteId = null;
                this.newNote.actor = this.localActors[0]?.id;
                this.newNote.content = '';
                this.newNote.content_warning = '';
            }
        },
        toggleNewDropdown() {
             this.showNewDropdown = !this.showNewDropdown;
        },
        createPollAndClose() {
             this.showNewDropdown = false;
             this.createPoll();
        },
        createPoll() {
            if (this.storePollUrl) {
                this.isCreatingPoll = true;
                this.newPoll.actor = this.localActors[0]?.id;
                this.newPoll.content = '';
                this.newPoll.multiple_choice = false;
                this.newPoll.options = ['', ''];
            }
        },
        closePollModal() {
            this.isCreatingPoll = false;
        },
        addOption() {
            this.newPoll.options.push('');
        },
        removeOption(index) {
            this.newPoll.options.splice(index, 1);
        },
        submitPoll() {
            if (!this.newPoll.content.trim()) {
                this.$toast.error('Question is required');
                return;
            }

            this.creating = true;
            
            // Filter out empty options
            const validOptions = this.newPoll.options.filter(o => o.trim() !== '');

            fetch(this.storePollUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': StatamicConfig.csrfToken
                },
                body: JSON.stringify({
                    ...this.newPoll,
                    options: validOptions
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    this.$toast.success('Poll created successfully');
                    this.isCreatingPoll = false;
                    // Ideally verify if the poll appears in feed or reload
                    this.page = 1;
                    this.notes = [];
                    this.hasMore = true; 
                    this.loadNotes();
                } else {
                    this.$toast.error(data.message || 'Error creating poll');
                }
            })
            .catch(err => {
                this.$toast.error('Network error');
            })
            .finally(() => {
                this.creating = false;
            });
        },

        editNote(note) {
            this.isCreatingNote = true;
            this.editingNoteId = note.id;
            
            const foundActor = this.localActors.find(a => note.actor.handle.includes(a.handle) || a.name === note.actor.name);
            this.newNote.actor = foundActor ? foundActor.id : this.localActors[0]?.id;
            
            this.newNote.content = note.raw_content || note.content; 
            this.newNote.content_warning = note.summary || '';
        },
        closeNoteModal() {
            this.isCreatingNote = false;
            this.editingNoteId = null;
        },
        submitNote() {
            if (!this.newNote.content) return;

            this.creating = true;
            console.log(this.editingNoteId,this.updateNoteUrl,this.storeNoteUrl);

            const url = this.editingNoteId ? this.updateNoteUrl : this.storeNoteUrl;
            const payload = {
                content: this.newNote.content,
                content_warning: this.newNote.content_warning,
            };
            
            if (this.editingNoteId) {
                payload.id = this.editingNoteId;
            } else {
                payload.actor = this.newNote.actor;
            }

            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': StatamicConfig.csrfToken
                },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    this.$toast.success(this.editingNoteId ? 'Note updated' : 'Note created');
                    this.closeNoteModal();
                    this.page = 1;
                    this.notes = [];
                    this.hasMore = true;
                    this.loadNotes();
                } else {
                    this.$toast.error(data.message || 'Error saving note');
                }
            })
            .catch(err => {
                this.$toast.error('Network error');
            })
            .finally(() => {
                this.creating = false;
            });
        },
        openQuoteModal(note) {
            this.quotedNote = note;
            this.newQuote = {
                actor: this.localActors[0]?.id || null,
                content: '',
                content_warning: '',
                quote_of: note.id,
            };
            this.isCreatingQuote = true;
        },
        closeQuoteModal() {
            this.isCreatingQuote = false;
            this.quotedNote = null;
            this.newQuote = {
                actor: null,
                content: '',
                content_warning: '',
                quote_of: null,
            };
        },
        async submitQuote() {
            if (!this.newQuote.content.trim()) {
                this.$toast.error('Please add your commentary');
                return;
            }

            this.creating = true;

            const payload = {
                actor: this.newQuote.actor,
                content: this.newQuote.content,
                content_warning: this.newQuote.content_warning,
                quote_of: this.newQuote.quote_of,
            };

            fetch(this.storeNoteUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': StatamicConfig.csrfToken
                },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    this.$toast.success("Quoted successfully");
                    this.closeQuoteModal();
                    this.page = 1;
                    this.notes = [];
                    this.hasMore = true;
                    this.loadNotes();
                } else {
                    this.$toast.error(data.message || 'Error quoting');
                }
            })
            .catch(err => {
                console.error('Quote error:', err);
                this.$toast.error('Failed to quote');
            })
            .finally(() => {
                this.creating = false;
            });
        },
        viewHistory(note) {
            this.isViewingHistory = true;
            this.historyNote = note;
            this.loadingHistory = true;
            this.relatedActivities = [];

            const baseUrl = this.apiUrl.replace(/\/api\/?$/, '');
            const url = `${baseUrl}/activities/${note.id}`;

            fetch(url)
                .then(res => res.json())
                .then(data => {
                    this.relatedActivities = data.data;
                })
                .catch(err => {
                    this.$toast.error('Failed to load history');
                })
                .finally(() => {
                    this.loadingHistory = false;
                });
        },
        closeHistoryModal() {
            this.isViewingHistory = false;
            this.historyNote = null;
            this.relatedActivities = [];
        },
        viewThread(note) {
            if (!note.actions || !note.actions.thread) {
                this.$toast.error('Cannot view thread for this item');
                return;
            }
            
            this.isViewingThread = true;
            this.threadItems = [];
            this.loadingThread = true;

            fetch(note.actions.thread)
                .then(res => res.json())
                .then(data => {
                    // Logic to calculate depth for indentation
                    // data.data is list of notes.
                    let focusIdx = data.data.findIndex(n => n.id === note.id);
                    if (focusIdx === -1) {
                         // Fallback if ID mismatch (e.g. types)
                         focusIdx = data.data.length - 1; // Assume last? No.
                    }

                    this.threadItems = data.data.map((n, idx) => {
                         let d = 0;
                         let isFocus = false;
                         
                         if (focusIdx !== -1) {
                             if (idx < focusIdx) {
                                 d = idx; // Ancestors: 0, 1, 2...
                             } else if (idx === focusIdx) {
                                 d = idx; 
                                 isFocus = true;
                             } else {
                                 // Replies to Focus
                                 d = focusIdx + 1;
                             }
                         }

                         const item = {
                            ...n,
                            depth: d,
                            is_focus: isFocus,
                            showContent: !n.sensitive
                        };

                        if (item.needs_preview && !item.link_preview && this.linkPreviewUrl) {
                            this.fetchLinkPreview(item);
                        }

                        return item;
                    });
                })
                .catch(err => {
                    this.$toast.error('Failed to load thread');
                })
                .finally(() => {
                    this.loadingThread = false;
                });
        },
        closeThreadModal() {
            this.isViewingThread = false;
            this.threadItems = [];
        },
        deleteItem(note) {
            if (!confirm('Are you sure you want to delete this item?')) return;

            fetch(this.deleteUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': StatamicConfig.csrfToken
                },
                body: JSON.stringify({
                    id: note.id
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    this.$toast.success('Item deleted');
                    this.notes = this.notes.filter(n => n.id !== note.id);
                } else {
                    this.$toast.error(data.message || 'Error deleting item');
                }
            })
            .catch(err => {
                this.$toast.error('Network error');
            });
        },
        fetchLinkPreview(note) {
            fetch(this.linkPreviewUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': StatamicConfig.csrfToken
                },
                body: JSON.stringify({
                    note_id: note.id
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.data) {
                    // Update in main list
                    const target = this.notes.find(n => n.id === note.id);
                    if (target) {
                        target.link_preview = data.data;
                    }
                    
                    // Update in thread list
                    const threadTarget = this.threadItems.find(n => n.id === note.id);
                    if (threadTarget) {
                        threadTarget.link_preview = data.data;
                    }
                }
            })
            .catch(err => {
                // Silent catch
            });
        }
    }
}
</script>

<style scoped>
@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
.animate-spin {
  animation: spin 1s linear infinite;
}
</style>
