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
            }
        };

        Statamic.$activitypub = {
            hooks: hooks,
            bus: bus
        };

        Statamic.booting(() => {
            Statamic.$components.register('inbox-stack', InboxStack);
            Statamic.$components.register('activity-pub-hook-loader', HookLoader);
            Statamic.$components.register('activity-pub-inbox', Inbox);
            Statamic.$components.register('activity-pub-log', InboxLog);
            Statamic.$components.register('actor_selector-fieldtype', ActorSelector);
            Statamic.$components.register('activity-pub-settings', Settings);
            Statamic.$components.register('activity-pub-following', ActivityPubFollowing);
            Statamic.$components.register('activity-pub-followers', ActivityPubFollowers);
            Statamic.$components.register('queue-status', QueueStatus);
            Statamic.$components.register('activity-pub-actor-lookup', ActorLookup);
        });
    } else {
        setTimeout(boot, 10);
    }
};

boot();
