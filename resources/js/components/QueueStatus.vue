<template>
    <div>
        <!-- Stats Layout -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <div class="card p-6 flex flex-col items-center justify-center">
                <span class="text-3xl font-bold bg-clip-text" :class="failedCount > 0 ? 'text-red-500' : 'text-green-500'">{{ failedCount }}</span>
                <span class="text-sm text-gray-600 mt-2 uppercase tracking-wider">Failed Jobs</span>
            </div>
            <div class="card p-6 flex flex-col items-center justify-center">
                <span class="text-3xl font-bold text-blue-500">{{ pendingCount }}</span>
                <span class="text-sm text-gray-600 mt-2 uppercase tracking-wider">Pending Jobs</span>
            </div>
        </div>

        <div class="mb-4 flex space-x-4">
            <button @click="activeTab = 'pending'" :class="['btn', activeTab === 'pending' ? 'btn-primary' : '']">Pending</button>
            <button @click="activeTab = 'failed'" :class="['btn', activeTab === 'failed' ? 'btn-primary' : '']">Failed</button>
        </div>

        <!-- Pending Tab -->
        <div v-show="activeTab === 'pending'" class="card p-0">
            <div class="p-6 border-b flex justify-between items-center">
                <h2 class="text-lg font-bold">Pending Jobs</h2>
                
                <div class="flex space-x-2 items-center">
                    <select v-model="pendingFilter" class="input-text w-48">
                        <option value="">All</option>
                        <option v-for="type in uniquePendingTypes" :key="type" :value="type">{{ type }}</option>
                    </select>

                    <button v-if="pendingFilter" @click="flushPendingByType" class="btn btn-danger" :title="'Flush all ' + pendingFilter">
                        Flush {{ pendingFilter.length > 20 ? pendingFilter.substring(0,20)+'...' : pendingFilter }}
                    </button>
                </div>
            </div>

            <div v-if="loadingPending" class="p-6 text-center text-gray-500">Loading pending jobs...</div>
            <div v-else-if="pendingJobs.length === 0" class="p-6 text-center text-gray-500">No pending jobs.</div>
            <table v-else class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Queue</th>
                        <th>Job Type</th>
                        <th>Display Name</th>
                        <th>Attempts</th>
                        <th>Created At</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="job in filteredPendingJobs" :key="job.id">
                        <td>{{ job.id }}</td>
                        <td>{{ job.queue }}</td>
                        <td class="font-mono text-xs text-gray-700 dark:text-gray-300">{{ job.parsed_name }}</td>
                        <td class="text-sm font-semibold">{{ job.display_name }}</td>
                        <td>{{ job.attempts }}</td>
                        <td>{{ formatTime(job.created_at) }}</td>
                        <td class="text-right flex items-center justify-end space-x-3">
                            <button @click="viewPayload(job)" class="text-blue-500 hover:text-blue-700">View</button>
                            <button @click="deletePending(job.id)" class="text-red-500 hover:text-red-700">Delete</button>
                        </td>
                    </tr>
                </tbody>
            </table>
            
            <div v-if="pendingPagination.last_page > 1" class="p-4 border-t flex justify-end">
                <button @click="fetchPending(pendingPagination.current_page - 1)" :disabled="pendingPagination.current_page <= 1" class="btn mx-1">&lt;</button>
                <span class="py-1 px-3">Page {{ pendingPagination.current_page }} of {{ pendingPagination.last_page }}</span>
                <button @click="fetchPending(pendingPagination.current_page + 1)" :disabled="pendingPagination.current_page >= pendingPagination.last_page" class="btn mx-1">&gt;</button>
            </div>
        </div>

        <!-- Failed Tab -->
        <div v-show="activeTab === 'failed'" class="card p-0">
            <div class="p-6 border-b flex justify-between items-center">
                <h2 class="text-lg font-bold">Failed Jobs</h2>
                <button v-if="failedJobs.length > 0" @click="flushFailed" class="btn btn-danger">Flush All Failed</button>
            </div>

            <div v-if="loadingFailed" class="p-6 text-center text-gray-500">Loading failed jobs...</div>
            <div v-else-if="failedJobs.length === 0" class="p-6 text-center text-gray-500">No failed jobs. Enjoy the peace!</div>
            <table v-else class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Queue</th>
                        <th>Job Type</th>
                        <th>Display Name</th>
                        <th>Connection</th>
                        <th>Failed At</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="job in failedJobs" :key="job.uuid">
                        <td>{{ job.id }}</td>
                        <td>{{ job.queue }}</td>
                        <td class="font-mono text-xs text-gray-700 dark:text-gray-300">{{ job.parsed_name }}</td>
                        <td class="text-sm font-semibold">{{ job.display_name }}</td>
                        <td>{{ job.connection }}</td>
                        <td>{{ job.failed_at }}</td>
                        <td class="text-right flex justify-end space-x-2">
                            <button @click="viewPayload(job)" class="btn btn-sm">View</button>
                            <button @click="retryFailed(job.uuid)" class="btn btn-sm">Retry</button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div v-if="failedPagination.last_page > 1" class="p-4 border-t flex justify-end">
                <button @click="fetchFailed(failedPagination.current_page - 1)" :disabled="failedPagination.current_page <= 1" class="btn mx-1">&lt;</button>
                <span class="py-1 px-3">Page {{ failedPagination.current_page }} of {{ failedPagination.last_page }}</span>
                <button @click="fetchFailed(failedPagination.current_page + 1)" :disabled="failedPagination.current_page >= failedPagination.last_page" class="btn mx-1">&gt;</button>
            </div>
        </div>

        <!-- Payload Modal -->
        <div v-if="showingPayload" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl w-3/4 max-w-4xl max-h-[90vh] flex flex-col">
                <div class="px-6 py-4 border-b dark:border-gray-700 flex justify-between items-center text-gray-900 dark:text-gray-100">
                    <h3 class="text-lg font-bold">Job Payload Details</h3>
                    <button @click="showingPayload = false" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">&times;</button>
                </div>
                <div class="p-6 overflow-y-auto flex-1 bg-gray-50 dark:bg-gray-900 border-b dark:border-gray-700">
                    <pre class="text-xs font-mono whitespace-pre-wrap text-gray-800 dark:text-gray-300">{{ formattedPayload }}</pre>
                </div>
                <div class="px-6 py-4 border-t dark:border-gray-700 bg-gray-100 dark:bg-gray-800 flex justify-end">
                    <button @click="showingPayload = false" class="btn">Close</button>
                </div>
            </div>
        </div>

    </div>
