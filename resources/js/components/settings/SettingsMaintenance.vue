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
        logsUrl: String
    }
}
</script>
