import '../css/cp.css';
import ActivityPubInbox from './components/ActivityPubInbox.vue';
import ActivityPubLog from './components/ActivityPubLog.vue';
import ActorSelector from './components/ActorSelector.vue';

Statamic.booting(() => {
    Statamic.$components.register('activity-pub-inbox', ActivityPubInbox);
    Statamic.$components.register('activity-pub-log', ActivityPubLog);
    Statamic.$components.register('actor_selector-fieldtype', ActorSelector);
});
