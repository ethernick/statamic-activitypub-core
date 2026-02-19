<template>
    <div>
        <following-title />

        <actor-search @followed="fetchData" />

        <div v-if="loading" class="loading loading-basic">
            <span class="icon icon-circular-graph animation-spin"></span> Loading...
        </div>

        <div v-else>
            <div v-for="localActor in myActors" :key="localActor.id">
                <following-list 
                    :title="localActor.title + '\'s Following'"
                    :actors="getFollowingFor(localActor)"
                    empty-text="Not following anyone (on this page)."
                >
                    <template #actions="{ actor }">
                        <button class="btn-xs btn-default" @click="performAction('unfollow', actor.id, localActor.id)">Unfollow</button>
                        
                        <button 
                            v-if="isBlocking(localActor, actor.id)" 
                            class="btn-xs btn-danger" 
                            @click="performAction('unblock', actor.id, localActor.id)"
                        >Unblock</button>
                        <button 
                            v-else 
                            class="btn-xs" 
                            @click="performAction('block', actor.id, localActor.id)"
                        >Block</button>
                    </template>
                </following-list>
            </div>
            
            <!-- Simple Pagination -->
             <div class="flex justify-between items-center mt-4" v-if="meta && meta.last_page > 1">
                <button :disabled="!links.prev" @click="fetchPage(links.prev)" class="btn" :class="{'opacity-50': !links.prev}">Previous</button>
                <span class="text-sm text-gray-600">Page {{ meta.current_page }} of {{ meta.last_page }}</span>
                <button :disabled="!links.next" @click="fetchPage(links.next)" class="btn" :class="{'opacity-50': !links.next}">Next</button>
            </div>
        </div>
    </div>
</template>

<script>
import FollowingTitle from './FollowingTitle.vue';
import FollowingList from './FollowingList.vue';
import ActorSearch from './ActorSearch.vue';

export default {
    components: {
        FollowingTitle,
        FollowingList,
        ActorSearch
    },
    data() {
        return {
            loading: true,
            myActors: [],
            actors: [], // List of fetched actors
            links: {},
            meta: {},
            endpoint: '/cp/activitypub/following/api'
        }
    },
    mounted() {
        this.fetchData();
    },
    methods: {
        fetchData(url = null) {
            this.loading = true;
            const targetUrl = url || this.endpoint;
            
            this.$axios.get(targetUrl)
                .then(response => {
                    this.actors = response.data.actors.data;
                    this.myActors = response.data.myActors;
                    
                    const paginator = response.data.actors;
                    this.meta = {
                        current_page: paginator.current_page,
                        last_page: paginator.last_page,
                        from: paginator.from,
                        to: paginator.to,
                        total: paginator.total
                    };
                    this.links = {
                        prev: paginator.prev_page_url,
                        next: paginator.next_page_url
                    };
                })
                .catch(error => {
                    Statamic.$toast.error(error.message);
                })
                .finally(() => {
                    this.loading = false;
                });
        },
        fetchPage(url) {
            if (url) this.fetchData(url);
        },
        getFollowingFor(localActor) {
            // Filter actors that are in localActor's following list
            // API returns actors enriched with details, and myActors with following_ids
            const followingIds = localActor.following_ids || [];
            return this.actors.filter(actor => followingIds.includes(actor.id));
        },
        isBlocking(localActor, targetId) {
            const blockIds = localActor.block_ids || [];
            return blockIds.includes(targetId);
        },
        performAction(action, targetId, senderId) {
             if (action === 'unfollow' && !confirm('Are you sure you want to unfollow this actor?')) return;
             if (action === 'block' && !confirm('Are you sure you want to block this actor?')) return;

            this.$axios.post('/cp/activitypub/' + action, {
                id: targetId,
                sender: senderId
            }).then(response => {
                Statamic.$toast.success('Action ' + action + ' successful');
                this.fetchData(); // Reload data to reflect changes
            }).catch(error => {
                Statamic.$toast.error('Action failed: ' + (error.response?.data?.error || error.message));
            });
        }
    }
}
</script>
