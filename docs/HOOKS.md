# Statamic ActivityPub Frontend Hooks

This document lists the available Vue frontend hooks for injecting components into the ActivityPub inbox and other UI locations.

## Registering Hooks

Addons should register hooks when the Statamic CP boots. The hook registry is available globally at `Statamic.$activitypub.hooks`.

```javascript
Statamic.$activitypub.hooks.register('hook-name', {
    component: MyVueComponent,
    priority: 10, // Higher numbers run later
    props: {
        // Optional static props
    }
});
```

## Available Hooks

### `inbox-note-content`
Injected into the content area of an `InboxNote.vue`, below the main text and above attachments.

- **Purpose**: Render type-specific UI (e.g., Poll voting, Article previews).
- **Props**:
    - `note`: The ActivityPub object data.
    - `permissions`: User permissions object.
- **Events**:
    - `vote`: Emitted when a vote is cast (expects `{ note, option, callback }`).

### `inbox-note-actions`
Injected into the action bar of an `InboxNote.vue`.

- **Purpose**: Add custom action buttons.
- **Props**:
    - `note`: The ActivityPub object data.
    - `permissions`: User permissions object.

---

## Technical Implementation

Hooks are rendered using the `activity-pub-hook-loader` component:

```html
<activity-pub-hook-loader 
    name="inbox-note-content" 
    :props="{ note, permissions }"
/>
```
