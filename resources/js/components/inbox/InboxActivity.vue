<template>
    <div :class="isMajor ? 'hover:bg-gray-50 dark:hover:bg-gray-900 transition-colors p-4' : 'px-4 py-2 hover:bg-gray-50 dark:hover:bg-gray-900 transition-colors border-l-4 border-gray-100 dark:border-gray-800 bg-gray-50/30 dark:bg-gray-900/10'">
        <div class="flex gap-4 items-center" :class="{ 'items-start': isMajor }">
            <!-- Avatar for Major, Icon for Minor -->
            <div class="flex-shrink-0">
                <template v-if="isMajor">
                    <img :src="activity.actor.avatar || 'https://www.gravatar.com/avatar/?d=mp'" loading="lazy" class="w-10 h-10 rounded-full bg-gray-200 object-cover">
                </template>
                <div v-else class="w-8 h-8 rounded-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 flex items-center justify-center text-gray-500">
                    <div v-if="activity.activity_type === 'Like'" class="text-yellow-500">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" /></svg>
                    </div>
                    <div v-else-if="activity.activity_type === 'Follow'" class="text-blue-500">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" /></svg>
                    </div>
                    <div v-else-if="activity.activity_type === 'Announce'" class="text-green-600">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                    </div>
                    <div v-else>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    </div>
                </div>
            </div>

            <div class="flex-1 min-w-0">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex-1 min-w-0" :class="isMajor ? 'text-base' : 'text-xs'">
                        <span class="font-bold mr-1" :class="isMajor ? 'text-gray-900 dark:text-gray-100' : 'text-gray-700 dark:text-gray-300'">{{ activity.actor.name }}</span>
                        
                        <template v-if="!isMajor">
                            <span class="text-gray-500 dark:text-gray-400 lowercase mr-1">{{ activityVerb }}</span>
                            <span v-if="activity.object_summary" class="text-gray-400 italic" :title="activity.object_summary">
                                &ldquo;{{ activity.object_summary }}&rdquo;
                            </span>
                        </template>

                        <span v-if="isMajor" class="text-sm text-gray-500 truncate" :title="activity.actor.handle">{{ activity.actor.handle }}</span>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <span class="text-xs text-gray-400 whitespace-nowrap" :title="activity.date">{{ activity.date_human }}</span>
                        <button v-if="permissions.delete" @click="$emit('delete', activity)" class="text-gray-400 hover:text-red-600 transition-colors" title="Delete">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Major Content -->
                <div v-if="isMajor" class="mt-2">
                    <!-- Fallback if no specialized component -->
                    <div v-if="activity.content" class="mt-2 mb-3 prose dark:prose-invert text-sm max-w-none break-words" v-html="activity.content"></div>

                    <!-- Specialized Activity Presentation Hook (e.g. Poll UI) -->
                    <activity-pub-hook-loader 
                        :name="`inbox-activity-${activity.activity_type}`" 
                        :props="{ note: activity, activity, permissions, actors }"
                        @vote="$emit('vote', $event)"
                        @json="$emit('json', $event)"
                    />
                    
                    <!-- Activity Actions Bar -->
                    <div class="mt-4 flex items-center gap-4 text-gray-500 border-t border-gray-100 dark:border-gray-800 pt-3">
                        <button class="flex items-center gap-1 hover:text-gray-900 dark:hover:text-white transition-colors" @click="$emit('reply', activity)" title="Reply">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6" />
                            </svg>
                        </button>
                        <button class="flex items-center gap-1 transition-colors" :class="activity.boosted_by_user ? 'text-green-600' : 'hover:text-gray-900 dark:hover:text-white'" @click="$emit('boost', activity)" title="Boost">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                        </button>
                        <button class="flex items-center gap-1 hover:text-gray-900 dark:hover:text-white transition-colors" @click="$emit('quote', activity)" title="Quote">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z" /></svg>
                        </button>
                        <button class="flex items-center gap-1 transition-colors" :class="activity.liked_by_user ? 'text-yellow-500' : 'hover:text-gray-900 dark:hover:text-white'" @click="$emit('like', activity)" title="Like">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" :fill="activity.liked_by_user ? 'currentColor' : 'none'" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" /></svg>
                        </button>
                        <button v-if="activity.related_activity_count > 0 || activity.related_activity_count" class="flex items-center gap-1 text-gray-500 hover:text-gray-900 dark:hover:text-white transition-colors" @click="$emit('history', activity)" title="View History">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </button>
                        <button class="flex items-center gap-1 text-gray-500 hover:text-gray-900 dark:hover:text-white transition-colors" @click="$emit('thread', activity)" title="View Thread">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="currentColor" viewBox="0 0 492.43313 470.25731">
                             <g transform="matrix(0.75674108,0,0,0.70536422,57.817645,55.147289)">
                                <path d="m 16.695312,-2.5546875 c -10.7064892,0 -19.2499995,8.5435103 -19.2499995,19.2499995 v 38.957032 c 0,29.137041 23.5058235,52.640626 52.6425785,52.640626 H 64.228516 v 295.421874 H 50.087891 c -29.136755,0 -52.6425785,23.50358 -52.6425785,52.64063 v 38.95703 c 0,10.7065 8.5434901,19.25 19.2499995,19.25 H 384 c 10.71306,0 19.25,-8.53506 19.25,-19.25 v -38.95703 c 0,-29.13649 -23.50414,-52.64063 -52.64063,-52.64063 H 336.4668 v -28.2832 h 30.83789 c 38.29372,10e-6 69.33789,-31.04205 69.33789,-69.33594 v -66.7832 c 0,-17.11722 13.71869,-30.83789 30.83594,-30.83789 h 27.82617 c 10.71308,0 19.25,-8.53502 19.25,-19.25 0,-10.7065 -8.54352,-19.25 -19.25,-19.25 h -27.82617 c -38.29404,0 -69.33789,31.04386 -69.3379,69.33789 v 66.7832 c 0,17.11687 -13.71907,30.83594 -30.83593,30.83594 H 336.4668 V 108.29297 h 14.14257 c 29.13649,0 52.64063,-23.504139 52.64063,-52.640626 V 16.695312 C 403.25,5.9803688 394.71306,-2.5546875 384,-2.5546875 Z m 19.25,38.4999995 H 364.75 v 19.707032 c 0,7.961761 -6.18114,14.140625 -14.14258,14.140625 H 50.087891 c -7.961438,0 -14.142579,-6.178863 -14.142579,-14.140625 z M 102.72852,108.29297 H 297.9668 v 28.2832 H 102.72852 Z m 0,66.7832 H 297.9668 v 28.28125 H 102.72852 Z m 0,66.7832 H 297.9668 v 28.28125 H 102.72852 Z m 0,66.78321 H 297.9668 v 28.28125 H 102.72852 Z m 0,66.78125 H 297.9668 v 28.2832 H 102.72852 Z m -52.640629,66.7832 H 350.60937 c 7.96039,0 14.14063,6.18025 14.14063,14.14063 v 19.70703 H 35.945312 v -19.70703 c 0,-7.96177 6.181139,-14.14063 14.142579,-14.14063 z" />
                             </g>
                        </svg>
                        </button>
                        <button v-if="activity.activitypub_json" class="flex items-center gap-1 hover:text-gray-900 dark:hover:text-white transition-colors" @click="$emit('json', activity)" title="View JSON">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" /></svg>
                        </button>
                    </div>
                    
                    <slot name="reply-editor"></slot>
                </div>

            </div>
            
            <!-- Context Action for Minor (e.g., View JSON) -->
            <div v-if="!isMajor" class="flex-shrink-0">
                <button v-if="activity.activitypub_json" @click="$emit('json', activity)" class="text-gray-300 hover:text-gray-600 dark:hover:text-gray-400 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" /></svg>
                </button>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    name: 'InboxActivity',
    props: {
        activity: {
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
            default: () => ({})
        },
        sendingReply: {
            type: Boolean,
            default: false
        }
    },
    computed: {
        isMajor() {
            // Activities that deserve a full card
            return ['Arrive', 'Travel', 'Offer', 'Listen', 'Question'].includes(this.activity.activity_type);
        },
        activityVerb() {
            const type = this.activity.object_type ? this.activity.object_type.toLowerCase() : 'post';
            
            // Check if the object belongs to one of our local actors
            const isLocalObject = this.actors.some(actor => {
                return actor.id === this.activity.object_actor_id || 
                       actor.activitypub_id === this.activity.object_actor_id ||
                       actor.url === this.activity.object_actor_id;
            });
            
            const possessive = isLocalObject ? 'your' : 'an';

            const verbs = {
                'Like': `liked ${possessive} ${type}`,
                'Follow': 'followed you',
                'Announce': `boosted ${possessive} ${type}`,
                'Undo': 'undid an action',
                'Delete': `deleted a ${type}`,
                'Update': `updated their ${type}`,
                'Create': `created a ${type}`
            };
            return verbs[this.activity.activity_type] || `performed a ${this.activity.activity_type} activity`;
        }
    }
}
</script>
