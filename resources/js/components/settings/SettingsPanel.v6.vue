<template>
    <div class="@container/panel relative bg-gray-150 dark:bg-gray-950/35 dark:inset-shadow-2xs dark:inset-shadow-black w-full rounded-2xl mb-8 max-[600px]:p-1.25 p-1.75 [&:has(>[data-ui-panel-header])]:pt-0 focus-none starting-style-transition">
        <div v-if="$slots.header || title">
        <header class="py-2 px-4">
            <span class="font-bold settings-panel-title" v-if="title">{{ title }}</span>
            <span class="text-xs text-gray-500 settings-panel-description" v-if="description">{{ description }}</span>
            <slot name="header"></slot>
        </header>
        </div>
        <slot v-if="$slots.table" name="table"></slot>
        <div v-else class="h-auto visible transition-[height,visibility] duration-[250ms,2s]">
             <div class="bg-white dark:bg-gray-850 rounded-xl ring ring-gray-200 dark:ring-x-0 dark:ring-b-0 dark:ring-gray-700/80 shadow-ui-md px-4 sm:px-4.5 py-5 space-y-6">
                <div class="space-y-4">
                    <slot></slot>
                </div>
                <div v-if="$slots.footer" class="pt-4 border-t dark:border-gray-700">
                    <slot name="footer"></slot>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
.settings-panel-description {
    padding-left: 0.5em;
}
</style>

<script>
import { defineComponent } from 'vue';

export default defineComponent({
    props: {
        title: String,
        description: String
    }
});
</script>
