<template>
    <div class="ap-note-container transition-colors">
         <!-- Boost Header -->
        <div v-if="note.is_boost && note.boosted_by" class="px-4 pt-3 flex items-center gap-2 text-gray-500 text-sm">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            <span class="font-semibold text-gray-600 dark:text-gray-400">{{ note.boosted_by.name }}</span>
            <span class="text-gray-400">boosted</span>
        </div>

        <!-- Content -->
         <div :class="{'px-4 pb-4 pt-1': note.is_boost, 'p-4': !note.is_boost}" class="flex gap-4">
            <div class="flex-shrink-0">
                <img :src="note.actor.avatar || 'https://www.gravatar.com/avatar/?d=mp'" loading="lazy" class="w-12 h-12 rounded-full bg-gray-200 object-cover">
            </div>

            <div class="flex-1 min-w-0">
                <div class="flex items-baseline justify-between gap-2">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="font-bold truncate">{{ note.actor.name }}</span>
                        <span class="text-sm text-gray-500 truncate" :title="note.actor.handle">{{ note.actor.handle }}</span>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <span class="text-xs text-gray-400 whitespace-nowrap" :title="note.date">{{ note.date_human }}</span>
                        <button v-if="canEdit" class="text-gray-400 hover:text-gray-900 dark:hover:text-white transition-colors" @click="$emit('edit', note)" title="Edit">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                        </button>
                        <button v-if="permissions.delete" @click="$emit('delete', note)" class="text-gray-400 transition-colors delete-btn" title="Delete">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </button>
                    </div>
                </div>
                
                <!-- Content Warning -->
                <div v-if="note.sensitive" 
                     @click="note.showContent = !note.showContent"
                     class="mb-2 p-2 bg-yellow-50 dark:bg-yellow-900/20 rounded text-sm flex items-center gap-2 text-yellow-800 dark:text-yellow-200 cursor-pointer select-none">
                    <span>⚠️</span>
                    <span>{{ note.summary || 'Sensitive Content' }}</span>
                </div>

                <div v-if="!note.sensitive || note.showContent" class="mt-2 mb-3 prose dark:prose-invert text-sm max-w-none break-words" v-html="note.content"></div>

                <!-- Dynamic Content Hook (e.g., Poll UI, Article Preview) -->
                <activity-pub-hook-loader 
                    name="inbox-note-content" 
                    :props="{ note, permissions, actors, storeNoteUrl }"
                    @vote="$emit('vote', $event)"
                />

                
                <!-- Attachments -->
                <div v-if="note.attachments && note.attachments.length > 0" class="mt-3 flex gap-2">
                    <div v-for="(att, idx) in note.attachments.slice(0, 3)" :key="idx" 
                         class="relative flex-1 cursor-pointer group overflow-hidden rounded-lg"
                         @click="$emit('lightbox', { attachments: note.attachments, index: idx })">
                        <img :src="att.url" :alt="att.description" class="w-full h-full object-cover transition-transform group-hover:scale-[1.02]" loading="lazy">
                    </div>
                </div>

                <!-- OEmbed -->
                <div v-if="note.oembed && note.oembed.html" class="mt-3" v-html="note.oembed.html"></div>

                <!-- Link Preview -->
                <a v-if="note.link_preview && !note.oembed" :href="note.link_preview.url" target="_blank" class="block mt-3 rounded-lg overflow-hidden transition-colors group no-underline ap-link-preview">
                    <div class="flex flex-col sm:flex-row h-full">
                        <div v-if="note.link_preview.image" class="sm:w-1/3 h-48 sm:h-auto relative overflow-hidden ap-link-preview-image">
                            <img :src="note.link_preview.image" class="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" loading="lazy">
                        </div>
                        <div class="p-4 flex flex-col justify-center flex-1 min-w-0">
                            <h3 class="font-bold text-sm line-clamp-2 mb-1">{{ note.link_preview.title }}</h3>
                            <p class="text-xs text-gray-500 line-clamp-2 mb-2">{{ note.link_preview.description }}</p>
                            <div class="flex items-center gap-2 mt-auto">
                                <img v-if="note.link_preview.icon" :src="note.link_preview.icon" class="w-4 h-4 rounded-sm">
                                <span class="text-xs text-gray-400 uppercase tracking-wider">{{ note.link_preview.site_name || 'Link' }}</span>
                            </div>
                        </div>
                    </div>
                </a>
                <!-- Tags Display -->
                <div v-if="note.tags && note.tags.length > 0" class="mt-3 flex flex-wrap gap-1">
                    <span v-for="(tag, idx) in note.tags" :key="idx" class="bg-gray-100 dark:bg-neutral-800 text-gray-600 dark:text-gray-400 text-xs px-2 py-0.5 rounded-full inline-flex items-center">
                        {{ typeof tag === 'object' ? (tag.title || tag.name || Object.values(tag)[0]) : tag }}
                    </span>
                </div>

                <!-- Actions -->
                <div class="mt-3 flex items-center gap-6 text-sm text-gray-500">
                    <!-- Actions for standard objects (Notes, Articles, Questions, etc) -->
                    <template v-if="note.type && note.type !== 'activity'">
                        <!-- Content View Toggle (Sensitive only) -->
                        <button v-if="note.sensitive" class="flex items-center gap-1 hover:text-gray-900 dark:hover:text-white transition-colors" @click="note.showContent = !note.showContent" :title="note.showContent ? 'Hide Content' : 'Show Content'">
                             <svg v-if="!note.showContent" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                            <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                            </svg>
                        </button>
                        <button class="flex items-center gap-1 text-gray-500 hover:text-gray-900 dark:hover:text-white transition-colors" @click="$emit('reply', note)" title="Reply">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6" />
                            </svg>
                        </button>

                        <button class="flex items-center gap-1 transition-colors" :class="note.boosted_by_user ? 'text-green-600' : 'text-gray-500 hover:text-gray-900 dark:hover:text-white'" @click="$emit('boost', note)" title="Boost">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                        </button>

                         <!-- Quote Button -->
                         <button class="flex items-center gap-1 text-gray-500 hover:text-gray-900 dark:hover:text-white transition-colors" @click="$emit('quote', note)" title="Quote">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z" />
                            </svg>
                         </button>

                         <button class="flex items-center gap-1 transition-colors" :class="note.liked_by_user ? 'text-yellow-500' : 'text-gray-500 hover:text-gray-900 dark:hover:text-white'" @click="$emit('like', note)" title="Like">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" :fill="note.liked_by_user ? 'currentColor' : 'none'" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                            </svg>
                            <span>{{ note.counts?.likes || 0 }}</span>
                         </button>
                         
                         <button v-if="note.related_activity_count > 0 || note.related_activity_count" class="flex items-center gap-1 text-gray-500 hover:text-gray-900 dark:hover:text-white transition-colors" @click="$emit('history', note)" title="View History">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </button>

                        <button class="flex items-center gap-1 text-gray-500 hover:text-gray-900 dark:hover:text-white transition-colors" @click="$emit('thread', note)" title="View Thread">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="currentColor" viewBox="0 0 492.43313 470.25731">
                             <g transform="matrix(0.75674108,0,0,0.70536422,57.817645,55.147289)">
                                <path d="m 16.695312,-2.5546875 c -10.7064892,0 -19.2499995,8.5435103 -19.2499995,19.2499995 v 38.957032 c 0,29.137041 23.5058235,52.640626 52.6425785,52.640626 H 64.228516 V 403.70703 H 50.087891 c -29.136755,0 -52.6425785,23.50358 -52.6425785,52.64063 v 38.95703 c 0,10.7065 8.5434901,19.25 19.2499995,19.25 H 384 c 10.71306,0 19.25,-8.53506 19.25,-19.25 v -38.95703 c 0,-29.13649 -23.50414,-52.64063 -52.64063,-52.64063 H 336.4668 v -28.2832 h 30.83789 c 38.29372,10e-6 69.33789,-31.04205 69.33789,-69.33594 v -66.7832 c 0,-17.11722 13.71869,-30.83789 30.83594,-30.83789 h 27.82617 c 10.71308,0 19.25,-8.53502 19.25,-19.25 0,-10.7065 -8.54352,-19.25 -19.25,-19.25 h -27.82617 c -38.29404,0 -69.33789,31.04386 -69.3379,69.33789 v 66.7832 c 0,17.11687 -13.71907,30.83594 -30.83593,30.83594 H 336.4668 V 108.29297 h 14.14257 c 29.13649,0 52.64063,-23.504139 52.64063,-52.640626 V 16.695312 C 403.25,5.9803688 394.71306,-2.5546875 384,-2.5546875 Z m 19.25,38.4999995 H 364.75 v 19.707032 c 0,7.961761 -6.18114,14.140625 -14.14258,14.140625 H 50.087891 c -7.961438,0 -14.142579,-6.178863 -14.142579,-14.140625 z M 102.72852,108.29297 H 297.9668 v 28.2832 H 102.72852 Z m 0,66.7832 H 297.9668 v 28.28125 H 102.72852 Z m 0,66.7832 H 297.9668 v 28.28125 H 102.72852 Z m 0,66.78321 H 297.9668 v 28.28125 H 102.72852 Z m 0,66.78125 H 297.9668 v 28.2832 H 102.72852 Z m -52.640629,66.7832 H 350.60937 c 7.96039,0 14.14063,6.18025 14.14063,14.14063 v 19.70703 H 35.945312 v -19.70703 c 0,-7.96177 6.181139,-14.14063 14.142579,-14.14063 z" />
                             </g>
                        </svg>
                            <span>{{ note.counts?.replies || 0 }}</span>
                        </button>

                    </template>

                    <!-- Common Actions (View Source / JSON) -->
                     <a v-if="note.actions && note.actions.view" :href="note.actions.view" target="_blank" class="flex items-center gap-1 text-gray-500 hover:text-gray-900 dark:hover:text-white transition-colors" title="View Original">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                        </svg>
                     </a>

                     <button v-if="note.activitypub_json" class="flex items-center gap-1 text-gray-500 hover:text-gray-900 dark:hover:text-white transition-colors" @click="$emit('json', note)" title="View Activity JSON">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
                        </svg>
                     </button>

                     <!-- Dynamic Actions Hook -->
                     <activity-pub-hook-loader 
                        name="inbox-note-actions" 
                        :props="{ note, permissions }"
                        @reply="$emit('reply', $event)"
                        @boost="$emit('boost', $event)"
                        @like="$emit('like', $event)"
                        @quote="$emit('quote', $event)"
                        @history="$emit('history', $event)"
                        @thread="$emit('thread', $event)"
                        @json="$emit('json', $event)"
                        @vote="$emit('vote', $event)"
                     />
                </div>

                <!-- INDENTED PARENT NOTE -->
                <div v-if="note.parent" class="mt-4 pt-4 pl-4 ml-2 rounded-r-lg ap-parent-note">
                     <div class="text-xs text-gray-500 mb-2 flex items-center gap-1 font-semibold uppercase tracking-wider">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6" />
                        </svg>
                        In reply to:
                     </div>
                     <inbox-note 
                        :note="note.parent" 
                        :permissions="permissions"
                        :actors="actors"
                        @reply="$emit('reply', $event)"
                        @boost="$emit('boost', $event)"
                        @like="$emit('like', $event)"
                        @history="$emit('history', $event)"
                        @thread="$emit('thread', $event)"
                        @json="$emit('json', $event)"
                        @lightbox="$emit('lightbox', $event)"
                        @delete="$emit('delete', $event)"
                        @edit="$emit('edit', $event)"
                        @vote="$emit('vote', $event)"
                     ></inbox-note>
                </div>

                <!-- QUOTED NOTE DISPLAY -->
                <div v-if="note.quote" class="mt-4 pt-4 pl-4 ml-2 rounded-r-lg ap-quoted-note">
                    <div class="text-xs text-gray-500 mb-2 flex items-center gap-1 font-semibold uppercase tracking-wider">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z" />
                        </svg>
                        Quoting:
                    </div>
                    <inbox-note
                        :note="note.quote"
                        :permissions="permissions"
                        :actors="actors"
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
                    ></inbox-note>
                </div>

                <!-- Reply Editor Slot -->
                <slot name="reply-editor"></slot>

            </div>
         </div>
    </div>
