<template>
    <div class="mt-4 p-4 rounded-lg border shadow-sm animate-fade-in-down ap-reply-card">
        <div class="flex flex-col gap-3">
            <div class="flex items-center gap-2">
                <label class="text-xs text-gray-500 uppercase font-bold">Reply As:</label>
                <select :value="actorId" @input="$emit('update:actorId', $event.target.value)" class="text-sm rounded-md shadow-sm ap-reply-input">
                    <option v-for="actor in actors" :key="actor.id" :value="actor.id">{{ actor.name }} ({{ actor.handle }})</option>
                </select>
            </div>
            <div class="mb-3">
                <input 
                    type="text" 
                    :value="contentWarning" 
                    @input="$emit('update:contentWarning', $event.target.value)" 
                    placeholder="Content Warning (optional)" 
                    class="input-text w-full text-sm"
                >
            </div>
            <div class="min-h-[150px]">
                <markdown-fieldtype
                    :value="content"
                    :model-value="content"
                    :config="{
                        cheatsheet: true,
                        container: 'assets',
                        buttons: ['bold', 'italic', 'unorderedlist', 'orderedlist', 'quote', 'link', 'image', 'table']
                    }"
                    @input="$emit('update:content', $event)"
                    @update:modelValue="$emit('update:content', $event)"
                    @update:value="$emit('update:content', $event)"
                />
            </div>
            <div class="flex justify-end gap-2 mt-3">
                <button type="button" @click="$emit('cancel')" class="btn">Cancel</button>
                <button type="button" @click="$emit('submit')" class="btn-primary" :disabled="loading">
                    {{ loading ? 'Sending...' : 'Reply' }}
                </button>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    props: {
        actors: {
            type: Array,
            default: () => []
        },
        actorId: {
            // v-model:actorId
            type: [String, Number],
            default: null
        },
        content: {
            // v-model:content or just default v-model
            type: String,
            default: ''
        },
        contentWarning: {
            // v-model:contentWarning
            type: String,
            default: ''
        },
        loading: {
            type: Boolean,
            default: false
        }
    }
}
</script>

<style>
/* Styles copied from ActivityPubInbox.vue */
.ap-reply-card {
    background-color: white;
    border-color: #e5e7eb; /* gray-200 */
}
.ap-reply-input {
    background-color: white;
    border-color: #d1d5db; /* gray-300 */
    color: #111827; /* gray-900 */
}

/* Reply Editor Dark Mode */
html.dark .ap-reply-card,
html.is-dark .ap-reply-card,
html.isdark .ap-reply-card {
    background-color: #262626; /* neutral-800 */
    border-color: #404040; /* neutral-700 */
}

html.dark .ap-reply-input,
html.is-dark .ap-reply-input,
html.isdark .ap-reply-input {
    background-color: #171717; /* neutral-900 */
    border-color: #404040; /* neutral-700 */
    color: #d4d4d4; /* neutral-300 */
}
</style>
