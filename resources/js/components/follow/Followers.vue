<template>
    <div>
        <followers-title />

        <div v-if="loading" class="loading loading-basic">
            <span class="icon icon-circular-graph animation-spin"></span> Loading...
        </div>

        <div v-else>
            <div v-for="localActor in myActors" :key="localActor.id">
                <following-list 
                    :title="localActor.title + '\'s Followers'"
                    :actors="getFollowersFor(localActor)"
                    empty-text="No followers found on this page."
                >
                    <template #actions="{ actor }">
                        <!-- Follow Back / Unfollow -->
                        <button 
                            v-if="isFollowing(localActor, actor.id)" 
                            class="btn-xs btn-default" 
                            @click="performAction('unfollow', actor.id, localActor.id)"
                        >Unfollow</button>
                        <button 
                            v-else 
                            class="btn-xs btn-primary" 
                            @click="performAction('follow', actor.id, localActor.id)"
                        >Follow Back</button>

                        <!-- Block / Unblock -->
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
import FollowersTitle from './FollowersTitle.vue';
import FollowingList from './FollowingList.vue';

export default {
    components: {
        FollowersTitle,
        FollowingList
    },
    data() {
         return {
            loading: true,
            myActors: [],
            actors: [],
            links: {},
            meta: {},
            endpoint: '/cp/activitypub/followers/api'
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
                    this.links = response.data.actors.links || {
                        prev: response.data.actors.prev_page_url,
                        next: response.data.actors.next_page_url
                    };
                    this.meta = response.data.actors.meta || {
                        current_page: response.data.actors.current_page,
                        last_page: response.data.actors.last_page,
                        from: response.data.actors.from,
                        to: response.data.actors.to,
                        total: response.data.actors.total
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
        getFollowersFor(localActor) {
            const followerIds = localActor.follower_ids || [];
            return this.actors.filter(actor => followerIds.includes(actor.id));
        },
        isFollowing(localActor, targetId) {
             const followingIds = localActor.following_ids || [];
             return followingIds.includes(targetId);
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
                this.fetchData(); // Reload
            }).catch(error => {
                Statamic.$toast.error('Action failed: ' + (error.response?.data?.error || error.message));
            });
        }
    }
}
</script>
