<template>
    <inbox-stack :open="open" @closed="$emit('close')" title="Create Poll">
        <div class="mb-5">
            <label class="block text-sm font-bold mb-2">Post As</label>
            <select v-model="form.actor" class="input-text w-full">
                <option v-for="actor in actors" :key="actor.id" :value="actor.id">{{ actor.name }} ({{ actor.handle }})</option>
            </select>
        </div>

        <div class="mb-5">
            <label class="block text-sm font-bold mb-2">Question</label>
            <textarea v-model="form.content" class="input-text w-full" rows="3" placeholder="Ask a question..."></textarea>
        </div>

        <div class="mb-5">
            <label class="flex items-center space-x-2 cursor-pointer">
                <input type="checkbox" v-model="form.multiple_choice" class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                <span class="text-sm font-bold">Allow Multiple Choices (Checkboxes)</span>
            </label>
        </div>

        <div class="mb-5">
            <label class="block text-sm font-bold mb-2">Options</label>
            <div class="text-xs text-gray-500 mb-2">Leave options empty for an open-ended question.</div>
            <div class="space-y-2">
                 <div v-for="(opt, idx) in form.options" :key="idx" class="flex items-center gap-2">
                    <input type="text" v-model="form.options[idx]" class="input-text w-full text-sm" placeholder="Option text">
                    <button v-if="form.options.length > 2" @click="removeOption(idx)" class="text-red-500 hover:text-red-700">&times;</button>
                 </div>
            </div>
            <button @click="addOption" class="mt-2 text-sm text-blue-600 hover:text-blue-800">+ Add Option</button>
        </div>

        <template #footer-end>
            <button class="relative inline-flex items-center justify-center whitespace-nowrap shrink-0 font-medium antialiased cursor-pointer no-underline disabled:[&_svg]:opacity-30 disabled:cursor-not-allowed [&_svg]:shrink-0 dark:[&_svg]:text-white bg-white hover:bg-gray-50 text-gray-800 border border-gray-300 shadow-sm px-4 h-10 text-sm rounded-lg dark:bg-gray-800 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-700" @click="$emit('close')">Cancel</button>
            <button class="relative inline-flex items-center justify-center whitespace-nowrap shrink-0 font-medium antialiased cursor-pointer no-underline disabled:[&_svg]:opacity-30 disabled:cursor-not-allowed [&_svg]:shrink-0 dark:[&_svg]:text-white bg-linear-to-b from-primary/90 to-primary hover:bg-primary-hover text-white disabled:opacity-60 disabled:text-white dark:disabled:text-white border border-primary-border shadow-ui-md inset-shadow-2xs inset-shadow-white/25 disabled:inset-shadow-none dark:disabled:inset-shadow-none [&_svg]:text-white [&_svg]:opacity-60 px-4 h-10 text-sm gap-2 rounded-lg" @click="$emit('submit')" :disabled="loading">
                {{ loading ? 'Creating...' : 'Create Poll' }}
            </button>
        </template>
    </inbox-stack>
</template>

<script>
import InboxStack from './InboxStack.vue';

export default {
    components: {
        InboxStack
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
        loading: {
            type: Boolean,
            default: false
        }
    },
    methods: {
        addOption() {
            this.form.options.push('');
        },
        removeOption(index) {
            this.form.options.splice(index, 1);
        }
    }
}
</script>
