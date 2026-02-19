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
             <div class="border-t dark:border-dark-800 pt-4">
                <label class="font-bold text-sm block mb-1">Schedule Interval (minutes)</label>
                <p class="text-xs text-gray-500 mb-2">How often the scheduled job executes (default: 1 min).</p>
                 <div class="flex items-center gap-4">
                    <input type="range" v-model.number="form.schedule_interval" class="w-full max-w-sm h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer dark:bg-gray-700" min="1" max="60">
                    <span class="font-mono bg-gray-100 dark:bg-dark-600 px-2 py-1 rounded text-sm w-12 text-center">{{ form.schedule_interval }}m</span>
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
