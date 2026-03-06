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

            <div v-if="hashtagEnabled" class="mb-2 px-1">
                <div class="flex flex-wrap gap-2 mb-2">
                    <div v-for="(tag, index) in tags" :key="index" class="bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-gray-200 text-xs px-2 py-1 rounded flex items-center gap-1">
                        {{ tag }}
                        <button type="button" @click="removeTag(index)" class="hover:text-red-500">&times;</button>
                    </div>
                </div>
                <div class="relative">
                    <input 
                        type="text" 
                        v-model="tagInput" 
                        @keydown.enter.prevent="addTag"
                        @keydown.comma.prevent="addTag"
                        @input="handleInput"
                        class="input-text w-full" 
                        placeholder="Add tag..."
                    >
                    <div v-if="suggestions.length" class="absolute z-10 w-full bg-white dark:bg-gray-800 border dark:border-gray-700 shadow-lg max-h-40 overflow-y-auto mt-1 rounded">
                        <div 
                            v-for="term in suggestions" 
                            :key="term.id" 
                            @click="selectTerm(term.id)"
                            class="p-2 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer text-sm text-gray-800 dark:text-gray-200"
                        >
                            {{ term.title }} (#{{ term.id }})
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2 mt-3">
                <button type="button" @click="$emit('cancel')" class="relative inline-flex items-center justify-center whitespace-nowrap shrink-0 font-medium antialiased cursor-pointer no-underline disabled:[&_svg]:opacity-30 disabled:cursor-not-allowed [&_svg]:shrink-0 dark:[&_svg]:text-white bg-white hover:bg-gray-50 text-gray-800 border border-gray-300 shadow-sm px-4 h-10 text-sm rounded-lg dark:bg-gray-800 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-700">Cancel</button>
                <button type="button" @click="submitForm" class="relative inline-flex items-center justify-center whitespace-nowrap shrink-0 font-medium antialiased cursor-pointer no-underline disabled:[&_svg]:opacity-30 disabled:cursor-not-allowed [&_svg]:shrink-0 dark:[&_svg]:text-white bg-linear-to-b from-primary/90 to-primary hover:bg-primary-hover text-white disabled:opacity-60 disabled:text-white dark:disabled:text-white border border-primary-border shadow-ui-md inset-shadow-2xs inset-shadow-white/25 disabled:inset-shadow-none dark:disabled:inset-shadow-none [&_svg]:text-white [&_svg]:opacity-60 px-4 h-10 text-sm gap-2 rounded-lg" :disabled="loading">
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
        },
        tags: {
            type: Array,
            default: () => []
        },
        hashtagEnabled: {
            type: Boolean,
            default: false
        },
        hashtagTaxonomy: {
            type: String,
            default: 'tags'
        },
        searchTermsUrl: {
            type: String,
            default: null
        }
    },
    data() {
        return {
            tagInput: '',
            suggestions: [],
            searchTimeout: null
        }
    },
    methods: {
        handleInput() {
            if (this.tagInput.includes(',')) {
                this.addTag();
            }
            this.searchExistingTerms();
        },
        addTag() {
            console.log('ReplyForm (v6) addTag, input:', this.tagInput);
            
            // Split by comma and process each part
            const tagsToProcess = this.tagInput.split(',');
            let newTags = [...this.tags];
            let modified = false;

            tagsToProcess.forEach(rawTag => {
                const tag = rawTag.trim().replace(/^#/, '');
                if (tag && !newTags.includes(tag)) {
                    newTags.push(tag);
                    modified = true;
                    console.log('Tag added to ReplyForm:', tag);
                }
            });

            if (modified) {
                console.log('Emitting updated tags:', JSON.stringify(newTags));
                this.$emit('update:tags', newTags);
            }

            this.tagInput = '';
            this.suggestions = [];
        },
        removeTag(index) {
            const newTags = [...this.tags];
            newTags.splice(index, 1);
            this.$emit('update:tags', newTags);
        },
        submitForm() {
            console.log('ReplyForm (v6) submitForm, pending input:', this.tagInput);
            if (this.tagInput.trim()) {
                this.addTag();
            }
            console.log('Emitting submit from ReplyForm');
            this.$emit('submit');
        },
        searchExistingTerms() {
            if (!this.searchTermsUrl || this.tagInput.length < 2) {
                this.suggestions = [];
                return;
            }

            if (this.searchTimeout) clearTimeout(this.searchTimeout);

            this.searchTimeout = setTimeout(() => {
                this.$axios.get(this.searchTermsUrl, {
                    params: {
                        taxonomy: this.hashtagTaxonomy,
                        q: this.tagInput
                    }
                }).then(response => {
                    this.suggestions = response.data.filter(term => !this.tags.includes(term.id));
                });
            }, 300);
        },
        selectTerm(slug) {
            if (!this.tags.includes(slug)) {
                const newTags = [...this.tags, slug];
                this.$emit('update:tags', newTags);
            }
            this.tagInput = '';
            this.suggestions = [];
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
