<template>
    <div class="card p-0 mb-4">
        <div class="flex items-center justify-between p-3 border-b">
            <h2 class="font-bold text-lg">{{ title }}</h2>
        </div>
        
        <table class="data-table">
            <thead>
                <tr>
                    <th>Actor</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="actors.length === 0">
                    <td colspan="2" class="text-gray-500 text-center py-4">{{ emptyText }}</td>
                </tr>
                <tr v-else v-for="actor in actors" :key="actor.id">
                    <td>
                        <div class="flex items-center gap-2">
                            <img v-if="actor.avatar" :src="actor.avatar" class="w-8 h-8 rounded-full object-cover">
                            <div v-else class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center text-xs text-gray-500">
                                {{ actor.title.charAt(0) }}
                            </div>
                            <div>
                                <div class="font-bold">{{ actor.title }}</div>
                                <div class="text-xs text-gray-500">{{ actor.activitypub_id }}</div>
                            </div>
                        </div>
                    </td>
                    <td class="text-right">
                        <div class="flex gap-2 justify-end">
                            <slot name="actions" :actor="actor"></slot>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<script>
export default {
    props: {
        title: {
            type: String,
            required: true
        },
        actors: {
            type: Array,
            default: () => []
        },
        emptyText: {
            type: String,
            default: 'No actors found.'
        }
    }
}
</script>
