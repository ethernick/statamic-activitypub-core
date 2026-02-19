<template>
    <inbox-stack :open="open" @closed="$emit('close')" :title="isEditing ? 'Edit Note' : 'Create Note'">
        <div class="mb-5">
            <label class="block text-sm font-bold mb-2">Post As</label>
            <select v-model="form.actor" class="input-text w-full" :disabled="isEditing">
                <option v-for="actor in actors" :key="actor.id" :value="actor.id">{{ actor.name }} ({{ actor.handle }})</option>
            </select>
        </div>

        <div class="mb-5 flex flex-col">
            <label class="block text-sm font-bold mb-2">Content</label>
            <div class="mb-3">
                <input type="text" v-model="form.content_warning" placeholder="Content Warning (optional)" class="input-text w-full">
            </div>
            <div class="min-h-[200px]">
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
                    :meta="{
                        previewUrl: previewUrl
                    }"
                    @input="val => form.content = val"
                    @update:modelValue="val => form.content = val"
                    @update:value="val => form.content = val"
                />
            </div>
        </div>

        <template #footer-end>
            <button class="relative inline-flex items-center justify-center whitespace-nowrap shrink-0 font-medium antialiased cursor-pointer no-underline disabled:[&_svg]:opacity-30 disabled:cursor-not-allowed [&_svg]:shrink-0 dark:[&_svg]:text-white bg-white hover:bg-gray-50 text-gray-800 border border-gray-300 shadow-sm px-4 h-10 text-sm rounded-lg dark:bg-gray-800 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-700" @click="$emit('close')">Cancel</button>
            <button class="relative inline-flex items-center justify-center whitespace-nowrap shrink-0 font-medium antialiased cursor-pointer no-underline disabled:[&_svg]:opacity-30 disabled:cursor-not-allowed [&_svg]:shrink-0 dark:[&_svg]:text-white bg-linear-to-b from-primary/90 to-primary hover:bg-primary-hover text-white disabled:opacity-60 disabled:text-white dark:disabled:text-white border border-primary-border shadow-ui-md inset-shadow-2xs inset-shadow-white/25 disabled:inset-shadow-none dark:disabled:inset-shadow-none [&_svg]:text-white [&_svg]:opacity-60 px-4 h-10 text-sm gap-2 rounded-lg" @click="$emit('submit')" :disabled="loading">
                {{ loading ? 'Saving...' : (isEditing ? 'Update' : 'Create') }}
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
        isEditing: {
            type: Boolean,
            default: false
        },
        loading: {
            type: Boolean,
            default: false
        },
        previewUrl: {
            type: String,
            default: null
        }
    }
}
</script>
