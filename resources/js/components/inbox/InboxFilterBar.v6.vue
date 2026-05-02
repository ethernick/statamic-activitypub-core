<template>
    <div class="flex rounded-lg p-1 gap-1 border ap-filter-container items-center">
        <button 
            v-for="f in ['all', 'activities', 'mentions']" 
            :key="f"
            @click="$emit('filter-change', f)"
            class="px-3 py-1 rounded-md text-sm font-medium transition-colors capitalize ap-filter-btn"
            :class="currentFilter === f ? 'active' : 'inactive'"
        >
            {{ f === 'all' ? 'Entries' : f }}
        </button>
        <div class="btn-group relative flex items-center ml-2" v-if="canCreateNote">
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
            <div v-if="showNewDropdown" class="absolute right-0 w-48 mt-2 origin-top-right border rounded-md shadow-lg outline-none z-50 py-1 ap-dropdown-menu" style="top: 2.75em; text-align: left;">
                <activity-pub-hook-loader name="inbox-new-dropdown" />
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
        canCreateNote: {
            type: Boolean,
            default: false
        },
        showNewDropdown: {
            type: Boolean,
            default: false
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
