<template>
    <div class="flex flex-col gap-6">
        <settings-panel title="Automatic Cleanup" description="Manage data retention policies.">
            <div>
                <label class="font-bold text-sm block mb-1">Maintenance Time</label>
                <p class="text-xs text-gray-500 mb-2">Daily run time (24h format).</p>
                <input type="time" v-model="form.maintenance_time" class="input-text max-w-xs">
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-4 border-t dark:border-dark-800">
                 <div>
                    <label class="font-bold text-sm block mb-1">Clean Activities (Days)</label>
                    <p class="text-xs text-gray-500 mb-2">Keep external activities (Likes, Announces) for X days.</p>
                    <input type="number" v-model.number="form.retention_activities" class="input-text w-full" min="0">
                </div>
                 <div>
                    <label class="font-bold text-sm block mb-1">Clean Objects (Days)</label>
                    <p class="text-xs text-gray-500 mb-2">Keep external objects (Notes, Articles) for X days.</p>
                    <input type="number" v-model.number="form.retention_entries" class="input-text w-full" min="0">
                </div>
                <div>
                    <label class="font-bold text-sm block mb-1">Clean Log Files (Days)</label>
                    <p class="text-xs text-gray-500 mb-2">Keep daily activitypub log files on disk for X days.</p>
                    <input type="number" v-model.number="form.retention_logs" class="input-text w-full" min="1">
                </div>
            </div>
        </settings-panel>

        <!-- Manual Maintenance -->
        <settings-panel title="Manual Cleanup" description="Execute the maintenance cleanup immediately (cleans expired activities, notes, auto-block logs, and orphaned files).">
            <div class="flex flex-col gap-3">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500">Trigger immediate pruning based on the retention days configured above.</p>
                        <p v-if="running" class="text-xs text-blue-600 dark:text-blue-400 mt-1 flex items-center gap-1.5 animate-pulse">
                            <span class="inline-block w-2 h-2 rounded-full bg-blue-500 animate-ping"></span>
                            Cleaning expired records, pruning sidecars, and rebuilding Stache cache...
                        </p>
                    </div>
                    <button 
                        @click="triggerMaintenance" 
                        class="btn flex items-center gap-2" 
                        :class="{ 'opacity-75 cursor-wait pointer-events-none': running }"
                        :disabled="running"
                    >
                        <svg v-if="running" class="animate-spin -ml-1 mr-1 h-4 w-4 text-gray-700 dark:text-gray-200" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span v-if="running">Processing (this may take a minute)...</span>
                        <span v-else>Run Maintenance Now</span>
                    </button>
                </div>

                <div v-if="output" class="mt-3 p-3 bg-gray-100 dark:bg-dark-800 rounded font-mono text-xs whitespace-pre-wrap text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-dark-700 max-h-60 overflow-y-auto">
                    {{ output }}
                </div>
            </div>
        </settings-panel>

        <!-- Activity Logs -->
        <settings-panel title="Activity Logs" description="View detailed logs for inbox and outbox processing.">
             <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Useful for debugging connection issues.</p>
                </div>
                <a :href="logsUrl" class="btn">View Logs</a>
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
        logsUrl: String,
        runMaintenanceUrl: String
    },
    data() {
        return {
            running: false,
            output: null
        };
    },
    methods: {
        triggerMaintenance() {
            this.running = true;
            this.output = null;

            this.$axios.post(this.runMaintenanceUrl)
                .then(response => {
                    Statamic.$toast.success(response.data.message || 'Maintenance completed successfully.');
                    this.output = response.data.output || 'Cleanup completed.';
                })
                .catch(error => {
                    const msg = error.response?.data?.message || 'Maintenance failed to run.';
                    Statamic.$toast.error(msg);
                    this.output = 'Error: ' + msg;
                })
                .finally(() => {
                    this.running = false;
                });
        }
    }
}
</script>
