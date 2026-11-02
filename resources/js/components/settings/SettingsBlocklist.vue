<template>
    <div class="flex flex-col gap-6">
        <!-- Add Blocks Section -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Block Domain -->
            <settings-panel title="Block Domain">
                <template #header>
                    <div class="flex items-center justify-between mt-1">
                        <span class="text-xs text-gray-500">
                            Block an entire instance. 
                            <button 
                                type="button" 
                                @click="showBulkDomain = !showBulkDomain" 
                                class="text-gray-400 hover:text-gray-500 dark:text-grey-400 dark:hover:text-gray-200 underline font-medium ml-1"
                            >
                                {{ showBulkDomain ? 'Single input' : 'Bulk Import' }}
                            </button>
                        </span>
                    </div>
                </template>
                <div class="flex flex-col gap-3">
                    <!-- Single Input -->
                    <div v-if="!showBulkDomain" class="flex gap-2">
                        <input 
                            type="text" 
                            v-model="domainToBlock" 
                            class="input-text flex-1" 
                            placeholder="domain.example or https://domain.example" 
                            @keyup.enter="blockDomain"
                        >
                        <button @click="blockDomain" class="btn" :disabled="blockingDomain">
                            <span v-if="blockingDomain">Blocking...</span>
                            <span v-else>Block Domain</span>
                        </button>
                    </div>

                    <!-- Bulk Textarea -->
                    <div v-else class="flex flex-col gap-2">
                        <textarea 
                            v-model="bulkDomainsText" 
                            class="input-text w-full font-mono text-xs h-32" 
                            placeholder="spam-domain.social&#10;bad-instance.com&#10;trolls.network"
                        ></textarea>
                        <div class="flex justify-between items-center">
                            <span class="text-xs text-gray-400">One domain per line.</span>
                            <button @click="blockDomainBulk" class="btn" :disabled="blockingDomain || !bulkDomainsText.trim()">
                                <span v-if="blockingDomain">Importing...</span>
                                <span v-else>Import Domains</span>
                            </button>
                        </div>
                    </div>

                    <p class="text-xs text-gray-500">
                        All actors and activities originating from listed domains or their subdomains will be rejected.
                    </p>
                </div>
            </settings-panel>

            <!-- Block User -->
            <settings-panel title="Block User / Actor">
                <template #header>
                    <div class="flex items-center justify-between mt-1">
                        <span class="text-xs text-gray-500">
                            Block specific handles or URLs. 
                            <button 
                                type="button" 
                                @click="showBulkUser = !showBulkUser" 
                                class="text-gray-400 hover:text-gray-500 dark:text-grey-400 dark:hover:text-gray-200 underline font-medium ml-1"
                            >
                                {{ showBulkUser ? 'Single input' : 'Bulk Import' }}
                            </button>
                        </span>
                    </div>
                </template>
                <div class="flex flex-col gap-3">
                    <!-- Single Input -->
                    <div v-if="!showBulkUser" class="flex gap-2">
                        <input 
                            type="text" 
                            v-model="handleToBlock" 
                            class="input-text flex-1" 
                            placeholder="@user@domain.example" 
                            @keyup.enter="blockUser"
                        >
                        <button @click="blockUser" class="btn" :disabled="blockingUser">
                            <span v-if="blockingUser">Blocking...</span>
                            <span v-else>Block User</span>
                        </button>
                    </div>

                    <!-- Bulk Textarea -->
                    <div v-else class="flex flex-col gap-2">
                        <textarea 
                            v-model="bulkUsersText" 
                            class="input-text w-full font-mono text-xs h-32" 
                            placeholder="@spammer@domain.example&#10;https://remote.social/users/badactor"
                        ></textarea>
                        <div class="flex justify-between items-center">
                            <span class="text-xs text-gray-400">One handle or Actor URL per line.</span>
                            <button @click="blockUserBulk" class="btn" :disabled="blockingUser || !bulkUsersText.trim()">
                                <span v-if="blockingUser">Importing...</span>
                                <span v-else>Import Users</span>
                            </button>
                        </div>
                    </div>

                    <p class="text-xs text-gray-500">
                        Resolves handles via Webfinger and blocks canonical URLs alongside known aliases.
                    </p>
                </div>
            </settings-panel>
        </div>

        <!-- Blocklist Entries Table -->
        <settings-panel title="Active Blocklist" description="Manage individual blocked domains and user actors.">
            <template #actions>
                <div class="flex items-center gap-2">
                    <select v-model="filterType" @change="fetchBlocks(1)" class="input-text text-xs py-1.5 dark:bg-dark-700 dark:border-dark-600">
                        <option value="">All Types</option>
                        <option value="domain">Domains</option>
                        <option value="user">Users / URLs</option>
                    </select>
                    <div class="relative flex items-center">
                        <input 
                            type="text" 
                            v-model="searchQuery" 
                            @input="debounceFetchBlocks" 
                            class="input-text text-xs py-1.5 pl-3 pr-7 w-56 dark:bg-dark-700 dark:border-dark-600" 
                            placeholder="Filter identifiers..."
                        >
                        <button 
                            v-if="searchQuery" 
                            @click="clearSearch" 
                            class="absolute right-2 text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                            title="Clear search"
                        >
                            ✕
                        </button>
                    </div>
                </div>
            </template>

            <div class="card p-0 overflow-hidden">
                <table class="data-table" v-if="blocks.length > 0">
                    <thead>
                        <tr>
                            <th>Identifier</th>
                            <th class="w-32">Type</th>
                            <th class="w-44">Date Added</th>
                            <th class="w-16 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="entry in blocks" :key="entry.id">
                            <td class="font-mono text-xs break-all text-gray-800 dark:text-gray-200">
                                {{ entry.identifier }}
                            </td>
                            <td class="text-xs capitalize text-gray-600 dark:text-gray-400">
                                {{ entry.type }}
                            </td>
                            <td class="whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">
                                {{ formatDate(entry.created_at) }}
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <button 
                                    @click="deleteBlock(entry)" 
                                    class="text-gray-400 hover:text-gray-700 dark:hover:text-white transition-colors p-1"
                                    :title="'Unblock ' + entry.identifier"
                                    :disabled="deletingId === entry.id"
                                >
                                    <svg v-if="deletingId === entry.id" class="animate-spin h-4 w-4 text-gray-400 inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-else class="p-8 text-center text-gray-500 italic">
                    <span v-if="loadingBlocks">Loading blocklist...</span>
                    <span v-else>No blocked domains or users found.</span>
                </div>
            </div>

            <!-- Statamic Standard Pagination Controls -->
            <div v-if="pagination.total > 0" class="py-4 px-4 flex items-center justify-between border-t dark:border-dark-800">
                <div class="text-xs text-gray-500">
                    Showing {{ (pagination.current_page - 1) * pagination.per_page + 1 }} to {{ Math.min(pagination.current_page * pagination.per_page, pagination.total) }} of {{ pagination.total }} entries
                </div>
                <div class="flex gap-1 items-center">
                    <select :value="pagination.per_page" @change="changePerPage(parseInt($event.target.value))" class="btn btn-sm text-xs pr-6 mr-1">
                        <option :value="25">25 / page</option>
                        <option :value="50">50 / page</option>
                        <option :value="100">100 / page</option>
                    </select>

                    <!-- Prev Button -->
                    <button 
                        class="btn btn-sm" 
                        :disabled="pagination.current_page <= 1 || loadingBlocks" 
                        @click="fetchBlocks(pagination.current_page - 1)"
                        title="Previous page"
                    >
                        &larr;
                    </button>
                    
                    <!-- Page Number Buttons -->
                    <template v-for="(p, i) in paginationRange">
                        <span v-if="p === '...'" :key="'dots'+i" class="px-2 py-1 text-xs text-gray-400">...</span>
                        <button 
                            v-else 
                            :key="p" 
                            @click="fetchBlocks(p)"
                            class="btn btn-sm text-xs"
                            :class="{ 'bg-blue-600 border-blue-600 text-white font-bold': p === pagination.current_page }"
                            :disabled="loadingBlocks"
                        >
                            {{ p }}
                        </button>
                    </template>

                    <!-- Next Button -->
                    <button 
                        class="btn btn-sm" 
                        :disabled="pagination.current_page >= pagination.last_page || loadingBlocks" 
                        @click="fetchBlocks(pagination.current_page + 1)"
                        title="Next page"
                    >
                        &rarr;
                    </button>
                </div>
            </div>
        </settings-panel>

        <!-- Auto-Block Logs -->
        <settings-panel title="Auto-Block Logs" description="Audit log of automated blocking actions (HTTP 410, Suspended, etc.).">
            <template #actions>
                <button @click="clearLogs" class="btn-sm text-red-500 hover:text-red-600" v-if="logs.length > 0">
                    Clear Logs
                </button>
            </template>

            <div class="card p-0 overflow-hidden">
                <table class="data-table" v-if="logs.length > 0">
                    <thead>
                        <tr>
                            <th>DateTime</th>
                            <th>Handle / Identifier</th>
                            <th>Reason</th>
                            <th>Blocked URLs</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="log in logs" :key="log.id">
                            <td class="whitespace-nowrap text-xs">{{ formatDate(log.created_at) }}</td>
                            <td class="font-bold text-sm">{{ log.identifier }}</td>
                            <td><span class="badge">{{ log.reason }}</span></td>
                            <td class="text-xs text-gray-500">
                                <div v-for="url in log.urls" :key="url" class="truncate max-w-xs" :title="url">{{ url }}</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-else class="p-8 text-center text-gray-500 italic">
                    No auto-block logs found.
                </div>
            </div>

            <!-- Auto-Block Pagination -->
            <div v-if="logsPagination.total > logsPagination.per_page" class="flex justify-between items-center mt-4 px-2">
                <button 
                    @click="fetchLogs(logsPagination.current_page - 1)" 
                    :disabled="logsPagination.current_page === 1" 
                    class="btn-sm"
                >
                    Previous
                </button>
                <span class="text-xs text-gray-500">
                    Page {{ logsPagination.current_page }} of {{ logsPagination.last_page }}
                </span>
                <button 
                    @click="fetchLogs(logsPagination.current_page + 1)" 
                    :disabled="logsPagination.current_page === logsPagination.last_page" 
                    class="btn-sm"
                >
                    Next
                </button>
            </div>
        </settings-panel>
    </div>
