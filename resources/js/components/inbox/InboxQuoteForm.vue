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
                    type: 'markdown'
                }"
                @input="val => form.content = val"
                @update:modelValue="val => form.content = val"
                @update:value="val => form.content = val"
            />
        </div>

        <div v-if="hashtagEnabled" class="mb-5">
            <label class="block text-sm font-bold mb-2">Tags / Hashtags</label>
            <p class="text-xs text-gray-500 mb-2">Manual hashtags to append as metadata (amendments).</p>
            <div class="flex flex-wrap gap-2 mb-2">
                <div v-for="(tag, index) in form.tags" :key="index" class="bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-gray-200 text-xs px-2 py-1 rounded flex items-center gap-1">
                    {{ tag }}
                    <button @click="removeTag(index)" class="hover:text-red-500">&times;</button>
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
                    placeholder="Add tag and press Enter..."
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

        <template #footer-end>
            <button class="btn" @click="$emit('close')">Cancel</button>
            <button class="btn-primary" @click="submitForm" :disabled="loading">
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
            if (!this.form.tags) this.form.tags = [];
            console.log('QuoteForm (v5) addTag, input:', this.tagInput);
            
            // Split by comma and process each part
            const tags = this.tagInput.split(',');
            
            tags.forEach(rawTag => {
                const tag = rawTag.trim().replace(/^#/, '');
                if (tag && !this.form.tags.includes(tag)) {
                    this.form.tags.push(tag);
                    console.log('Tag added:', tag, 'Current tags:', JSON.stringify(this.form.tags));
                }
            });

            this.tagInput = '';
            this.suggestions = [];
        },
        removeTag(index) {
            this.form.tags.splice(index, 1);
        },
        submitForm() {
            if (this.tagInput.trim()) {
                this.addTag();
            }
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
                    this.suggestions = response.data.filter(term => !this.form.tags.includes(term.id));
                });
            }, 300);
        },
        selectTerm(slug) {
            if (!this.form.tags.includes(slug)) {
                this.form.tags.push(slug);
            }
            this.tagInput = '';
            this.suggestions = [];
        }
    }
}
</script>
