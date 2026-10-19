<template>
    <div class="activity-pub-post-card-wrapper">
        <!-- Minor Activity (Like, Announce, Follow) - Compact format -->
        <div v-if="!entry.is_major" class="px-4 py-2.5 flex items-center gap-3 transition-colors hover:bg-gray-50/80 dark:hover:bg-gray-800/40">
            <div class="w-7 h-7 rounded-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 flex items-center justify-center text-gray-500 shrink-0" style="width: 28px; height: 28px; min-width: 28px; min-height: 28px;">
                <svg v-if="entry.activity_type === 'Like'" class="w-3.5 h-3.5 text-yellow-500 fill-current" viewBox="0 0 24 24"><path d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" /></svg>
                <svg v-else-if="entry.activity_type === 'Announce'" class="w-3.5 h-3.5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                <svg v-else class="w-3.5 h-3.5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            </div>
            <div class="flex-1 min-w-0 text-xs flex items-baseline justify-between gap-2">
                <div class="min-w-0 truncate">
                    <span 
                        class="font-semibold text-gray-800 dark:text-gray-200 mr-1 cursor-pointer hover:underline" 
                        @click="filterByAuthor(entry.actor)"
                        :title="entry.actor?.handle ? `Filter by ${entry.actor.handle}` : 'Filter by author'"
                    >{{ entry.actor?.name || entry.actor_name || 'ActivityPub User' }}</span>
                    <span class="text-gray-500 dark:text-gray-400 lowercase mr-1">{{ getActivityVerb(entry) }}</span>
                    <span v-if="entry.object_summary || entry.title" class="text-gray-600 dark:text-gray-400 italic" :title="entry.object_summary || entry.title">
                        &ldquo;{{ entry.object_summary || entry.title }}&rdquo;
                    </span>
                </div>
                <span class="text-[11px] text-gray-400 dark:text-gray-500 shrink-0">{{ entry.date_human || entry.date }}</span>
            </div>
        </div>

        <!-- Major Activity (Note, Event, Check-in, Article, Question) - Card format -->
        <div v-else class="p-3 sm:p-4 flex gap-2.5 sm:gap-3.5 items-start transition-colors hover:bg-gray-50/80 dark:hover:bg-gray-800/40 min-w-0">
            <!-- Author Avatar: responsive 32px on mobile, 40px on desktop -->
            <img 
                :src="entry.actor?.avatar || 'https://www.gravatar.com/avatar/?d=mp'" 
                :alt="entry.actor?.name || 'Avatar'"
                loading="lazy" 
                class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-gray-200 dark:bg-gray-800 object-cover shrink-0 cursor-pointer hover:opacity-90 transition-opacity"
                @click="filterByAuthor(entry.actor)"
                :title="entry.actor?.handle ? `Filter by ${entry.actor.handle}` : 'Filter by author'"
            >

            <div class="flex-1 min-w-0">
                <!-- Header: Author Name, Handle, Badge Hook & Timestamp -->
                <div class="flex items-baseline justify-between gap-1.5 sm:gap-2">
                    <div class="flex items-baseline gap-1 sm:gap-1.5 min-w-0 flex-wrap">
                        <span 
                            class="font-bold text-xs sm:text-sm text-gray-900 dark:text-gray-100 truncate cursor-pointer hover:underline" 
                            @click="filterByAuthor(entry.actor)"
                            :title="entry.actor?.handle ? `Filter by ${entry.actor.handle}` : 'Filter by author'"
                        >{{ entry.actor?.name || entry.actor_name || 'ActivityPub User' }}</span>
                        <span 
                            v-if="entry.actor?.handle" 
                            class="text-[11px] sm:text-xs text-gray-500 dark:text-gray-400 truncate cursor-pointer hover:underline" 
                            @click="filterByAuthor(entry.actor)"
                            :title="`Filter by ${entry.actor.handle}`"
                        >{{ entry.actor.handle }}</span>
                        <span 
                            v-if="entry.actor?.actor_type && entry.actor.actor_type !== 'Person'" 
                            class="inline-flex items-center px-1.5 py-0.2 rounded text-[9px] sm:text-[10px] font-medium bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700 uppercase tracking-wider"
                        >
                            {{ entry.actor.actor_type === 'Service' ? '🤖 Bot' : (entry.actor.actor_type === 'Organization' ? '🏢 Org' : entry.actor.actor_type) }}
                        </span>
                        <activitypub-hook-loader name="post-card-badge" :props="{ item: entry, readonly, permissions, actors }" />
                    </div>
                    <span class="text-[11px] sm:text-xs text-gray-400 dark:text-gray-500 whitespace-nowrap shrink-0" :title="entry.date">{{ entry.date_human || entry.date }}</span>
                </div>

                <!-- Secondary Header Actions Hook (e.g. EventActionBar, location pills) or slot -->
                <slot name="secondary-actions" :item="entry">
                    <div class="mt-1">
                        <activitypub-hook-loader 
                            name="inbox-secondary-actions" 
                            :props="{ note: entry, item: entry, activity: entry, readonly, permissions, actors }"
                            @edit="$emit('edit', $event)"
                            @delete="$emit('delete', $event)"
                            @reply="$emit('reply', $event)"
                            @boost="$emit('boost', $event)"
                            @like="$emit('like', $event)"
                            @quote="$emit('quote', $event)"
                            @history="$emit('history', $event)"
                            @thread="$emit('thread', $event)"
                            @json="$emit('json', $event)"
                        />
                    </div>
                </slot>

                <!-- Title (Suppressed for Notes / micro-posts) or custom slot -->
                <slot name="title" :item="entry">
                    <h4 v-if="shouldShowTitle" class="text-sm font-bold text-gray-900 dark:text-gray-100 mt-1">
                        {{ entry.title }}
                    </h4>
                </slot>

                <!-- Body Content -->
                <div v-if="entry.content" class="mt-2 mb-1 text-sm text-gray-800 dark:text-gray-200 prose dark:prose-invert max-w-none break-words leading-relaxed overflow-hidden" v-html="entry.content"></div>
                <div v-else-if="entry.object_summary" class="mt-2 mb-1 text-sm text-gray-600 dark:text-gray-400 italic">
                    &ldquo;{{ entry.object_summary }}&rdquo;
                </div>

                <!-- Rich Embed Hooks (e.g. Poll interactive widget, Article reader, Check-in card, Map preview) -->
                <activitypub-hook-loader 
                    name="post-card-body-extra" 
                    :props="{ note: entry, item: entry, activity: entry, readonly, permissions, actors }"
                    @vote="$emit('vote', $event)"
                />
                <activitypub-hook-loader 
                    v-if="activityTypeHookName"
                    :name="activityTypeHookName" 
                    :props="{ note: entry, item: entry, activity: entry, readonly, permissions, actors }"
                    @vote="$emit('vote', $event)"
                />
                <activitypub-hook-loader 
                    name="inbox-note-content" 
                    :props="{ note: entry, item: entry, activity: entry, readonly, permissions, actors }"
                />

                <slot name="after-body" :item="entry" />

                <!-- Media Attachments (Controlled aspect ratio and mobile-friendly grid) -->
                <div v-if="entry.attachments && entry.attachments.length > 0" 
                     class="mt-3 grid gap-2"
                     :class="entry.attachments.length === 1 ? 'grid-cols-1' : (entry.attachments.length === 2 ? 'grid-cols-2' : 'grid-cols-2 sm:grid-cols-3')"
                >
                    <div v-for="(att, idx) in entry.attachments.slice(0, 3)" :key="idx" 
                         class="relative cursor-pointer group overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-800"
                         :class="entry.attachments.length === 1 ? 'max-h-72 sm:max-h-96' : 'aspect-square sm:aspect-video max-h-48'"
                         @click="$emit('lightbox', { attachments: entry.attachments, index: idx })">
                        <img :src="att.url || att" :alt="att.description || 'Media'" class="w-full h-full object-cover transition-transform group-hover:scale-[1.02]" loading="lazy">
                    </div>
                </div>

                <!-- Meta & Extensible Addon Hook (e.g. Map directions, event schedule) or slot -->
                <div class="mt-2">
                    <slot name="meta" :item="entry">
                        <activitypub-hook-loader name="post-card-meta" :props="{ note: entry, item: entry, readonly, permissions, actors }" />
                    </slot>
                </div>

                <!-- Custom Action Bar Slot or Interactive Social Action Bar (Fluid mobile-friendly distribution) -->
                <div v-if="$slots.actions || !readonly" class="mt-3">
                    <slot name="actions" :item="entry">
                        <div class="flex items-center justify-between sm:justify-start sm:gap-6 text-sm text-gray-500 dark:text-gray-400 pt-1">
                            <button class="flex items-center gap-1 text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100 transition-colors p-1 -m-1" @click="$emit('reply', entry)" title="Reply">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6" /></svg>
                            </button>
                            <button class="flex items-center gap-1 transition-colors p-1 -m-1" :class="entry.boosted_by_user ? 'text-green-600' : 'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100'" @click="$emit('boost', entry)" title="Boost">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                            </button>
                            <button class="flex items-center gap-1 text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100 transition-colors p-1 -m-1" @click="$emit('quote', entry)" title="Quote">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z" /></svg>
                            </button>
                            <button class="flex items-center gap-1 transition-colors p-1 -m-1" :class="entry.liked_by_user ? 'text-yellow-500' : 'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100'" @click="$emit('like', entry)" title="Like">
                                <svg class="h-4 w-4" :fill="entry.liked_by_user ? 'currentColor' : 'none'" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" /></svg>
                                <span v-if="entry.counts?.likes" class="text-xs">{{ entry.counts.likes }}</span>
                            </button>
                            <activitypub-hook-loader name="post-card-actions" :props="{ note: entry, item: entry, readonly, permissions, actors }" />
                        </div>
                    </slot>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    name: 'ActivityPubPostCard',
    props: {
        item: {
            type: Object,
            default: null
        },
        note: {
            type: Object,
            default: null
        },
        readonly: {
            type: Boolean,
            default: false
        },
        permissions: {
            type: Object,
            default: () => ({ update: false, delete: false })
        },
        actors: {
            type: Array,
            default: () => []
        }
    },
    computed: {
        entry() {
            const raw = this.item || this.note || {};
            return {
                is_major: raw.is_major !== undefined ? Boolean(raw.is_major) : true,
                ...raw
            };
        },
        shouldShowTitle() {
            const type = this.entry.type || this.entry.object_type;
            if (type === 'note' || type === 'Note') return false;
            return Boolean(this.entry.title && this.entry.title !== this.entry.content);
        },
        activityTypeHookName() {
            const rawType = this.entry.activity_type || this.entry.object_type || this.entry.type;
            if (!rawType) return null;
            const type = rawType.charAt(0).toUpperCase() + rawType.slice(1);
            return `inbox-activity-${type}`;
        }
    },
    methods: {
        getActivityVerb(activity) {
            switch (activity.activity_type) {
                case 'Like': return 'liked';
                case 'Announce': return 'boosted';
                case 'Follow': return 'followed';
                case 'Create': return 'posted';
                case 'Update': return 'updated';
                case 'Delete': return 'deleted';
                case 'Undo': return 'undid an interaction';
                case 'Arrive': return 'checked in at';
                default: return activity.activity_type ? activity.activity_type.toLowerCase() : 'interacted with';
            }
        },
        filterByAuthor(actor) {
            const handle = actor?.handle || actor?.username || actor?.name;
            if (!handle) return;
            const query = handle.startsWith('@') ? handle : `@${handle}`;
            if (typeof Statamic !== 'undefined' && Statamic.$activitypub?.bus) {
                Statamic.$activitypub.bus.emit('activitypub:inbox:filter', query);
            }
        }
    }
}
</script>