</template>

<script>
import SettingsPanel from './SettingsPanel.vue';

export default {
    components: { SettingsPanel },
    props: {
        blocklistUrl: {
            type: String,
            required: true
        },
        blockDomainUrl: {
            type: String,
            required: true
        },
        blockUserUrl: {
            type: String,
            required: true
        },
        deleteBlockUrl: {
            type: String,
            required: true
        },
        autoBlockLogsUrl: {
            type: String,
            required: true
        },
        clearAutoBlockLogsUrl: {
            type: String,
            required: true
        }
    },
    data() {
        return {
            domainToBlock: '',
            handleToBlock: '',
            bulkDomainsText: '',
            bulkUsersText: '',
            showBulkDomain: false,
            showBulkUser: false,

            blockingDomain: false,
            blockingUser: false,
            deletingId: null,
            loadingBlocks: false,

            blocks: [],
            filterType: '',
            searchQuery: '',
            debounceTimer: null,
            pagination: {
                current_page: 1,
                last_page: 1,
                total: 0,
                per_page: 25
            },

            logs: [],
            logsPagination: {
                current_page: 1,
                last_page: 1,
                total: 0,
                per_page: 50
            }
        };
    },
    computed: {
        paginationRange() {
            const range = [];
            const delta = 2;
            const current = this.pagination.current_page;
            const last = this.pagination.last_page;
            const left = current - delta;
            const right = current + delta + 1;
            
            for (let i = 1; i <= last; i++) {
                if (i === 1 || i === last || (i >= left && i < right)) {
                    range.push(i);
                }
            }
            
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
        this.fetchBlocks();
        this.fetchLogs();
    },
    methods: {
        debounceFetchBlocks() {
            clearTimeout(this.debounceTimer);
            this.debounceTimer = setTimeout(() => {
                this.fetchBlocks(1);
            }, 300);
        },
        clearSearch() {
            this.searchQuery = '';
            this.fetchBlocks(1);
        },
        changePerPage(perPage) {
            this.pagination.per_page = perPage;
            this.fetchBlocks(1);
        },
        fetchBlocks(page = 1) {
            this.loadingBlocks = true;
            this.$axios.get(this.blocklistUrl, {
                params: {
                    page,
                    per_page: this.pagination.per_page,
                    type: this.filterType,
                    search: this.searchQuery
                }
            })
            .then(response => {
                this.blocks = response.data.data;
                this.pagination = response.data;
            })
            .catch(error => {
                Statamic.$toast.error('Failed to load blocklist entries.');
            })
            .finally(() => {
                this.loadingBlocks = false;
            });
        },
        blockDomain() {
            if (!this.domainToBlock.trim()) return;
            this.blockingDomain = true;

            this.$axios.post(this.blockDomainUrl, { domain: this.domainToBlock })
                .then(response => {
                    Statamic.$toast.success(response.data.message);
                    this.domainToBlock = '';
                    this.fetchBlocks(1);
                })
                .catch(error => {
                    Statamic.$toast.error(error.response?.data?.message || 'Failed to block domain.');
                })
                .finally(() => {
                    this.blockingDomain = false;
                });
        },
        blockDomainBulk() {
            if (!this.bulkDomainsText.trim()) return;
            this.blockingDomain = true;

            this.$axios.post(this.blockDomainUrl, { domains: this.bulkDomainsText })
                .then(response => {
                    Statamic.$toast.success(response.data.message);
                    this.bulkDomainsText = '';
                    this.showBulkDomain = false;
                    this.fetchBlocks(1);
                })
                .catch(error => {
                    Statamic.$toast.error(error.response?.data?.message || 'Failed to import domains.');
                })
                .finally(() => {
                    this.blockingDomain = false;
                });
        },
        blockUser() {
            if (!this.handleToBlock.trim()) return;
            this.blockingUser = true;

            this.$axios.post(this.blockUserUrl, { handle: this.handleToBlock })
                .then(response => {
                    Statamic.$toast.success(response.data.message);
                    this.handleToBlock = '';
                    this.fetchBlocks(1);
                    this.fetchLogs(1);
                })
                .catch(error => {
                    Statamic.$toast.error(error.response?.data?.message || 'Failed to block user.');
                })
                .finally(() => {
                    this.blockingUser = false;
                });
        },
        blockUserBulk() {
            if (!this.bulkUsersText.trim()) return;
            this.blockingUser = true;

            this.$axios.post(this.blockUserUrl, { handles: this.bulkUsersText })
                .then(response => {
                    Statamic.$toast.success(response.data.message);
                    this.bulkUsersText = '';
                    this.showBulkUser = false;
                    this.fetchBlocks(1);
                    this.fetchLogs(1);
                })
                .catch(error => {
                    Statamic.$toast.error(error.response?.data?.message || 'Failed to import users.');
                })
                .finally(() => {
                    this.blockingUser = false;
                });
        },
        deleteBlock(entry) {
            if (!confirm(`Are you sure you want to unblock "${entry.identifier}"?`)) return;

            this.deletingId = entry.id;
            const url = `${this.deleteBlockUrl}/${entry.id}`;

            this.$axios.delete(url)
                .then(response => {
                    Statamic.$toast.success(response.data.message || 'Block removed.');
                    this.fetchBlocks(this.pagination.current_page);
                })
                .catch(error => {
                    Statamic.$toast.error(error.response?.data?.message || 'Failed to remove block.');
                })
                .finally(() => {
                    this.deletingId = null;
                });
        },
        fetchLogs(page = 1) {
            this.$axios.get(this.autoBlockLogsUrl, { params: { page } })
                .then(response => {
                    this.logs = response.data.data;
                    this.logsPagination = response.data;
                });
        },
        clearLogs() {
            if (!confirm('Are you sure you want to clear all auto-block logs?')) return;
            this.$axios.post(this.clearAutoBlockLogsUrl)
                .then(() => {
                    this.logs = [];
                    this.logsPagination.total = 0;
                    Statamic.$toast.success('Logs cleared.');
                });
        },
        formatDate(dateString) {
            if (!dateString) return '';
            return new Date(dateString).toLocaleString();
        }
    }
}
</script>
