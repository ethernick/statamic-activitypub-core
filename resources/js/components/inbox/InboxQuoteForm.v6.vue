<template>
    <inbox-stack :open="open" @closed="$emit('close')" title="Quote Post">
        <!-- Original Note Preview -->
        <div class="mb-6 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
            <div class="text-xs text-gray-500 dark:text-gray-400 mb-2">Quoting:</div>
            <inbox-note
                v-if="quotedNote"
                :note="quotedNote"
                :permissions="{ update: false, delete: false }"
                @reply="() => {}"
                @boost="() => {}"
                @quote="() => {}"
                @like="() => {}"
            />
        </div>

        <div class="mb-5">
            <label class="block text-sm font-bold mb-2">Post As</label>
            <select v-model="form.actor" class="input-text w-full">
                <option v-for="actor in actors" :key="actor.id" :value="actor.id">
                    {{ actor.name }} ({{ actor.handle }})
                </option>
            </select>
        </div>

        <div class="mb-5">
            <label class="block text-sm font-bold mb-2">Content Warning (Optional)</label>
            <input
                type="text"
                v-model="form.content_warning"
                class="input-text w-full"
                placeholder="Content Warning (optional)">
        </div>

        <div class="mb-5">
            <label class="block text-sm font-bold mb-2">Your Commentary</label>
            <markdown-fieldtype
                :value="form.content"
                :model-value="form.content"
                :config="{
                    cheatsheet: true,
                    container: 'assets',
                    buttons: ['bold', 'italic', 'unorderedlist', 'orderedlist', 'quote', 'link', 'image', 'table'],
                    type: 'markdown',
                    toolbar_mode: 'fixed'
                }"
                @input="val => form.content = val"
                @update:modelValue="val => form.content = val"
                @update:value="val => form.content = val"
            />
        </div>

        <template #footer-end>
            <button class="relative inline-flex items-center justify-center whitespace-nowrap shrink-0 font-medium antialiased cursor-pointer no-underline disabled:[&_svg]:opacity-30 disabled:cursor-not-allowed [&_svg]:shrink-0 dark:[&_svg]:text-white bg-white hover:bg-gray-50 text-gray-800 border border-gray-300 shadow-sm px-4 h-10 text-sm rounded-lg dark:bg-gray-800 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-700" @click="$emit('close')">Cancel</button>
            <button class="relative inline-flex items-center justify-center whitespace-nowrap shrink-0 font-medium antialiased cursor-pointer no-underline disabled:[&_svg]:opacity-30 disabled:cursor-not-allowed [&_svg]:shrink-0 dark:[&_svg]:text-white bg-linear-to-b from-primary/90 to-primary hover:bg-primary-hover text-white disabled:opacity-60 disabled:text-white dark:disabled:text-white border border-primary-border shadow-ui-md inset-shadow-2xs inset-shadow-white/25 disabled:inset-shadow-none dark:disabled:inset-shadow-none [&_svg]:text-white [&_svg]:opacity-60 px-4 h-10 text-sm gap-2 rounded-lg" @click="$emit('submit')" :disabled="loading">
                {{ loading ? 'Posting...' : 'Quote' }}
            </button>
        </template>
    </inbox-stack>
</template>

<script>
import InboxStack from './InboxStack.vue';
import InboxNote from './InboxNote.vue';

export default {
    components: {
        InboxStack,
        InboxNote
    },
    props: {
        open: {
            type: Boolean,
            required: true
        },
        form: {
            type: Object,
            required: true
        },
        actors: {
            type: Array,
            default: () => []
        },
        quotedNote: {
            type: Object,
            default: null
        },
        loading: {
            type: Boolean,
            default: false
        }
    }
}
</script>
