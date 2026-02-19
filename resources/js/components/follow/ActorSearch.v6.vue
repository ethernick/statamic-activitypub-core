<template>
    <div class="@container/panel relative bg-gray-150 dark:bg-gray-950/35 dark:inset-shadow-2xs dark:inset-shadow-black w-full rounded-2xl mb-8 max-[600px]:p-1.25 p-1.75 [&:has(>[data-ui-panel-header])]:pt-0 focus-none starting-style-transition">
        <header class="px-4 py-2">
            <span class="font-bold">Find People to Follow</span>
        </header>
        <div class="h-auto visible transition-[height,visibility] duration-[250ms,2s]">
            <div class="bg-white dark:bg-gray-850 rounded-xl ring ring-gray-200 dark:ring-x-0 dark:ring-b-0 dark:ring-gray-700/80 shadow-ui-md px-4 sm:px-4.5 py-5 space-y-6">

                <div class="flex gap-2">
                    <input 
                        type="text" 
                        v-model="handle" 
                        class="input-text flex-1" 
                        placeholder="nick@whoisnick.com"
                        @keyup.enter="search"
                    >
                    <button @click="search" class="relative inline-flex items-center justify-center whitespace-nowrap shrink-0 font-medium antialiased cursor-pointer no-underline disabled:[&_svg]:opacity-30 disabled:cursor-not-allowed [&_svg]:shrink-0 dark:[&_svg]:text-white bg-linear-to-b from-primary/90 to-primary hover:bg-primary-hover text-white disabled:opacity-60 disabled:text-white dark:disabled:text-white border border-primary-border shadow-ui-md inset-shadow-2xs inset-shadow-white/25 disabled:inset-shadow-none dark:disabled:inset-shadow-none [&_svg]:text-white [&_svg]:opacity-60 px-4 h-10 text-sm gap-2 rounded-lg" :disabled="loading">
                        {{ loading ? 'Searching...' : 'Search' }}
                    </button>
                </div>
        
                <div v-if="result || error || loading" class="mt-4">
                    <div v-if="loading" class="loading loading-basic">
                        <span class="icon icon-circular-graph animation-spin"></span> Searching...
                    </div>
                    
                    <div v-else-if="error" class="text-red-500">{{ error }}</div>
                    
                    <div v-else-if="result" class="flex items-center justify-between border p-3 rounded bg-gray-50 dark:bg-gray-800 dark:border-gray-700">
                        <div class="flex items-center gap-3">
                            <img v-if="result.avatar" :src="result.avatar" class="w-10 h-10 rounded-full object-cover">
                            <div v-else class="w-10 h-10 rounded-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center text-gray-500 font-bold text-lg">
                                {{ result.name ? result.name.charAt(0) : '?' }}
                            </div>
                            <div>
                                <div class="font-bold text-lg">{{ result.name }}</div>
                                <div class="text-sm text-gray-500 mb-1">{{ result.activitypub_id }}</div>
                                
                                <span v-if="result.is_following" class="text-green-600 flex items-center gap-1 text-sm">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg> Following
                                </span>
                                <span v-else-if="result.is_pending" class="text-yellow-600 flex items-center gap-1 text-sm">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd" /></svg> Pending
                                </span>
                            </div>
                        </div>
                        <div>
                            <button 
                                v-if="!result.is_following && !result.is_pending" 
                                @click="follow(result.id)" 
                                class="btn"
                                :disabled="followLoading"
                            >
                                {{ followLoading ? 'Following...' : 'Follow' }}
                            </button>
                            
                            <div v-if="followSuccess" class="flex flex-col items-center animate-pulse text-green-600">
                                <span class="flex items-center gap-1 font-bold">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
                                    Request Sent!
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    data() {
        return {
            handle: '',
            loading: false,
            error: null,
            result: null,
            followLoading: false,
            followSuccess: false
        }
    },
    methods: {
        search() {
            if (!this.handle) return;
            
            this.loading = true;
            this.error = null;
            this.result = null;
            this.followSuccess = false;

            this.$axios.post('/cp/activitypub/search', { handle: this.handle })
                .then(response => {
                    this.result = response.data;
                })
                .catch(error => {
                    this.error = error.response?.data?.error || error.message;
                })
                .finally(() => {
                    this.loading = false;
                });
        },
        follow(id) {
            this.followLoading = true;
            
            this.$axios.post('/cp/activitypub/follow', { id })
                .then(response => {
                    this.followSuccess = true;
                    this.result.is_pending = true; // Optimistic update
                    setTimeout(() => {
                         this.$emit('followed');
                         // Maybe reload page or refetch list?
                    }, 2000);
                })
                .catch(error => {
                    Statamic.$toast.error('Follow failed: ' + (error.response?.data?.error || error.message));
                })
                .finally(() => {
                    this.followLoading = false;
                });
        }
    }
}
</script>
