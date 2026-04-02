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
- [x] **Robust Tag Input UX Overhaul**
    - [x] Multi-tag splitting (comma-delimited) via `@input` splitting
    - [x] Auto-commit remaining field text on form submission
    - [x] Automatic Statamic Taxonomy term creation for manual tags
    - [x] Unified logic for hashtags and manual tags via `ensureTermsExist`
- [x] **Settings UI for Taxonomy/Field configuration**

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
- **Hashtag & Tag Overhaul**: Automatic extraction of #hashtags and robust manual tag input with multi-splitting, auto-commit on save, and automatic Statamic Taxonomy term creation.

### Current Focus (v1.0 Release Candidate)
- Polishing the Control Panel UI to ensure it feels native to both Statamic 5 and 6.
- Adding a simple dashboard for administrators to monitor the health of their outbound messages.
- Writing plain-English documentation focused on creators and admins.

### Future (v1.x+)
- Support for advanced Fediverse features (e.g., private posts, lists).
- Integration with external community tools (Webhooks).

## Appendix

- `addons/ethernick/ActivityPubQuestions/docs/PRD.md` - Poll addon docs.

### 2. Technical Decisions
#### Reference Resolution (Internal vs External)
To minimize database overhead and handle Statamic's URI-based routing, the core uses a dual-lookup strategy:
- **External (Explicit):** Entries from Fediverse instances are identified by their unique `activitypub_id` (URL).
- **Internal (Mathematical/Parsed):** Local Statamic entries omit the `activitypub_id` field. Instead, inbound references (e.g., `inReplyTo: /polls/slug`) are resolved by parsing the URI, stripping the base domain, and performing a slug-based database query. Actor mentions (e.g., `@nick`) are matched by mathematically constructing the actor's handle URL from the local site configuration.

### 3. Work Log / Session History
- **2026-03-02**: Implemented DLQ Management CLI and UI. Added "Actor Lookup" tool (renamed from Utilities). Resolved critical environment issues: downgraded to Vite 6 to fix Vue 2 compiler crashes and implemented string-based routes in `cp.php` to bypass PHP parser bugs. All 121 tests passing.
- **2026-03-05**: Audited `activitypub_collections` taxonomy usage. Confirmed hybrid approach: taxonomy for outbox distribution and external actor classification, but hard-coded relationship fields for local actor profiles.
- **2026-03-06**: Overhauled Tag Input UX. Implemented comma-based multi-splitting, auto-commit on form submission, automated backend term creation for manual tags, and polished dark mode UI visibility for the tag selector. Added tag chicklets to the InboxFeed for better context. Verified across Note, Poll, Quote, and Reply variants.
- **2026-03-14**: Audited "Advanced ActivityPub Experimentation" tasks. Verified manual JSON override injection, validation logic in `ActivityPubListener`, and propagation from objects to activities. Confirmed 100% test coverage for these features.
- **2026-03-22**: Resolved critical poll federation blockers. Implemented aggressive UUID slug generation for new polls to ensure unique IDs. Fixed vote tallying by introducing robust local URI resolution in `PollVoteListener` and `InboxHandler`. Verified that vote tallying triggers `Update` activities. Added regression tests for handle-based mentions (`@nick`) and local URI replies.
- **2026-03-25**: Implemented centralized `ActivityPubNav` service for priority-aware navigation. Refactored Core and Questions addons to use the new registry, fixing ordering issues where Polls appeared before Inbox. Established a modular, hierarchical registration system for all future ActivityPub menu items. Verified correct sorting (Inbox: 10, Polls: 20) in the CP.
- **2026-04-01**: Hardened Blocklist Protection. Implemented granular handle-based blocking and Webfinger resolution. Added automated "self-healing" protections (auto-block on 410 Gone / Suspended). Normalized and implemented alias-aware mention detection in `InboxHandler` and `NoteController` (Handle URL, Statamic URL, and AP ID matching). Fixed critical test regressions including missing migrations and JSON override logic. Verified with 237 passing tests.
- *Consult `docs/sessions/` for detailed logs of specific development sessions.*