</template>
<script>
export default {
    name: 'InboxNote',
    // We must manually register the component for recursion in some environments (though 'name' usually works)
    // To be safe, let's try the name-based recursion which is standard.
    // However, in Statamic CP, components are often global. 
    // If recursion fails, we might need to locally register it, but we can't import SFC easily in this setup without build step support verifying circular deps.
    // The safest "Vue 2" way without imports is relying on global registration or `name`.
    // I will assume `name: 'ActivityPubNote'` is correct.
    props: {
        note: {
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
        storeNoteUrl: {
            type: String,
            default: null
        }
    },
    computed: {
        canEdit() {
            if (!this.note) return false;
            
            const canUpdate = this.permissions && this.permissions.update;
            const actorMatch = this.actors && this.actors.some(a => 
                (a.id && a.id === this.note.actor.id) || 
                (a.url && a.url === this.note.actor.id) || 
                (a.url && a.url === this.note.actor.url)
            );
            
            return canUpdate && actorMatch;
        }
    },
    data() {
        return {
            // Internal state moved to dynamic components
        }
    },

    methods: {
        // Core methods kept, type-specific ones removed
    }

}
</script>

<style>
.prose a span.invisible {
    width: 0;
    display: inline-block;
    overflow: hidden;
    vertical-align: bottom;
}

.prose a span.ellipsis::after {
    content: '...';
}

.delete-btn:hover {
    color: #dc2626 !important;
}

button.delete-btn:hover {
    color: #dc2626 !important;
}

/* Hide quote citation line (RE: link) since the actual quoted note is displayed below */
.prose .quote-inline {
    display: none;
}
/* Note Container */
.ap-note-container:hover {
    background-color: #f9fafb; /* gray-50 */
}

html.dark .ap-note-container:hover,
html.is-dark .ap-note-container:hover,
html.isdark .ap-note-container:hover {
    background-color: #1a1a1a; /* slightly lighter than neutral-900 */
}

/* Parent/Quoted Note backgrounds */
.ap-parent-note {
    background-color: rgba(249, 250, 251, 0.5); /* gray-50/50 */
    border-left: 4px solid #d1d5db; /* gray-300 */
}
html.dark .ap-parent-note,
html.is-dark .ap-parent-note,
html.isdark .ap-parent-note {
    background-color: rgba(23, 23, 23, 0.3); /* neutral-900/30 */
    border-left-color: #262626; /* neutral-800 */
}

.ap-quoted-note {
    background-color: rgba(250, 245, 255, 0.5); /* purple-50/50 */
    border-left: 4px solid #d8b4fe; /* purple-300 */
}
html.dark .ap-quoted-note,
html.is-dark .ap-quoted-note,
html.isdark .ap-quoted-note {
    background-color: rgba(88, 28, 135, 0.1); /* purple-900/10 */
    border-left-color: #9333ea; /* purple-600 */
}

/* Link Preview */
.ap-link-preview {
    border: 1px solid var(--theme-color-content-border, #e5e7eb);
    background-color: transparent;
}
.ap-link-preview:hover {
    background-color: var(--theme-color-gray-100, #f3f4f6);
}
html.dark .ap-link-preview,
html.is-dark .ap-link-preview,
html.isdark .ap-link-preview {
    border-color: var(--theme-color-gray-900, #19292f);
}
html.dark .ap-link-preview:hover,
html.is-dark .ap-link-preview:hover,
html.isdark .ap-link-preview:hover {
    background-color: var(--theme-color-gray-850, #1c2e36) !important;
}

.ap-link-preview-image {
    background-color: var(--theme-color-gray-100, #f3f4f6);
}
html.dark .ap-link-preview-image,
html.is-dark .ap-link-preview-image,
html.isdark .ap-link-preview-image {
    background-color: var(--theme-color-gray-950, #141a1f);
}
</style>
