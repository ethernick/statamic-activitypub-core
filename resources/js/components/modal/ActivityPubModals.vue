<template>
    <div class="activitypub-modals">
        <activitypub-hook-loader
            name="activitypub-modals"
            :props="resolvedProps"
            v-on="$listeners"
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
