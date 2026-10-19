import '../css/cp.css';
import Inbox from './components/inbox/Inbox.vue';
import InboxLog from './components/inbox/InboxLog.vue';
import ActorSelector from './components/ActorSelector.vue';
import Settings from './components/settings/Settings.vue';
import ActivityPubFollowing from './components/follow/Following.vue';
import ActivityPubFollowers from './components/follow/Followers.vue';
import QueueStatus from './components/QueueStatus.vue';
import ActorLookup from './components/tools/ActorLookup.vue';
import InboxStack from './components/inbox/InboxStack.vue';
import InboxNote from './components/inbox/InboxNote.vue';
import InboxActivity from './components/inbox/InboxActivity.vue';
import ActivityPubPostCard from './components/feed/ActivityPubPostCard.vue';
import ActivityPubModals from './components/modal/ActivityPubModals.vue';
import HookLoader from './components/HookLoader.vue';
import hooks from './hooks';

const boot = () => {
    if (typeof Statamic !== 'undefined') {
        // Expose hooks and bus globally
        // Vue 2 uses a function constructor, Vue 3 uses a simple emitter.
        const bus = (typeof Vue === 'function') ? new Vue() : {
            events: {},
            emit(event, ...args) {
                (this.events[event] || []).forEach(cb => cb(...args));
            },
            on(event, cb) {
                (this.events[event] = this.events[event] || []).push(cb);
            },
            off(event, cb) {
                this.events[event] = (this.events[event] || []).filter(h => h !== cb);
            },
            has(event) {
                return (this.events[event] || []).length > 0;
            }
        };

        // Normalize Vue 2 bus to have emit/on/off (without $)
        if (typeof Vue === 'function') {
            bus.emit = bus.$emit.bind(bus);
            bus.on = bus.$on.bind(bus);
            bus.off = bus.$off.bind(bus);
            bus.has = (event) => (bus._events[event] || []).length > 0;
        }

        Statamic.$activitypub = {
            hooks: hooks,
            bus: bus
        };

        Statamic.booting(() => {
            Statamic.$components.register('inbox-stack', InboxStack);
            Statamic.$components.register('actor_selector-fieldtype', ActorSelector);
            Statamic.$components.register('queue-status', QueueStatus);

            // Canonical activitypub-* components with backward-compatible activity-pub-* aliases
            const registered = [
                ['activitypub-hook-loader', 'activity-pub-hook-loader', HookLoader],
                ['activitypub-post-card', 'activity-pub-post-card', ActivityPubPostCard],
                ['activitypub-modals', 'activity-pub-modals', ActivityPubModals],
                ['activitypub-modal-host', 'activity-pub-modal-host', ActivityPubModals],
                ['activitypub-inbox', 'activity-pub-inbox', Inbox],
                ['activitypub-log', 'activity-pub-log', InboxLog],
                ['activitypub-settings', 'activity-pub-settings', Settings],
                ['activitypub-following', 'activity-pub-following', ActivityPubFollowing],
                ['activitypub-followers', 'activity-pub-followers', ActivityPubFollowers],
                ['activitypub-inbox-note', 'activity-pub-inbox-note', InboxNote],
                ['activitypub-inbox-activity', 'activity-pub-inbox-activity', InboxActivity],
                ['activitypub-actor-lookup', 'activity-pub-actor-lookup', ActorLookup],
            ];

            registered.forEach(([canonical, alias, comp]) => {
                Statamic.$components.register(canonical, comp);
                Statamic.$components.register(alias, comp);
            });
        });
    } else {
        setTimeout(boot, 10);
    }
};

boot();
