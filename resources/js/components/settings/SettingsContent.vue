<template>
    <div class="flex flex-col gap-6">
        <!-- Allow Quotes -->
        <settings-panel title="General Behavior" description="Configure global ActivityPub behavior.">
             <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <label class="font-bold text-sm">Allow Quotes</label>
                    <p class="text-sm text-gray-500">Allow others to quote/boost your activities. Adds "canQuote" permission.</p>
                </div>
                <div class="toggle-container">
                    <input type="checkbox" :checked="form.allow_quotes" @change="$emit('update:allow_quotes', $event.target.checked)">
                </div>
            </div>
        </settings-panel>

        <!-- Collections -->
        <settings-panel title="Collections" description="Select which content collections to publish.">
            <template #table>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Collection</th>
                        <th>ActivityPub Type</th>
                        <th>Publish</th>
                        <th>Federate</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="col in collections" :key="col.handle">
                        <td class="p-3">
                            <div class="flex items-center gap-2">
                                <div class="w-4 h-4" v-if="col.icon" v-html="col.icon"></div>
                                <span>{{ col.title }}</span>
                            </div>
                        </td>
                        <td class="p-3">
                            <select class="input-text text-sm w-full" v-model="form.types[col.handle]">
                                <option v-for="(label, value) in types" :key="value" :value="value">{{ label }}</option>
                            </select>
                        </td>
                        <td class="p-3 text-center">
                            <input type="checkbox" v-model="form.collections[col.handle]">
                        </td>
                        <td class="p-3 text-center">
                            <input type="checkbox" v-model="form.federated[col.handle]">
                        </td>
                    </tr>
                </tbody>
            </table>
            </template>
        </settings-panel>

        <!-- Taxonomies -->
        <settings-panel title="Taxonomies">
            <template #table>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Taxonomy</th>
                        <th>ActivityPub Type</th>
                        <th>Publish</th>
                    </tr>
                </thead>
                <tbody class="divide-y dark:divide-dark-800">
                    <tr v-for="tax in taxonomies" :key="tax.handle">
                        <td class="p-3">
                            <div class="flex items-center gap-2">
                               <svg class="w-4 h-4 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" /></svg>
                                <span>{{ tax.title }}</span>
                            </div>
                        </td>
                        <td class="p-3">
                            <select class="input-text text-sm w-full" v-model="form.types[tax.handle]">
                                <option v-for="(label, value) in types" :key="value" :value="value">{{ label }}</option>
                            </select>
                        </td>
                        <td class="p-3 text-center">
                           <input type="checkbox" v-model="form.collections[tax.handle]">
                        </td>
                    </tr>
                </tbody>
            </table>
            </template>
        </settings-panel>
    </div>
</template>

<script>
import SettingsPanel from './SettingsPanel.vue';

export default {
    components: { SettingsPanel },
    props: {
        form: Object,
        collections: Array,
        taxonomies: Array,
        types: Object
    }
}
</script>
