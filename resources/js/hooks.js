/**
 * ActivityPub Vue Hook / Action System
 * 
 * Allows addons to register components to specific UI locations in the ActivityPub Core.
 * Standard naming convention: {context}-{component}-{location}
 */

class HookRegistry {
    constructor() {
        this.hooks = {};
    }

    /**
     * Register a component to a hook
     * @param {string} hookName - Name of the hook (e.g., 'inbox-note-content')
     * @param {string} componentName - Name of the Vue component
     * @param {object} props - Default props for the component
     * @param {number} priority - Lower numbers run first
     */
    register(hookName, component, props = {}, priority = 10) {
        if (!this.hooks[hookName]) {
            this.hooks[hookName] = [];
        }

        // Handle object signature: register(name, { component, props, priority })
        if (typeof component === 'object' && component.component) {
            const config = component;
            this.hooks[hookName].push({
                component: config.component,
                props: config.props || {},
                priority: config.priority || 10
            });
        } else {
            // Handle positional signature: register(name, component, props, priority)
            this.hooks[hookName].push({
                component: component,
                props: props,
                priority: priority
            });
        }

        // Sort by priority
        this.hooks[hookName].sort((a, b) => a.priority - b.priority);
    }

    /**
     * Get all components registered for a hook
     * @param {string} hookName 
     * @returns {Array}
     */
    get(hookName) {
        return this.hooks[hookName] || [];
    }
}

export default new HookRegistry();
