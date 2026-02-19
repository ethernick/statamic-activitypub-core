<template>
    <div class="max-w-5xl mx-auto">
        <settings-title
            :saving="saving"
            :success="success"
            :error="error"
            @save="save"
        />

        <div class="flex flex-col gap-6">
            <!-- Tabs -->
            <div class="w-full flex border-b dark:border-dark-800">
                <button 
                    v-for="tab in tabs" 
                    :key="tab.id"
                    @click.prevent="currentTab = tab.id"
                    class="px-4 py-2 text-sm font-medium border-b-2 transition-colors focus:outline-none"
                    :class="currentTab === tab.id ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                >
                    {{ tab.label }}
                </button>
            </div>


            <!-- TAB: Content (Collections & Taxonomies) -->
            <div v-if="currentTab === 'content'">
                <settings-content 
                    :form="form" 
                    :collections="collections" 
                    :taxonomies="taxonomies" 
                    :types="types"
                    @update:allow_quotes="form.allow_quotes = $event"
                />
            </div>

            <!-- TAB: Scheduling -->
            <div v-if="currentTab === 'scheduling'">
                <settings-scheduling :form="form" />
            </div>

            <!-- TAB: Maintenance -->
            <div v-if="currentTab === 'maintenance'">
                <settings-maintenance :form="form" :logs-url="logsUrl" />
            </div>

            <!-- TAB: Blocklist -->
            <div v-if="currentTab === 'blocklist'">
                <settings-blocklist :form="form" />
            </div>
        </div>
    </div>
</template>

<script>
import SettingsTitle from './SettingsTitle.vue';
import SettingsContent from './SettingsContent.vue';
import SettingsScheduling from './SettingsScheduling.vue';
import SettingsMaintenance from './SettingsMaintenance.vue';
import SettingsBlocklist from './SettingsBlocklist.vue';

export default {
    components: {
        SettingsTitle,
        SettingsContent,
        SettingsScheduling,
        SettingsMaintenance,
        SettingsBlocklist
    },
    props: {
        initialSettings: {
            type: Object,
            required: true
        },
        collections: {
            type: Array,
            default: () => []
        },
        taxonomies: {
            type: Array,
            default: () => []
        },
        types: {
            type: Object,
            default: () => ({})
        },
        saveUrl: {
            type: String,
            required: true
        },
        logsUrl: {
            type: String,
            required: true
        }
    },
    data() {
        return {
            currentTab: 'content',
            tabs: [
                { id: 'content', label: 'Content' },
                { id: 'scheduling', label: 'Scheduling' },
                { id: 'maintenance', label: 'Maintenance' },
                { id: 'blocklist', label: 'Blocklist' }
            ],
            // Initialize form with safe defaults, but we'll rebuild it in created()
            form: {
                allow_quotes: false,
                inbox_batch_size: 50,
                outbox_batch_size: 50,
                schedule_interval: 1,
                maintenance_time: '02:00',
                retention_activities: 2,
                retention_entries: 30,
                blocklist: '',
                collections: {},
                federated: {},
                types: {}
            },
            saving: false,
            success: false,
            error: null
        };
    },
    created() {
        try {
            // Build the complete form state object locally to ensure reactivity when assigned
            const newForm = {
                allow_quotes: this.initialSettings.allow_quotes || false,
                inbox_batch_size: this.initialSettings.inbox_batch_size || 50,
                outbox_batch_size: this.initialSettings.outbox_batch_size || 50,
                schedule_interval: this.initialSettings.schedule_interval || 1,
                maintenance_time: this.initialSettings.maintenance_time || '02:00',
                retention_activities: this.initialSettings.retention_activities || 2,
                retention_entries: this.initialSettings.retention_entries || 30,
                blocklist: this.initialSettings.blocklist || '',
                collections: {},
                federated: {},
                types: {}
            };

            // Populate nested objects
            this.collections.forEach(c => {
                const config = this.initialSettings[c.handle] || {};
                newForm.collections[c.handle] = !!config.enabled;
                newForm.types[c.handle] = config.type || 'Object';
                newForm.federated[c.handle] = !!config.federated;
            });

            this.taxonomies.forEach(t => {
                const config = this.initialSettings[t.handle] || {};
                newForm.collections[t.handle] = !!config.enabled;
                newForm.types[t.handle] = config.type || 'Object';
            });

            // Assign to data property (Vue 2 will walk this and make it reactive)
            this.form = newForm;

        } catch (e) {
            console.error('Error initializing Settings component:', e);
            this.error = 'Failed to load settings: ' + e.message;
        }
    },
    methods: {
        save() {
            this.saving = true;
            this.success = false;
            this.error = null;

            const payload = {
                collections: this.form.collections,
                types: this.form.types,
                federated: this.form.federated,
                allow_quotes: this.form.allow_quotes ? 1 : 0,
                inbox_batch_size: this.form.inbox_batch_size,
                outbox_batch_size: this.form.outbox_batch_size,
                schedule_interval: this.form.schedule_interval,
                maintenance_time: this.form.maintenance_time,
                retention_activities: this.form.retention_activities,
                retention_entries: this.form.retention_entries,
                blocklist: this.form.blocklist
            };

            this.$axios.post(this.saveUrl, payload)
                .then(response => {
                    this.success = true;
                    setTimeout(() => { this.success = false; }, 3000);
                    Statamic.$toast.success('Settings saved successfully.');
                })
                .catch(error => {
                    console.error(error);
                    const msg = error.response?.data?.message || 'Failed to save settings.';
                    this.error = msg;
                    Statamic.$toast.error(msg);
                })
                .finally(() => {
                    this.saving = false;
                });
        }
    }
}
</script>
