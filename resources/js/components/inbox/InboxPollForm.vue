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
            <button class="btn" @click="$emit('close')">Cancel</button>
            <button class="btn-primary" @click="$emit('submit')" :disabled="loading">
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
