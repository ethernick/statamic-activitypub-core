# Product Requirements Document: ActivityPubCore

## 1. Problem
Currently, Statamic creators operate on isolated websites. They spend time crafting content but lack a native way to distribute it to the broader, decentralized web (the Fediverse). To build an audience, they must rely on third-party tools, manual cross-posting, or walled gardens like Twitter/X. Furthermore, their audience on platforms like Mastodon cannot seamlessly interact with their Statamic content directly from their own feeds.

## 2. Users & JTBD
**Primary Users:** Content Creators (Bloggers, Journalists, Brands) and Site Administrators running Statamic.

**Jobs to Be Done:**
- As a content creator, I want to automatically publish my posts to the Fediverse so that I can reach a wider audience without leaving my Statamic control panel.
- As a site visitor or Fediverse user, I want to reply to, like, or share Statamic content directly from my ActivityPub platform (e.g., Mastodon, Pixelfed) so that I can engage seamlessly.
- As a site administrator, I want to manage my site's federation settings and monitor activity effortlessly so that my server isn't bogged down by external traffic.

## 3. MVP Scope
**In Scope:**
- Seamless publishing of core content types from Statamic to the Fediverse.
- Receiving replies, likes, and follows from Fediverse users directly into the Statamic Control Panel.
- A user-friendly Activity Inbox within the Statamic CP to view incoming interactions.
- Automatic retry of failed message deliveries to ensure broad reach.
- Extensibility for future addons (like long-form articles or polls) to plug into this core ecosystem.

**Out of Scope (Non-goals):**
- Complex Fediverse moderation tools (e.g., managing custom interaction policies or instance blocking) in the first release.
- A public API for third-party headless apps.

## 4. Key Flows

### Flow 1: Publishing Content to the Fediverse
1. The creator writes and publishes a new entry in Statamic as they normally would.
2. The system automatically converts the post into a Fediverse-friendly format.
3. The post is distributed to all of the creator's Fediverse followers in the background without any extra clicks.
4. The creator can see the status of the delivery if they choose to check.

### Flow 2: Engaging with the Audience 
1. A Fediverse user (e.g., on Mastodon) sees the creator's post and replies to it.
2. The reply is securely received by the Statamic site.
3. The creator logs into the Statamic Control Panel and sees a notification or an entry in their new "Activity Inbox."
4. The creator can read the reply and understand how their content is performing.

## 5. Success Metrics
- **Primary:** 
  - Successful syndication rate (percentage of posts successfully delivered to followers).
  - Engagement tracking (number of inbound replies/likes successfully processed and displayed).
- **Secondary:** 
  - Zero disruption to the creator's existing publishing workflow.
  - Zero performance degradation in the Statamic Control Panel.

## 6. Risks & Mitigations
- **Spam and Unwanted Interactions (High Impact):** As the site opens up to the Fediverse, malicious actors could flood the inbox. Mitigation: Implement strict inbound verification and provide basic filtering/blocking mechanisms early.
- **Server Load from High Follower Counts (Medium Impact):** A popular creator could overwhelm their own site when broadcasting a post. Mitigation: Process all federation tasks reliably in the background without affecting the live site's speed.
- **Creator Confusion (Low Impact):** Creators might not understand what "Federation" means. Mitigation: Use clear, non-technical language in the UI (e.g., "Share to Fediverse" instead of "ActivityPub Outbox").

## 7. Acceptance Criteria
- A creator can publish a standard Statamic entry and see it appear on a Mastodon test account.
- A creator can reply to that post from Mastodon and see the reply appear in the Statamic Control Panel.
- The UI must look and feel like a native Statamic feature, blending perfectly with the V5/V6 design language.
- The system handles momentary offline states of follower servers gracefully without losing the creator's post.

## 8. Product Roadmap

### Completed 
- Basic capability to send and receive messages from the Fediverse.
- Background processing to keep the site fast.
- Security checks to ensure messages are genuine.

### Current Focus (v1.0 Release Candidate)
- Polishing the Control Panel UI to ensure it feels native to both Statamic 5 and 6.
- Adding a simple dashboard for administrators to monitor the health of their outbound messages.
- Writing plain-English documentation focused on creators and admins.

### Future (v1.x+)
- Support for advanced Fediverse features (e.g., private posts, lists).
- Integration with external community tools (Webhooks).

## Appendix

### 1. Supplemental Documentation
- `addons/ethernick/ActivityPubLongFormat/docs/PRD.md` - Article addon docs.
- `addons/ethernick/ActivityPubQuestions/docs/PRD.md` - Poll addon docs.

### 2. Work Log / Session History
- **2026-03-02**: Implemented DLQ Management CLI and UI. Added "Actor Lookup" tool (renamed from Utilities). Resolved critical environment issues: downgraded to Vite 6 to fix Vue 2 compiler crashes and implemented string-based routes in `cp.php` to bypass PHP parser bugs. All 121 tests passing.
- *Consult `docs/session/` for detailed logs of specific development sessions.*
