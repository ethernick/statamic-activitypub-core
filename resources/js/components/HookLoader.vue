<template>
    <div class="activitypub-hooks">
        <component 
            v-for="(hook, idx) in registeredHooks" 
            :key="idx"
            :is="hook.component"
            v-bind="{ ...hook.props, ...props }"
            v-on="$listeners"
        />
    </div>
</template>

<script>
export default {
    name: 'HookLoader',
    props: {
        name: {
            type: String,
            required: true
        },
        props: {
            type: Object,
            default: () => ({})
        }
    },
    computed: {
        registeredHooks() {
            if (typeof Statamic === 'undefined' || !Statamic.$activitypub) return [];
            return Statamic.$activitypub.hooks.get(this.name);
        }
    }
}
</script>
