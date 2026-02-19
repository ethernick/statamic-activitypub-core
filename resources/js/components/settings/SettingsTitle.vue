<template>
    <header class="mb-6 flex items-center justify-between">
        <h1 class="font-bold text-2xl">ActivityPub Settings</h1>
        <div class="flex items-center gap-2">
            <span v-if="success" class="text-green-600 text-sm font-medium animate-pulse">Saved</span>
            <p v-if="error" class="text-red-500 text-sm">{{ error }}</p>
            <button type="button" @click="$emit('save')" class="btn-primary" :disabled="saving">
                {{ saving ? 'Saving...' : 'Save Settings' }}
            </button>
        </div>
    </header>
</template>

<script>
export default {
    props: {
        saving: Boolean,
        success: Boolean,
        error: String
    }
}
</script>
