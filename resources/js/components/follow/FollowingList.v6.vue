<template>
    <div class="@container/panel relative bg-gray-150 dark:bg-gray-950/35 dark:inset-shadow-2xs dark:inset-shadow-black w-full rounded-2xl mb-8 max-[600px]:p-1.25 p-1.75 [&:has(>[data-ui-panel-header])]:pt-0 focus-none starting-style-transition">
        <header class="px-4 py-2"><span class="font-bold">{{ title }}</span></header> 
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
                            <div v-else class="w-8 h-8 rounded-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center text-xs text-gray-500">
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
    name: 'FollowingListV6',
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
