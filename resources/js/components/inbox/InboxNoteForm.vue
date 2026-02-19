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
                        type: 'markdown'
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
            <button class="btn" @click="$emit('close')">Cancel</button>
            <button class="btn-primary" @click="$emit('submit')" :disabled="loading">
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
