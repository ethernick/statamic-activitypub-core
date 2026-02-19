<template>
    <div class="actor-selector-wrapper">
        <select v-model="selected" class="input-text w-full">
            <option v-if="!meta.actors || meta.actors.length === 0" disabled value="">{{ __('No actors found') }}</option>
            <option v-for="actor in meta.actors" :key="actor.value" :value="actor.value">{{ actor.label }}</option>
        </select>
    </div>
</template>

<script>
const Fieldtype = window.__STATAMIC__?.core?.FieldtypeMixin || {};

export default {
    mixins: [Fieldtype],
    
    props: {
        modelValue: {
            default: undefined
        }
    },

    data() {
        return {
            selected: this.modelValue !== undefined ? this.modelValue : this.value,
        };
    },

    watch: {
        value(val) {
            this.selected = val;
        },
        modelValue(val) {
            this.selected = val;
        },
        selected(val) {
            this.update(val);
            this.$emit('update:modelValue', val);
        }
    }
};
</script>
