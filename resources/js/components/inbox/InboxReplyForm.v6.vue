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
                        buttons: ['bold', 'italic', 'unorderedlist', 'orderedlist', 'quote', 'link', 'image', 'table'],
                        toolbar_mode: 'fixed'
                    }"
                    @input="$emit('update:content', $event)"
                    @update:modelValue="$emit('update:content', $event)"
                    @update:value="$emit('update:content', $event)"
                />
            </div>
            <div class="flex justify-end gap-2 mt-3">
                <button type="button" @click="$emit('cancel')" class="relative inline-flex items-center justify-center whitespace-nowrap shrink-0 font-medium antialiased cursor-pointer no-underline disabled:[&_svg]:opacity-30 disabled:cursor-not-allowed [&_svg]:shrink-0 dark:[&_svg]:text-white bg-white hover:bg-gray-50 text-gray-800 border border-gray-300 shadow-sm px-4 h-10 text-sm rounded-lg dark:bg-gray-800 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-700">Cancel</button>
                <button type="button" @click="$emit('submit')" class="relative inline-flex items-center justify-center whitespace-nowrap shrink-0 font-medium antialiased cursor-pointer no-underline disabled:[&_svg]:opacity-30 disabled:cursor-not-allowed [&_svg]:shrink-0 dark:[&_svg]:text-white bg-linear-to-b from-primary/90 to-primary hover:bg-primary-hover text-white disabled:opacity-60 disabled:text-white dark:disabled:text-white border border-primary-border shadow-ui-md inset-shadow-2xs inset-shadow-white/25 disabled:inset-shadow-none dark:disabled:inset-shadow-none [&_svg]:text-white [&_svg]:opacity-60 px-4 h-10 text-sm gap-2 rounded-lg" :disabled="loading">
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
    border-color: #e5e5e5; /* neutral-200 */
}
.ap-reply-input {
    background-color: white;
    border-color: #d4d4d4; /* neutral-300 */
    color: #171717; /* neutral-900 */
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
