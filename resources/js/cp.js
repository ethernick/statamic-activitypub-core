import '../css/cp.css';
import Inbox from './components/inbox/Inbox.vue';
import InboxLog from './components/inbox/InboxLog.vue';
import ActorSelector from './components/ActorSelector.vue';
import Settings from './components/settings/Settings.vue';
import ActivityPubFollowing from './components/follow/Following.vue';
import ActivityPubFollowers from './components/follow/Followers.vue';

const boot = () => {
    if (typeof Statamic !== 'undefined') {
        Statamic.booting(() => {
            Statamic.$components.register('activity-pub-inbox', Inbox);
            Statamic.$components.register('activity-pub-log', InboxLog);
            Statamic.$components.register('actor_selector-fieldtype', ActorSelector);
            Statamic.$components.register('activity-pub-settings', Settings);
            Statamic.$components.register('activity-pub-following', ActivityPubFollowing);
            Statamic.$components.register('activity-pub-followers', ActivityPubFollowers);
        });
    } else {
        setTimeout(boot, 10);
    }
};

boot();
