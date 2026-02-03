<template>
    <div>
        <div v-if="open" style="position:fixed;inset:0;z-index:9999">
            <!-- Backdrop -->
            <div class="ap-stack-overlay" style="position:absolute;inset:0" @click="$emit('closed')"></div>
            <!-- Drawer panel (right-aligned, below nav bar) -->
            <div
                class="ap-stack-panel"
                style="position:absolute;top:0.5rem;bottom:0.5rem;right:0.5rem;width:100%;max-width:48rem"
                @click.stop
            >
                <!-- Header -->
                <div v-if="title" class="ap-stack-header">
                    <h2 class="ap-stack-title">{{ title }}</h2>
                    <button @click="$emit('closed')" class="ap-stack-close-btn">
                        <svg style="height:1rem;width:1rem" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>
                <!-- Content -->
                <div class="ap-stack-content" :style="{ padding: inset ? '0' : '1.5rem' }">
                    <slot />
                </div>
                <!-- Footer -->
                <div v-if="$slots['footer-end']" class="ap-stack-footer">
                    <slot name="footer-end" />
                </div>
            </div>
        </div>
    </div>
</template>

<style>
/* Layout */
.ap-stack-panel {
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border-radius: 0.75rem;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    background-color: #fff;
}
.ap-stack-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.75rem 1.5rem;
    flex-shrink: 0;
    border-bottom: 1px solid #e5e7eb;
}
.ap-stack-title {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    color: #111827;
}
.ap-stack-close-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0.25rem;
    border: none;
    background: none;
    border-radius: 0.375rem;
    cursor: pointer;
    color: #9ca3af;
}
.ap-stack-close-btn:hover {
    color: #4b5563;
    background-color: #f3f4f6;
}
.ap-stack-content {
    flex: 1;
    overflow-y: auto;
}
.ap-stack-footer {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
    padding: 1rem;
    flex-shrink: 0;
    border-top: 1px solid #e5e7eb;
    background-color: #f9fafb;
}
.ap-stack-overlay {
    background: rgba(17, 24, 39, 0.5);
}

/* Dark mode */
html.dark .ap-stack-overlay,
html.is-dark .ap-stack-overlay,
html.isdark .ap-stack-overlay {
    background: rgba(17, 24, 39, 0.7);
}
html.dark .ap-stack-panel,
html.is-dark .ap-stack-panel,
html.isdark .ap-stack-panel {
    background-color: #111827;
}
html.dark .ap-stack-header,
html.is-dark .ap-stack-header,
html.isdark .ap-stack-header {
    border-bottom-color: #1f2937;
}
html.dark .ap-stack-title,
html.is-dark .ap-stack-title,
html.isdark .ap-stack-title {
    color: #f3f4f6;
}
html.dark .ap-stack-close-btn,
html.is-dark .ap-stack-close-btn,
html.isdark .ap-stack-close-btn {
    color: #6b7280;
}
html.dark .ap-stack-close-btn:hover,
html.is-dark .ap-stack-close-btn:hover,
html.isdark .ap-stack-close-btn:hover {
    color: #d1d5db;
    background-color: #1f2937;
}
html.dark .ap-stack-footer,
html.is-dark .ap-stack-footer,
html.isdark .ap-stack-footer {
    background-color: #1f2937;
    border-top-color: #1f2937;
}
</style>

<script>
export default {
    props: {
        open: { type: Boolean, default: false },
        title: { type: String, default: '' },
        inset: { type: Boolean, default: false },
    },
    emits: ['closed'],
    mounted() {
        // Portal to document.body to escape stacking contexts.
        // Statamic 5: #main has position:relative + z-index:1, trapping fixed children.
        // Statamic 6: portal-targets and other CP wrappers can interfere similarly.
        document.body.appendChild(this.$el);
    },
    watch: {
        open(val) {
            if (val) {
                document.body.style.overflow = 'hidden';
                this._escHandler = (e) => { if (e.key === 'Escape') this.$emit('closed'); };
                document.addEventListener('keydown', this._escHandler);
            } else {
                document.body.style.overflow = '';
                if (this._escHandler) document.removeEventListener('keydown', this._escHandler);
            }
        }
    },
    beforeUnmount() {
        document.body.style.overflow = '';
        if (this._escHandler) document.removeEventListener('keydown', this._escHandler);
        if (this.$el && this.$el.parentNode) {
            this.$el.parentNode.removeChild(this.$el);
        }
    }
};
</script>
