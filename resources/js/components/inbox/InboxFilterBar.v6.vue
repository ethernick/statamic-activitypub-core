<template>
    <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto">

        <!-- Search Input with Debounce & Clear (Keywords, @author, #tag) -->
        <div class="ap-search-wrapper relative flex items-center w-full sm:w-auto flex-1 min-w-0">
            <div class="ap-search-icon absolute inset-y-0 left-0 flex items-center pointer-events-none text-gray-400">
                <svg v-if="!loading" class="w-4 h-4 stroke-current" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <svg v-else class="animate-spin w-4 h-4 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </div>
            <input 
                type="text" 
                v-model="searchQuery"
                @input="onSearchInput"
                @keydown.esc="clearSearch"
                placeholder="Search notes, @author, #tag..."
                class="ap-search-input h-10 w-full rounded-lg transition-colors border outline-none"
            />
            <button 
                v-if="searchQuery" 
                type="button" 
                @click="clearSearch" 
                class="ap-search-clear absolute inset-y-0 right-0 flex items-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 cursor-pointer"
                title="Clear search"
            >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <!-- Segmented Tab Filters: Entries, Activities, Mentions -->
        <div class="flex rounded-lg p-1 gap-1 border ap-filter-container items-center shrink-0">
            <button 
                v-for="f in ['all', 'activities', 'mentions']" 
                :key="f"
                @click="$emit('filter-change', f)"
                class="px-2.5 sm:px-3 py-1 rounded-md text-xs sm:text-sm font-medium transition-colors capitalize ap-filter-btn"
                :class="currentFilter === f ? 'active' : 'inactive'"
            >
                {{ f === 'all' ? 'Entries' : f }}
            </button>
        </div>

        <!-- New Note Compose & Dropdown -->
        <div class="btn-group relative flex items-center shrink-0 ml-auto sm:ml-0" v-if="canCreateNote">
            <button 
                type="button" 
                @click="$emit('create-note')" 
                class="ap-new-note-btn focus:z-10"
            >
                New Note
            </button>
            <button 
                type="button" 
                @click="$emit('toggle-dropdown')" 
                class="ap-dropdown-toggle focus:z-10"
            >
                <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                </svg>
            </button>
            <div 
                v-if="showNewDropdown" 
                class="absolute right-0 w-48 max-w-[calc(100vw-2rem)] mt-2 origin-top-right border rounded-md shadow-lg outline-none z-50 py-1 ap-dropdown-menu" 
                style="top: 2.75em; text-align: left;"
            >
                <activitypub-hook-loader name="inbox-new-dropdown" />
            </div>
        </div>
    </div>
</template>

<script>
export default {
    props: {
        currentFilter: {
            type: String,
            default: 'all'
        },
        search: {
            type: String,
            default: ''
        },
        canCreateNote: {
            type: Boolean,
            default: false
        },
        showNewDropdown: {
            type: Boolean,
            default: false
        },
        loading: {
            type: Boolean,
            default: false
        }
    },
    emits: ['filter-change', 'search-change', 'create-note', 'toggle-dropdown', 'create-poll'],
    data() {
        return {
            searchQuery: this.search || '',
            debounceTimer: null
        };
    },
    watch: {
        search(newVal) {
            this.searchQuery = newVal || '';
        }
    },
    methods: {
        onSearchInput() {
            clearTimeout(this.debounceTimer);
            this.debounceTimer = setTimeout(() => {
                this.$emit('search-change', this.searchQuery);
            }, 300);
        },
        clearSearch() {
            this.searchQuery = '';
            clearTimeout(this.debounceTimer);
            this.$emit('search-change', '');
        }
    }
}
</script>

<style>
.ap-filter-container {
    background-color: #e5e7eb; /* gray-200 */
    border-color: #e5e7eb;
}
html.dark .ap-filter-container,
html.is-dark .ap-filter-container,
html.isdark .ap-filter-container {
    background-color: #171717; /* neutral-900 */
    border-color: #262626; /* neutral-800 */
}

/* Light Mode Defaults */
.ap-filter-btn.active {
    background-color: white;
    color: #111827; /* gray-900 */
    box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
}
.ap-filter-btn.inactive {
    color: #6b7280; /* gray-500 */
    font-weight: normal;
}
.ap-filter-btn.inactive:hover {
    color: #374151; /* gray-700 */
}

/* Dark Mode Overrides */
html.dark .ap-filter-btn.active,
html.is-dark .ap-filter-btn.active,
html.isdark .ap-filter-btn.active {
    background-color: #404040; /* neutral-700 */
    color: #f5f5f5; /* neutral-100 */
}
html.dark .ap-filter-btn.inactive,
html.is-dark .ap-filter-btn.inactive,
html.isdark .ap-filter-btn.inactive {
    color: #a3a3a3; /* neutral-400 */
}
html.dark .ap-filter-btn.inactive:hover,
html.is-dark .ap-filter-btn.inactive:hover,
html.isdark .ap-filter-btn.inactive:hover {
    color: #e5e5e5; /* neutral-200 */
}

/* Search Input Styling */
.ap-search-wrapper {
    min-width: 0;
    width: 100%;
    max-width: 100%;
    flex: 1 1 100%;
}

@media (min-width: 640px) {
    .ap-search-wrapper {
        min-width: 280px;
        max-width: 440px;
        width: auto;
        flex: 1 1 auto;
    }
}

.ap-search-icon {
    padding-left: 0.875rem !important; /* 14px */
    pointer-events: none;
    z-index: 2;
}

.ap-search-clear {
    padding-right: 0.75rem !important;
    z-index: 2;
}

.ap-search-input {
    background-color: white;
    border-color: #d1d5db; /* gray-300 */
    color: #111827; /* gray-900 */
    font-size: 0.8125rem; /* 13px */
    padding-left: 2.5rem !important; /* 40px - clears search icon completely */
    padding-right: 2rem !important;
    box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
}
.ap-search-input::placeholder {
    color: #9ca3af; /* gray-400 */
    opacity: 0.65;
    font-size: 0.8125rem;
}
.ap-search-input:focus {
    border-color: var(--theme-color-primary, #3b82f6);
    box-shadow: 0 0 0 1px var(--theme-color-primary, #3b82f6);
}
html.dark .ap-search-input,
html.is-dark .ap-search-input,
html.isdark .ap-search-input {
    background-color: #171717; /* neutral-900 */
    border-color: #262626; /* neutral-800 */
    color: #f5f5f5; /* neutral-100 */
}
html.dark .ap-search-input::placeholder,
html.is-dark .ap-search-input::placeholder,
html.isdark .ap-search-input::placeholder {
    color: #737373; /* neutral-500 */
    opacity: 0.6;
}
html.dark .ap-search-input:focus,
html.is-dark .ap-search-input:focus,
html.isdark .ap-search-input:focus {
    border-color: var(--theme-color-primary, #3b82f6);
}

/* Explicit overrides for button and dropdown to bypass potential missing utility classes */
.ap-new-note-btn,
.ap-dropdown-toggle {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 2.5rem; /* h-10 */
    padding: 0 1rem;
    font-size: 0.875rem;
    font-weight: 500;
    color: white;
    cursor: pointer;
    /* Use Statamic's core theme variables directly for native look */
    background-image: linear-gradient(to bottom, color-mix(in oklch, var(--theme-color-primary, var(--color-primary)) 90%, white), var(--theme-color-primary, var(--color-primary)));
    background-color: var(--theme-color-primary, var(--color-primary));
    border: 1px solid color-mix(in oklch, var(--theme-color-primary, var(--color-primary)) 100%, black 20%);
    box-shadow: var(--shadow-ui-md), inset 0 1px 0 0 rgba(255, 255, 255, 0.25);
}

.ap-new-note-btn {
    border-top-left-radius: 0.5rem;
    border-bottom-left-radius: 0.5rem;
    padding-right: 0.75rem;
}

.ap-dropdown-toggle {
    border-top-right-radius: 0.5rem;
    border-bottom-right-radius: 0.5rem;
    padding: 0 0.5rem;
    border-left-color: rgba(255, 255, 255, 0.2);
    margin-left: -1px;
}

.ap-new-note-btn:hover,
.ap-dropdown-toggle:hover {
    background-image: none !important;
    background-color: color-mix(in oklch, var(--theme-color-primary, var(--color-primary)) 100%, black 30%) !important;
}

html.dark .ap-new-note-btn,
html.dark .ap-dropdown-toggle,
html.is-dark .ap-new-note-btn,
html.is-dark .ap-dropdown-toggle,
html.isdark .ap-new-note-btn,
html.isdark .ap-dropdown-toggle {
    border-color: color-mix(in oklch, var(--theme-color-primary, var(--color-primary)) 100%, black 20%);
}

html.dark .ap-new-note-btn:hover,
html.dark .ap-dropdown-toggle:hover,
html.is-dark .ap-new-note-btn:hover,
html.is-dark .ap-dropdown-toggle:hover,
html.isdark .ap-new-note-btn:hover,
html.isdark .ap-dropdown-toggle:hover {
    background-color: color-mix(in oklch, var(--theme-color-primary, var(--color-primary)) 100%, white 30%) !important;
}

.ap-dropdown-menu {
    background-color: var(--theme-color-content-bg, white);
    border: 1px solid var(--theme-color-content-border, #e5e7eb);
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
}

.ap-dropdown-menu a, 
.ap-dropdown-menu button {
    display: block;
    width: 100%;
    padding: 0.5rem 1rem;
    text-align: left;
    font-size: 0.875rem;
    color: var(--theme-color-gray-900, #1f2937);
    text-decoration: none;
    background: transparent;
    border: none;
    cursor: pointer;
    font-weight: 400;
    letter-spacing: 0.01em;
}

.ap-dropdown-menu a:hover, 
.ap-dropdown-menu button:hover {
    background-color: var(--theme-color-gray-100, #f3f4f6);
}

/* Dark Mode Overrides */
html.dark .ap-dropdown-menu,
html.is-dark .ap-dropdown-menu,
html.isdark .ap-dropdown-menu {
    background-color: var(--theme-color-gray-850, #1c2e36);
    border: 1px solid var(--theme-color-gray-950, #141a1f);
    color: var(--theme-color-gray-300, #eef2f6);
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.5), 0 4px 6px -2px rgba(0, 0, 0, 0.2);
}

html.dark .ap-dropdown-menu a,
html.dark .ap-dropdown-menu button,
html.is-dark .ap-dropdown-menu a,
html.is-dark .ap-dropdown-menu button,
html.isdark .ap-dropdown-menu a,
html.isdark .ap-dropdown-menu button {
    color: var(--theme-color-gray-300, #eef2f6);
}

.ap-dropdown-header {
    border-bottom: 1px solid var(--theme-color-gray-100, #f3f4f6);
    margin-bottom: 4px;
}

html.dark .ap-dropdown-header,
html.is-dark .ap-dropdown-header,
html.isdark .ap-dropdown-header {
    border-bottom-color: var(--theme-color-gray-800, #262626);
    color: var(--theme-color-gray-500, #737373);
}

html.dark .ap-dropdown-menu a:hover,
html.dark .ap-dropdown-menu button:hover,
html.is-dark .ap-dropdown-menu a:hover,
html.is-dark .ap-dropdown-menu button:hover,
html.isdark .ap-dropdown-menu a:hover,
html.isdark .ap-dropdown-menu button:hover {
    background-color: var(--theme-color-gray-800, #2e393d) !important;
    color: white !important;
}
</style>
