<template>
    <div class="flex flex-col gap-6">
         <settings-panel title="Domain Blocklist" description="Prevent interactions with specific domains.">
            <textarea v-model="form.blocklist" class="input-text w-full h-64 font-mono text-sm" placeholder="example.com\nspam.server.social" rows="25"></textarea>
            <p class="text-xs text-gray-500 mt-2">
                One domain or Actor URL per line. Subdomains are automatically blocked if the parent is listed.
            </p>
        </settings-panel>

        <!-- Block User Tool -->
        <settings-panel title="Block User" description="Resolve a handle via Webfinger and block all associated URLs and aliases.">
            <div class="flex gap-2">
                <input type="text" v-model="handleToBlock" class="input-text flex-1" placeholder="@user@example.com" @keyup.enter="blockHandle">
                <button @click="blockHandle" class="btn" :disabled="blocking">
                    <span v-if="blocking">Processing...</span>
                    <span v-else>Block User</span>
                </button>
            </div>
            <p class="text-xs text-gray-500 mt-2">
                This will perform an Actor Lookup, then append the Actor URL and any known aliases (alsoKnownAs) to the blocklist above.
            </p>
        </settings-panel>

        <!-- Auto-Block Logs -->
        <settings-panel title="Auto-Block Logs" description="Audit log of automated blocking actions (HTTP 410, Suspended, etc.).">
            <template #actions>
                <button @click="clearLogs" class="btn-sm text-red-500 hover:text-red-600" v-if="logs.length > 0">Clear Logs</button>
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

            <!-- Simple Pagination -->
            <div v-if="pagination.total > pagination.per_page" class="flex justify-between items-center mt-4 px-4">
                <button @click="fetchLogs(pagination.current_page - 1)" :disabled="pagination.current_page === 1" class="btn-sm">Previous</button>
                <span class="text-xs text-gray-500">Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
                <button @click="fetchLogs(pagination.current_page + 1)" :disabled="pagination.current_page === pagination.last_page" class="btn-sm">Next</button>
            </div>
        </settings-panel>
    </div>
</template>

<script>
import SettingsPanel from './SettingsPanel.vue';

export default {
    components: { SettingsPanel },
    props: {
        form: Object,
        autoBlockLogsUrl: String,
        clearAutoBlockLogsUrl: String,
        resolveHandleUrl: String
    },
    data() {
        return {
            handleToBlock: '',
            blocking: false,
            logs: [],
            pagination: {
                current_page: 1,
                last_page: 1,
                total: 0,
                per_page: 50
            }
        };
    },
    mounted() {
        this.fetchLogs();
    },
    methods: {
        fetchLogs(page = 1) {
            this.$axios.get(this.autoBlockLogsUrl, { params: { page } })
                .then(response => {
                    this.logs = response.data.data;
                    this.pagination = response.data;
                });
        },
        blockHandle() {
            if (!this.handleToBlock) return;
            this.blocking = true;
            this.$axios.post(this.resolveHandleUrl, { handle: this.handleToBlock })
                .then(response => {
                    Statamic.$toast.success(response.data.message);
                    this.handleToBlock = '';
                    // Reload logs to show the manual block
                    this.fetchLogs();
                    // We need to reload settings or update the form.blocklist locally
                    // Simplified approach: reload the page or tell user to refresh
                    // Better: The controller added it to YAML. We should probably re-fetch settings or emit an event.
                    // For now, let's just trigger a reload of the settings from the parent if possible.
                    location.reload(); 
                })
                .catch(error => {
                    Statamic.$toast.error(error.response?.data?.message || 'Failed to block handle.');
                })
                .finally(() => {
                    this.blocking = false;
                });
        },
        clearLogs() {
            if (!confirm('Are you sure you want to clear all auto-block logs?')) return;
            this.$axios.post(this.clearAutoBlockLogsUrl)
                .then(() => {
                    this.logs = [];
                    this.pagination.total = 0;
                    Statamic.$toast.success('Logs cleared.');
                });
        },
        formatDate(dateString) {
            return new Date(dateString).toLocaleString();
        }
    }
}
</script>
