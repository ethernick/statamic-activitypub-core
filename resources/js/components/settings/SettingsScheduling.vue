<template>
    <div class="flex flex-col gap-6">
        <settings-panel title="Inbox / Outbox Processing" description="Configure batch sizes and schedule frequency.">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                 <div>
                    <label class="font-bold text-sm block mb-1">Inbox Batch Size</label>
                    <p class="text-xs text-gray-500 mb-2">Items to process per run.</p>
                    <input type="number" v-model.number="form.inbox_batch_size" class="input-text w-full" min="1">
                </div>
                 <div>
                    <label class="font-bold text-sm block mb-1">Outbox Batch Size</label>
                    <p class="text-xs text-gray-500 mb-2">Items to send per run.</p>
                    <input type="number" v-model.number="form.outbox_batch_size" class="input-text w-full" min="1">
                </div>
            </div>
             <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                <label class="font-bold text-sm block mb-1">Schedule Interval (minutes)</label>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">How often the scheduled job executes (default: 1 min).</p>
                 <div class="flex items-center gap-4">
                    <input type="range" v-model.number="form.schedule_interval" class="w-full max-w-sm h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer dark:bg-gray-700" min="1" max="60">
                    <span class="font-mono bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-100 border border-gray-300 dark:border-gray-700 px-2 py-1 rounded text-sm w-12 text-center">{{ form.schedule_interval }}m</span>
                </div>
            </div>
             <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                <label class="font-bold text-sm block mb-1">Raw File Sync Interval (hours)</label>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">Scan collections for new/updated Markdown files uploaded via SFTP or Git (0 = disabled, CLI only).</p>
                 <div class="flex items-center gap-4">
                    <input type="range" v-model.number="form.sync_interval_hours" class="w-full max-w-sm h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer dark:bg-gray-700" min="0" max="24">
                    <span class="font-mono bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-100 border border-gray-300 dark:border-gray-700 px-2 py-1 rounded text-sm w-16 text-center">{{ form.sync_interval_hours === 0 ? 'Off' : form.sync_interval_hours + 'h' }}</span>
                </div>
            </div>
        </settings-panel>
    </div>
</template>

<script>
import SettingsPanel from './SettingsPanel.vue';

export default {
    components: { SettingsPanel },
    props: {
        form: Object
    }
}
</script>
