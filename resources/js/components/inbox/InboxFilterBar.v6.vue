<template>
    <div class="flex rounded-lg p-1 gap-1 border ap-filter-container items-center">
        <button 
            v-for="f in ['all', 'activities', 'mentions']" 
            :key="f"
            @click="$emit('filter-change', f)"
            class="px-3 py-1 rounded-md text-sm font-medium transition-colors capitalize ap-filter-btn"
            :class="currentFilter === f ? 'active' : 'inactive'"
        >
            {{ f }}
        </button>
        <div class="btn-group relative flex items-center ml-2" v-if="canCreateNote">
            <button 
                type="button" 
                @click="$emit('create-note')" 
                class="relative inline-flex items-center justify-center whitespace-nowrap shrink-0 font-medium antialiased cursor-pointer no-underline disabled:[&_svg]:opacity-30 disabled:cursor-not-allowed [&_svg]:shrink-0 dark:[&_svg]:text-white bg-linear-to-b from-primary/90 to-primary hover:bg-primary-hover text-white disabled:opacity-60 disabled:text-white dark:disabled:text-white border border-primary-border shadow-ui-md inset-shadow-2xs inset-shadow-white/25 disabled:inset-shadow-none dark:disabled:inset-shadow-none [&_svg]:text-white [&_svg]:opacity-60 h-10 text-sm gap-2 rounded-r-none pr-3 pl-4 focus:z-10 ap-new-note-btn"
            >
                New Note
            </button>
            <button 
                type="button" 
                @click="$emit('toggle-dropdown')" 
                class="relative inline-flex items-center justify-center whitespace-nowrap shrink-0 font-medium antialiased cursor-pointer no-underline disabled:[&_svg]:opacity-30 disabled:cursor-not-allowed [&_svg]:shrink-0 dark:[&_svg]:text-white bg-linear-to-b from-primary/90 to-primary hover:bg-primary-hover text-white disabled:opacity-60 disabled:text-white dark:disabled:text-white border border-primary-border shadow-ui-md inset-shadow-2xs inset-shadow-white/25 disabled:inset-shadow-none dark:disabled:inset-shadow-none [&_svg]:text-white [&_svg]:opacity-60 h-10 text-sm gap-2 rounded-r-lg rounded-l-none px-2 border-l border-primary-border/20 -ml-px flex items-center focus:z-10"
            >
                <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                </svg>
            </button>
            <div v-if="showNewDropdown" class="absolute right-0 w-48 mt-2 origin-top-right border divide-y rounded-md shadow-lg outline-none z-50 py-1 ap-dropdown-menu" style="top: 2.75em; text-align: left;">
                <a href="#" @click.prevent="$emit('create-poll')" class="block px-4 py-2 text-sm ap-dropdown-item">New Poll</a>
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
.ap-new-note-btn {
    padding-left: 0.75rem;
    border-top-left-radius: 0.5rem !important;
    border-bottom-left-radius: 0.5rem !important;
}

.ap-dropdown-menu {
    background-color: white;
    border-color: #f3f4f6; /* gray-100 */
}
.ap-dropdown-item {
    color: #374151; /* gray-700 */
}
.ap-dropdown-item:hover {
    background-color: #f3f4f6; /* gray-100 */
}

/* Dark Mode Overrides */
html.dark .ap-dropdown-menu,
html.is-dark .ap-dropdown-menu,
html.isdark .ap-dropdown-menu {
    background-color: #1f2937; /* gray-800 */
    border-color: #374151; /* gray-700 */
    color: #e5e7eb; /* gray-200 */
}

html.dark .ap-dropdown-item,
html.is-dark .ap-dropdown-item,
html.isdark .ap-dropdown-item {
    color: #e5e7eb; /* gray-200 */
}

html.dark .ap-dropdown-item:hover,
html.is-dark .ap-dropdown-item:hover,
html.isdark .ap-dropdown-item:hover {
    background-color: #374151; /* gray-700 */
}
</style>
