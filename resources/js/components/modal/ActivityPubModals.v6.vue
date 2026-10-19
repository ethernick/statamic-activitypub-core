<template>
    <div class="activitypub-modals">
        <activitypub-hook-loader
            name="activitypub-modals"
            :props="resolvedProps"
            @submit-success="$emit('submit-success', $event)"
            @vote="$emit('vote', $event)"
            @close="$emit('close', $event)"
        />
    </div>
</template>

<script>
export default {
    name: 'ActivityPubModals',
    props: {
        actors: {
            type: Array,
            default: () => []
        },
        props: {
            type: Object,
            default: () => ({})
        }
    },
    emits: ['submit-success', 'vote', 'close'],
    computed: {
        resolvedProps() {
            return {
                actors: this.actors,
                ...this.props
            };
        }
    }
}
</script>
