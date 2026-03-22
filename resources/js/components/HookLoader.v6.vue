<template>
    <div class="activitypub-hooks">
        <component 
            v-for="(hook, idx) in registeredHooks" 
            :key="idx"
            :is="hook.component"
            v-bind="{ ...hook.props, ...props }"
            @submit-success="$emit('submit-success')"
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
    emits: ['submit-success'],
    computed: {
        registeredHooks() {
            if (typeof Statamic === 'undefined' || !Statamic.$activitypub) return [];
            return Statamic.$activitypub.hooks.get(this.name);
        }
    }
}
</script>