</template>

<script>
export default {
    data() {
        return {
            activeTab: 'pending',
            pendingCount: 0,
            failedCount: 0,
            
            pendingJobs: [],
            pendingPagination: {},
            loadingPending: false,
            pendingFilter: '',
            
            failedJobs: [],
            failedPagination: {},
            loadingFailed: false,
            
            showingPayload: false,
            selectedJobPayload: null,

            pollingInterval: null
        }
    },
    computed: {
        formattedPayload() {
            if (!this.selectedJobPayload) return '';
            try {
                // Parse it so we can re-stringify with pretty formatting
                const obj = JSON.parse(this.selectedJobPayload);
                return JSON.stringify(obj, null, 2);
            } catch (e) {
                return this.selectedJobPayload;
            }
        },
        uniquePendingTypes() {
            const types = new Set(this.pendingJobs.map(j => j.display_name));
            return Array.from(types).sort();
        },
        filteredPendingJobs() {
            if (!this.pendingFilter) return this.pendingJobs;
            return this.pendingJobs.filter(j => j.display_name === this.pendingFilter);
        }
    },
    mounted() {
        this.fetchStatus();
        this.fetchPending();
        this.fetchFailed();

        // Fallback or explicit Axios reference across Vue versions
        if (!this.$axios && typeof Statamic !== 'undefined' && Statamic.$axios) {
            this.$axios = Statamic.$axios;
        }

        // Poll counts every 20 seconds
        this.pollingInterval = setInterval(() => {
            this.fetchStatus();
            // Refresh current tab data
            if (this.activeTab === 'pending') {
                this.fetchPending(this.pendingPagination.current_page);
            } else {
                this.fetchFailed(this.failedPagination.current_page);
            }
        }, 20000);
    },
    beforeDestroy() {
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
        }
    },
    methods: {
        fetchStatus() {
            this.$axios.get(cp_url('activitypub/queue/status'))
                .then(response => {
                    this.pendingCount = response.data.pending_count;
                    this.failedCount = response.data.failed_count;
                })
                .catch(error => {
                    console.error("Failed to fetch queue status", error);
                });
        },
        fetchPending(page = 1) {
            this.loadingPending = true;
            this.$axios.get(cp_url('activitypub/queue/pending?page=' + page))
                .then(response => {
                    this.pendingJobs = response.data.data;
                    this.pendingPagination = response.data;
                })
                .finally(() => {
                    this.loadingPending = false;
                });
        },
        fetchFailed(page = 1) {
            this.loadingFailed = true;
            this.$axios.get(cp_url('activitypub/queue/failed?page=' + page))
                .then(response => {
                    this.failedJobs = response.data.data;
                    this.failedPagination = response.data;
                })
                .finally(() => {
                    this.loadingFailed = false;
                });
        },
        deletePending(id) {
            if (!confirm('Are you sure you want to delete this pending job?')) return;
            
            this.$axios.delete(cp_url('activitypub/queue/pending/' + id))
                .then(() => {
                    this.$toast.success('Job deleted');
                    this.fetchPending();
                    this.fetchStatus();
                });
        },
        flushPendingByType() {
            if (!this.pendingFilter) return;
            if (!confirm(`Are you sure you want to flush ALL pending jobs of type: ${this.pendingFilter}?`)) return;

            this.$axios.post(cp_url('activitypub/queue/pending/flush'), { type: this.pendingFilter })
                .then(response => {
                    this.$toast.success(response.data.message);
                    this.pendingFilter = '';
                    this.fetchPending();
                    this.fetchStatus();
                });
        },
        retryFailed(id) {
            this.$axios.post(cp_url('activitypub/queue/retry/' + id))
                .then(() => {
                    this.$toast.success('Job queued for retry');
                    this.fetchFailed();
                    this.fetchStatus();
                });
        },
        flushFailed() {
            if (!confirm('Are you sure you want to flush ALL failed jobs?')) return;

            this.$axios.post(cp_url('activitypub/queue/flush'))
                .then(() => {
                    this.$toast.success('All failed jobs flushed');
                    this.fetchFailed();
                    this.fetchStatus();
                });
        },
        formatTime(timestamp) {
            if (!timestamp) return '';
            const date = new Date(timestamp * 1000);
            return date.toLocaleString();
        },
        viewPayload(job) {
            this.selectedJobPayload = job.raw_payload;
            this.showingPayload = true;
        }
    }
}
</script>
