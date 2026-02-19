<template>
    <header class="flex flex-wrap items-center justify-between gap-4 px-2 sm:px-0 py-6 max-md:pb-8 md:py-8">
        <h1 class="text-[25px] leading-[1.25] st-text-legibility font-medium antialiased flex items-center gap-2.5 md:flex-1">
            ActivityPub Settings
        </h1>
        <div class="flex items-center gap-2">
            <span v-if="success" class="text-green-600 text-sm font-medium animate-pulse">Saved</span>
            <p v-if="error" class="text-red-500 text-sm">{{ error }}</p>
            <button type="button" class="relative inline-flex items-center justify-center whitespace-nowrap shrink-0 font-medium antialiased cursor-pointer no-underline disabled:[&_svg]:opacity-30 disabled:cursor-not-allowed [&_svg]:shrink-0 dark:[&_svg]:text-white bg-linear-to-b from-primary/90 to-primary hover:bg-primary-hover text-white disabled:opacity-60 disabled:text-white dark:disabled:text-white border border-primary-border shadow-ui-md inset-shadow-2xs inset-shadow-white/25 disabled:inset-shadow-none dark:disabled:inset-shadow-none [&_svg]:text-white [&_svg]:opacity-60 px-4 h-10 text-sm gap-2 rounded-lg" @click="$emit('save')" :disabled="saving">
                {{ saving ? 'Saving...' : 'Save Settings' }}
            </button>
        </div>
    </header>
</template>

<script>
import { defineComponent } from 'vue';

export default defineComponent({
    props: {
        saving: Boolean,
        success: Boolean,
        error: String
    },
    emits: ['save']
});
</script>
