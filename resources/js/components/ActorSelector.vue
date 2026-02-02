<template>
    <div class="actor-selector-wrapper">
        <v-select
            v-model="selected"
            :options="meta.actors"
            :reduce="actor => actor.value"
            label="label"
        >
            <template #no-options>
                <div class="text-sm text-gray-500 p-2">
                    {{ __('No actors found') }}
                </div>
            </template>
        </v-select>
    </div>
</template>

<script>
export default {
    mixins: [Fieldtype],
    
    data() {
        return {
            selected: this.value,
        };
    },

    watch: {
        value(val) {
            this.selected = val;
        },
        selected(val) {
            this.update(val);
        }
    },

    mounted() {
        // console.log('ActorSelector mounted');
        // console.log('Value:', this.value);
        // console.log('Meta:', this.meta);
    }
};
</script>
