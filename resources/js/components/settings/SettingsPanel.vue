<template>
    <div class="card p-0 h-full">
        <div class="p-4 border-b dark:border-dark-800" v-if="$slots.header || title">
            <h2 class="font-bold text-lg" v-if="title">{{ title }}</h2>
            <p class="text-sm text-gray-500 mt-1" v-if="description">{{ description }}</p>
            <slot name="header"></slot>
        </div>
        <div class="p-4 space-y-4">
            <slot></slot>
        </div>
        <div class="p-4 bg-gray-50 dark:bg-dark-600 border-t dark:border-dark-800" v-if="$slots.footer">
            <slot name="footer"></slot>
        </div>
    </div>
</template>

<script>
export default {
    props: {
        title: String,
        description: String
    }
}
</script>
