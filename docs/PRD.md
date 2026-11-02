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

### 1. Activity vs Content Distinction (Inbox)
The Inbox will distinguish between "Content" (Notes, Articles, Polls) and "Activities" (Arrive, Like, Follow, etc.).
- **Content:** Remains the primary, dominant presentation.
- **Activities:** Handled by a foundational `InboxActivity` component with two modes:
  - **Major Mode:** For significant activities like Arrive, Travel, Offer, Listen.
  - **Minor Mode:** For utility activities like Follow, Like, Boost, Undo, Delete (minimized presentation).

### 2. Component Naming Standards
To ensure clarity between different presentation types, the following suffixes are used:
- `*Form.vue`: Creation/Update forms.
- `*Stack.vue`: Drawers, slide-overs, or modal stacks.
- `*Activity*.vue`: ActivityPub Activity specialized presentations.
- No suffix: Stream/Feed presentation components.

### 2. Technical Decisions
#### Reference Resolution (Internal vs External)
To minimize database overhead and handle Statamic's URI-based routing, the core uses a dual-lookup strategy:
- **External (Explicit):** Entries from Fediverse instances are identified by their unique `activitypub_id` (URL).
- **Internal (Mathematical/Parsed):** Local Statamic entries omit the `activitypub_id` field. Instead, inbound references (e.g., `inReplyTo: /polls/slug`) are resolved by parsing the URI, stripping the base domain, and performing a slug-based database query. Actor mentions (e.g., `@nick`) are matched by mathematically constructing the actor's handle URL from the local site configuration.

#### Storage Architecture: Sidecar JSON Files (Completed)
To optimize Stache cache performance and prevent `.md` file bloat, ActivityPub JSON representations are split into independent `.json` sidecar files stored alongside the Markdown entries.
- **Generation:** Hooks into `EntrySaved` to write the JSON to a `.json` file at the entry's disk path, removing it from the entry's internal data.
- **Serving:** `NegotiateActivityPubResponse` reads directly from the `.json` file, bypassing Statamic's data layer entirely.
- **Migration:** A non-destructive batch migration command (`activitypub:migrate-json`) extracts `activitypub_json` from existing `.md` files into `.json` sidecars in chunked, delayed batches.
- **Testing:** Comprehensive test suites cover generation, response negotiation, and the batch migration process.

#### Reusable Cross-Addon Post Presentation (`<activity-pub-post-card>`)
To prevent markup and CSS divergence across satellite addons (`Places`, `Events`, `Questions`), the presentational representation of an ActivityPub feed item is extracted into a globally registered core component: `<activity-pub-post-card>`.
- **Decoupled Presentational Component**: Unlike `InboxNote.vue` (which couples presentation to inbox-specific reply drawers, composer buses, and Mastodon actions), `<activity-pub-post-card>` is a pure presentation container with configurable `readonly` mode.
- **Dynamic Hook Slots**: Exposes standardized hook locations (`post-card-badge`, `post-card-body-extra`, `post-card-meta`, `post-card-actions`) allowing any satellite addon in the ecosystem to inject specialized UI (e.g., event dates, RSVPs, poll votes, or venue directions) into any feed.
- **Dynamic Hook Bridging**: Automatically evaluates `inbox-activity-[Type]` (e.g. `inbox-activity-Arrive` check-in badges, `inbox-activity-Question` poll ballots) and `inbox-note-content`, allowing type-specific presentation widgets to render across any feed without redundant hook registrations.
- **Scoped Slots**: Exposes `#title`, `#meta`, `#secondary-actions`, `#actions`, and `#after-body` to allow consumers to override, customize, or suppress specific rows (such as suppressing duplicate secondary bars on the Events Agenda).
- **Standardized Backend Feed Transformer**: A shared transformer method `ActivityPubUtils::transformEntryForFeed()` provides a consistent schema across core Inbox and secondary feeds.

#### Universal Modals (`<activitypub-modals>`) & Cross-Addon Stack Orchestration (RFC / Planned v1.1)
To eliminate the "Ghost Modal" problem—where interaction triggers on cross-pollinated cards fail because the corresponding drawer/modal stack is not mounted on the active page (e.g. an Event card on the Places dashboard attempting to open `EventDetailsStack`):
- **Centralized Mounting Component**: Core will provide `<activitypub-modals>` globally in the CP (with `<activitypub-modal-host>` and `<activity-pub-modal-host>` as backward-compatible aliases).
- **Universal Hook Point (`activitypub-modals`)**: All satellite addons register their slide-over drawer stacks and modal managers to `activitypub-modals` (with legacy backward-compatibility for `inbox-modals`).
- **Zero-Coupling Dashboard Integration**: Dashboards across the ecosystem (`PlacesDashboard`, `EventsDashboard`, `PollDashboard`, `Inbox`) mount `<activitypub-modals>` once at their template root instead of importing view-specific `<*-inbox-modals>` components.
- **Namespaced Event Bus Communication**: Stacks remain dormant in memory until triggered via global event bus channels (`activitypub:events:open-details`, `activitypub:places:open-checkin`, `activitypub:places:open-map`, `activitypub:polls:open-drawer`).

### 5. Commercial & Pricing Model
To maximize developer adoption while building a sustainable commercial model for agencies and publishers:
- **Free Tier (Single Actor)**: 100% Free & Open Source under MIT license. Allows single personal blogs or site-level nodes (`@site@domain.com` or 1 author actor) with full outbox, inbox, webfinger, signature verification, and basic CP features.
- **Pro Tier (Multi-Actor - $79)**: Commercial site license ($79 on Statamic Marketplace). Unlocks unlimited local actors/authors, per-author handle routing, team publishing controls, and priority queue handling.
- **Ecosystem Gating**: Extension addons (`Questions`, `Places`, `Events`) inherit the multi-actor state directly from Core via `ActivityPubCore::isMultiActorEnabled()`, centralizing license verification in one place.

---

### 6. Work Log / Session History
- **2026-03-02**: Implemented DLQ Management CLI and UI. Added "Actor Lookup" tool (renamed from Utilities). Resolved critical environment issues: downgraded to Vite 6 to fix Vue 2 compiler crashes and implemented string-based routes in `cp.php` to bypass PHP parser bugs. All 121 tests passing.
- **2026-03-05**: Audited `activitypub_collections` taxonomy usage. Confirmed hybrid approach: taxonomy for outbox distribution and external actor classification, but hard-coded relationship fields for local actor profiles.
- **2026-03-06**: Overhauled Tag Input UX. Implemented comma-based multi-splitting, auto-commit on form submission, automated backend term creation for manual tags, and polished dark mode UI visibility for the tag selector. Added tag chicklets to the InboxFeed for better context. Verified across Note, Poll, Quote, and Reply variants.
- **2026-03-14**: Audited "Advanced ActivityPub Experimentation" tasks. Verified manual JSON override injection, validation logic in `ActivityPubListener`, and propagation from objects to activities. Confirmed 100% test coverage for these features.
- **2026-03-22**: Resolved critical poll federation blockers. Implemented aggressive UUID slug generation for new polls to ensure unique IDs. Fixed vote tallying by introducing robust local URI resolution in `PollVoteListener` and `InboxHandler`. Verified that vote tallying triggers `Update` activities. Added regression tests for handle-based mentions (`@nick`) and local URI replies.
- **2026-03-25**: Implemented centralized `ActivityPubNav` service for priority-aware navigation. Refactored Core and Questions addons to use the new registry, fixing ordering issues where Polls appeared before Inbox. Established a modular, hierarchical registration system for all future ActivityPub menu items. Verified correct sorting (Inbox: 10, Polls: 20) in the CP.
- **2026-04-01**: Hardened Blocklist Protection. Implemented granular handle-based blocking and Webfinger resolution. Added automated "self-healing" protections (auto-block on 410 Gone / Suspended). Normalized and implemented alias-aware mention detection in `InboxHandler` and `NoteController` (Handle URL, Statamic URL, and AP ID matching). Fixed critical test regressions including missing migrations and JSON override logic. Verified with 237 passing tests.
- **2026-06-09**: Storage Architecture: Sidecar JSON Migration. Implemented the unified `ActivityPubPayload` domain abstraction, separating ActivityPub JSON storage from Statamic's inline markdown front-matter into sidecar `.json` files. Updated core and ingress code references, including Questions and Places addons. Fixed an unassigned `$apJson` variable scoping bug in `InboxController.php`. Created the `activitypub:migrate-json` command supporting `--dry-run`, chunking, and millisecond delays. Wrote comprehensive unit tests in `SidecarMigrationTest`, verifying sidecar storage lifecycle, manual CP override, middleware negotiation, and migration command. All 281 tests passing.
- **2026-06-10**: Actor Profile Avatar Self-Healing. Refactored the `GenerateActorAvatar` listener to expose a public static `generate(Entry $entry): bool` helper method. Updated `ActivityPubObjectTransformer::getActorIcon` to try on-demand generation when the static public image file under `public/activitypub/avatars/` is missing (e.g. after clean deployments). Aligned the transformer's fallback asset lookup to search under the `assets::` container prefix when simple filename values are saved. Wrote regression tests verifying on-demand generation and path prefixing. All 283 tests passing.
- **2026-06-11**: Optimized JSON Migration & Bulk Cleanup Performance. Restructured `activitypub:migrate-json` command to target only ActivityPub-enabled collections and run collection-by-collection in chunks, drastically reducing scan overhead and preventing memory exhaustion. Fixed `activitypub:clean` and `CleanOldActivityPubData` by querying `where('is_internal', '!=', true)` to target federated entries that omit the `is_internal` field. Optimized the mass deletion process by replacing individual `$entry->delete()` lifecycle calls with direct filesystem deletes (unlinking target `.md` and `.json` sidecar files), followed by a single `Stache::clear()` cache rebuild at the end. Fixed a critical production error where `in_reply_to` array values triggered an "Array to string conversion" exception in `ProtectPollEntries` by safely extracting the first element before running queries. Aligned `ActivityPubUtils::transformActorForCp` avatar resolving to check under the `assets::` container prefix so that downloaded federated actor avatars successfully load in the Control Panel Inbox feed rather than falling back to Gravatar placeholders. All 284 tests passing.
- **2026-08-09**: Packagist & Statamic Marketplace Launch Planning Session. Conducted technical test audit, documentation review, growth marketing analysis, and commercial strategy evaluation. Recommended a 100% free MIT core package on Packagist/Marketplace paired with paid first-party extensions (LongFormat, Questions, Places) licensed via Statamic's native marketplace licensing. Structured release preparation tasks in `TASKS.md` broken into Agent vs. Administrator (User) responsibilities. Verified complete test suite passing (292 tests, 925 assertions).
- **2026-08-23**: Task 2.1 Packagist & Marketplace Metadata Optimization. Refactored `addons/ethernick/ActivityPubCore/composer.json` with Packagist-standard schema fields: explicit keywords, homepage, author profile, funding links, issue/docs support links, type `statamic-addon`, and explicit version constraints (`php: ^8.2`, `statamic/cms: ^5.0 | ^6.0`, `illuminate/contracts: ^11.0 | ^12.0`). Validated schema via `composer validate` and verified all 308 tests passing (972 assertions).
- **2026-09-07**: Tasks 2.2 & 2.3 Overhaul.
  - *Task 2.2 (Developer Guide)*: Replaced the brief Vue-only `HOOKS.md` with an extensive technical guide covering `ActivityPubTypes` registry, all backend lifecycle hooks (`registerStoreHook`, priority `registerOutboxHook`, `registerPreloadHook`, `registerTransformHook`), `OutboxHandlerInterface` contracts, `ActivityPubNav`, Vue hooks, Mermaid sequence & architecture diagrams, and a runnable `Event` addon example.
  - *Task 2.3 (Marketplace README)*: Replaced legacy `README.md` with a visual Marketplace-ready overhaul featuring official Statamic 5/6, PHP, Fediverse, and MIT badges, feature comparison grid, 3-step quickstart, Mermaid system architecture diagram, modular addon links, and documentation links.
- **2026-09-12**: Phase 2 Completion & Phase 3 Launch Blueprint.
  - *Task 2.4 (Operator & Troubleshooting)*: Authored `docs/INSTALLATION.md` and `docs/TROUBLESHOOTING.md` covering system requirements, Supervisor queue setup, Webfinger routing (Nginx/Apache/Caddy/Cloudflare), diagnostic artisan commands, and resolution matrices for signature stripping, clock skew, and asset cache issues.
  - *Task 2.5 (Federation Guide)*: Authored `docs/FEDERATION_GUIDE.md` detailing actor handle architectures (`@blog@domain.com` vs `@user@domain.com`), 3-tier moderation architecture with automated self-healing (auto-block on HTTP 410 Gone / suspended flags), Redis vs. Database queue concurrency strategies, sidecar `.json` storage architecture, and scheduled `activitypub:clean` retention policies.
  - *Task 2.6 (Installer Automation)*: Enhanced `activitypub:install` to automatically detect/create Laravel queue tables (`jobs`, `failed_jobs`), publish `config/activitypub.php`, run addon migrations, compile dual-bundle frontend assets, provision collection blueprints, and associate an initial local actor. Streamlined `docs/INSTALLATION.md` to a guided 2-step setup (`composer require` + `activitypub:install`). Added comprehensive end-to-end testing in `FreshInstallationTest`.
  - *Task 3.1 (Marketplace Launch Blueprint)*: Authored `docs/MARKETPLACE_LAUNCH.md` defining 1200x630 hero banner creative specs (palette, typography, dual-logo glow), 4 high-converting CP screenshot punch-lists (Inbox feed, Actor profile, Queue dashboard, Blocklist UI), a 15-second animated demo storyboard (CP Note publish -> Outbox worker -> Mastodon feed -> Reply sync back to CP), and the complete 5-minute creator onboarding guide and marketplace copy pack.
  - Updated `README.md` with links to all new guides. All tests passing.
- **2026-09-25**: Inbox Stream Search & Filtering Architecture Planning.
  - Outlined 4-pillar search and stream filtering roadmap:
    1. *Free-text Search*: Real-time / debounce string search across post bodies and object summaries.
    2. *Actor Search (`@handle`)*: Filtering stream to specific local or remote ActivityPub actors. Author header elements will be clickable to trigger this filter with zero intrusive link styling (cursor pointer only).
    3. *Hashtag Search (`#tag`)*: Tag-based stream filtering matching taxonomy terms and body hashtags.
    4. *Type Filtering UX*: Identified design tension between adding a "Type" dropdown next to "New Note" (creates visual clutter) vs. supporting inline query syntax (`type:event`, `type:place`) or evolving the `[Entries | Activities | Mentions]` toggle. Documented options in `TASKS.md` for future execution.
- **2026-09-28**: Cross-Addon Feed Standardization & Reusable Post Card Architecture.
  - Identified frontend markup duplication across satellite addons (`ActivityPubPlaces` venue stream, `ActivityPubEvents` agenda).
  - Formulated the reusable `<activity-pub-post-card>` architecture in Core to eliminate manual HTML mirroring.
  - Designed component contract supporting `readonly` presentation mode, slot hooks (`post-card-badge`, `post-card-body-extra`, `post-card-meta`, `post-card-actions`), and global Statamic component registration.
  - Standardized feed item backend transformation into `ActivityPubUtils::transformEntryForFeed()`.
  - Standardized color tokens to Statamic CP `gray-*` palette, fixing silent dark mode styling failures from invalid `dark-xxx` classes.
  - Added scoped slots (`#title`, `#meta`, `#secondary-actions`, `#actions`, `#after-body`) to `<activity-pub-post-card>` for maximum layout flexibility across addons.
  - Implemented dynamic hook bridging for `inbox-activity-[Type]` and `inbox-note-content`.
  - Refactored `EventCpController::index` to delegate to `transformEntryForFeed` and enriched the `events` transform hook.
- **2026-09-29**: Universal Modal Host & Cross-Addon Stack Orchestration Architecture.
  - Formulated RFC addressing the "Ghost Modal" problem across satellite dashboards (e.g. Event cards on Places dashboard unable to open `EventDetailsStack`).
  - Planned `<activity-pub-modal-host>` in Core, transitioning `inbox-modals` into a universal `activitypub-modals` hook point to enable seamless cross-addon slide-over drawers with zero view-level component coupling.
- **2026-09-30 (Part 2)**: Milestone v1.1 — Universal Modals & Cross-Addon Stack Orchestration (`<activitypub-modals>`).
  - Created canonical `<activitypub-modals>` (Vue 2 & Vue 3) component in Core (`resources/js/components/modal/ActivityPubModals.vue` and `.v6.vue`).
  - Registered `activitypub-modals` globally in Core's `cp.js` with backward-compatible aliases `activity-pub-modals`, `activitypub-modal-host`, and `activity-pub-modal-host`.
  - Added bidirectional hook aliasing in `HookRegistry` (`activitypub-modals` <-> `inbox-modals`), allowing legacy and new hook names to interoperate with zero breaking changes or duplicate mounting.
  - Standardized cross-addon bus events (`activitypub:events:open-details`, `activitypub:events:open-create`, `activitypub:places:open-create`, `activitypub:places:open-checkin`, `activitypub:places:open-edit`, `activitypub:places:open-map`, `activitypub:polls:open-create`, `activitypub:polls:open-edit`, `activitypub:polls:open-drawer`) alongside backward-compatible legacy bus listeners.
  - Refactored `PlacesDashboard.vue` / `.v6.vue`, `EventsDashboard.vue` / `.v6.vue`, `PollDashboard.vue` / `.v6.vue`, and `Inbox.vue` / `.v6.vue` to mount `<activitypub-modals>`.
  - Solved the "Ghost Modal" problem across the entire addon ecosystem, ensuring any event, place, or poll interaction inside any dashboard or inbox stream opens its associated slide-over stack seamlessly.
  - Rebuilt production bundles across `ActivityPubCore`, `ActivityPubPlaces`, `ActivityPubEvents`, and `ActivityPubQuestions`.
- **2026-09-30 (Part 3)**: Inbox Stream Search & Quick Filter Overhaul.
  - Implemented multi-modal query search in `InboxController::api` supporting free-text keywords, `@author` / `@handle` filtering, and `#tag` taxonomy and body filtering.
  - Added test suite coverage in `InboxApiTest.php` with unit/feature tests for keyword, author, and hashtag queries (`test_inbox_api_search_by_keyword`, `test_inbox_api_search_by_author`, `test_inbox_api_search_by_hashtag`). All tests passing.
  - Upgraded `InboxFilterBar.vue` and `InboxFilterBar.v6.vue` with an integrated search box featuring a search icon, clean clear button (`✕`), 300ms debounce, and responsive light/dark mode styling.
  - Connected author and tag elements across `ActivityPubPostCard.vue` / `.v6.vue`, `InboxNote.vue`, and `InboxActivity.vue` / `.v6.vue` with click-to-filter handlers emitting `activitypub:inbox:filter` onto the global event bus.
  - Updated `Inbox.vue` and `Inbox.v6.vue` to listen to the bus and update the stream reactively.
  - Successfully compiled Vue 2 and Vue 3 production bundles and published assets to the public vendor directory.
- **2026-10-01**: Performance & Resource Optimization (Low-Resource Environments).
  - Designed and executed 3-phase optimization plan for low-resource servers (1–2 vCPUs, 1–2 GB RAM, SQLite / flat-file Stache):
    - *Phase 1 (Network Latency & Timeout Protection)*: Added 3-second connect timeout and 24-hour caching to `WebfingerService`. Parallelized link preview and OEmbed extraction in `InboxController::batchEnrichment` via `Http::pool()` with individual preview caching. Lowered default outbound concurrency to `3` and timeout to `15s`.
    - *Phase 2 (Asynchronous Media & Schedule Maintenance)*: Replaced blocking remote avatar downloads in `ActorResolver` with background queued job `DownloadActorAvatar`. Registered `activitypub:maintenance` daily schedule in `ActivityPubServiceProvider`. Extended `CleanOldActivityPubData` to prune stale failed jobs, auto-block logs, and orphaned sidecar `.json` files, and invoked `$entry->delete()` to ensure Stache synchronization.
    - *Phase 3 (Database & Index Optimizations)*: Time-boxed memory searches and mention filters in `InboxController::api` to the entry retention window (minimum 90 days) to prevent unbounded memory spikes on flat-file Stache. Implemented save-time mention extraction and indexing (`parseMentions`) in `ActivityPubListener` and handle resolution in `ActorResolver::resolveByHandle()`, speeding up mentions stream filtering and eliminating $O(N)$ text parsing. All unit and integration tests passing.
- **2026-10-02**: Alternative Actor Types Architecture & Commercial Tier Packaging ($79 Pro/Teams).
  - Expanded ActivityPub actor modeling beyond individual `Person` to full W3C ActivityStreams 2.0 actor spectrum: `Organization`, `Service` (Automated Bot), `Application`, and `Group`.
  - Created `Ethernick\ActivityPubCore\Types\Organization` and `Ethernick\ActivityPubCore\Types\Service` classes registered with `ActivityPhp\Type` and `ActivityPubTypes`.
  - Updated `actors.yaml` blueprint template with an `actor_type` selector providing user-friendly descriptions and validation.
  - Updated `ActivityPubObjectTransformer` to serialize actor payloads dynamically based on `actor_type` with backward-compatible fallback to `Person`.
  - Updated `InboxHandler::handleUpdateActivity` and `ActorResolver` to detect and persist remote actor types.
  - Enriched `ActivityPubUtils::transformActorForCp` with `actor_type` to enable front-end badging (e.g. automated bot indicators in CP streams).
  - Verified with new unit test in `ActorJsonTest::test_actor_json_supports_alternative_actor_types`. All tests passing.
  - Transitioned the entire ActivityPub addon ecosystem from MIT to a **Statamic Commercial License (Source-Available)**: All repositories remain 100% public on GitHub for maximum developer portfolio and calling card impact, with `"license": "proprietary"` in `composer.json` granting free local development/testing and requiring a paid Statamic Marketplace license for production deployments.
- **2026-10-03**: Release Performance Hardening & Stache Field Indexing.
  - Automatically indexed key ActivityPub fields (`type`, `actor`, `user`, `author`, `tags`, `activitypub_id`, `in_reply_to`, `is_internal`, `mentioned_urls`) in `CustomCollectionEntriesStore::storeIndexes()` across all ActivityPub collections.
  - Converted inbox search and mentions queries in `InboxController::api` from full in-memory collection scans (`->get()->filter(...)`) directly onto the Stache query builder (`whereJsonOverlaps()`, `whereIn()`, `paginate()`).
  - Benchmarked query speed improvements from ~330ms to ~57ms on actor queries and ~165ms on tag queries while eliminating memory bloat on large collections.
  - Verified 100% test suite passing (275 tests, 843 assertions).
- **2026-10-04**: Two-Tier Feed Caching & Actor Fast-Lookup Map.
  - Implemented two-tier card architecture separating immutable content compilation (Markdown to HTML, sidecars, attachments) into a cached Content Shell (`ap:card_shell:{$id}`) from volatile dynamic state (like/boost/reply counters, user flags).
  - Volatile indicators overlay in sub-millisecond runtime operations; incoming likes, boosts, and replies never invalidate or rebuild the content cache.
  - Content shell caches invalidate only upon actual content updates (`Update` activities or local CP edits).
  - Created actor fast-lookup dictionary (`ActivityPubUtils::getCachedActorMap()`) dropping author search times to sub-150ms.
  - Stream load response dropped to **~15.89ms** on cached hits. Tested with 100% passing rate.
- **2026-10-05**: Performance Test Suite Inception & UI Stream Polish.
  - Built the inaugural command of the ActivityPub performance suite: `php artisan activitypub:test:search {query?}` in [`TestSearch.php`](file:///private/srv/statamic/addons/ethernick/ActivityPubCore/src/Console/Commands/TestSearch.php).
    - Checks cold & warm latency for the actor lookup dictionary.
    - Inspects `search_tokens` index coverage across content collections with actionable backfill status warnings.
    - Benchmarks standard query types (Actor Filter, Tag Filter, Keyword Search, Multi-word query) and optional custom query parameters.
    - Added automated verification in [`TestSearchCommandTest.php`](file:///private/srv/statamic/addons/ethernick/ActivityPubCore/src/Tests/TestSearchCommandTest.php).
  - Upgraded Inbox Stream UX & Visual Feedback:
    - Added interactive debounced spinner directly within the search bar in `InboxFilterBar.vue` and `InboxFilterBar.v6.vue`.
    - Emptied stream contents on filter/search trigger (`this.notes = []`) so the feed immediately presents the loading spinner rather than looking static.
    - Re-engineered dark-mode contrast for the inbox stream loading spinner in `InboxFeed.vue` and `InboxFeed.v6.vue` (high-contrast translucent track and vivid primary spinner) resolving visibility issues where the spinner appeared as an unmoving circle.
    - Rebuilt both Vue 2 and Vue 3 frontend distribution bundles (`npm run build`).
  - Consolidated Performance & Testing commands under the unified `activitypub:test:*` namespace:
    - `php artisan activitypub:test:search {query?}`: Benchmarks actor index latency, token index coverage %, and query execution.
    - `php artisan activitypub:test:fuzz {--count=2000} {--actors=20} {--cleanup}`: Generates realistic high-volume synthetic entries, actors, sidecars, and tokens with non-destructive `fuzz-test-*` scoping and one-command `--cleanup`.
    - `php artisan activitypub:test:oembed {url}`: Validates OEmbed and link preview resolution (with backward-compatible alias).
    - Unit tests implemented and verified (`TestSearchCommandTest.php` and `TestFuzzCommandTest.php`).
  - High-Volume Scale Benchmark & Query Short-Circuiting (5,200 Entries, 802 Actors):
    - Identified query bottleneck where unindexed `orWhere('...like...')` fallbacks forced Statamic to bypass Stache memory indexes and scan 5,200 flat YAML files across the disks, taking 103ms.
    - Updated [`InboxController.php`](file:///private/srv/statamic/addons/ethernick/ActivityPubCore/src/Http/Controllers/CP/InboxController.php) to short-circuit:
      - `@author` queries resolve through `ActorSearchIndex::findActorIds()` and execute purely against `whereJsonOverlaps('actor', $ids)`, eliminating redundant linear scans and dropping latency from **55.59ms to 16.9ms**.
      - Keyword queries execute purely as token intersections (`whereJsonOverlaps('search_tokens', [$token])`), dropping single-word and multi-word query latency from **103ms down to 10.66ms - 14.28ms** (up to 10x faster).
- **2026-10-06**: ActivityPub Date Preservation & Maintenance Stabilization.
  - Diagnosed unwanted date bumping across federated posts. Incoming `Announce` (boost) activities previously called `$originalNote->date($publishedDate)` in `InboxHandler::handleAnnounceActivity()`, causing older notes to be bumped to the boost timestamp. Removed the date modification while retaining booster logging in `boosted_by` and updating interaction counts.
  - Updated `InboxHandler::handleUpdateActivity()` so that incoming note edits record the new timestamp in `updated` while strictly preserving the initial `published` / `date` creation timestamp.
  - Hardened `ActivityPubMaintenance::fixActivityDates()`:
    - Guaranteed that external notes preserve their original `published` creation timestamp even if updated, while syncing the `updated` field if present in the sidecar payload.
    - Verified that activities prioritize outer activity `published` dates and only fall back to nested objects when appropriate.
    - Cleaned up obsolete inline methods, delegating bulk count rebuilds cleanly to the queued `RecalculateActivityPubCounts` job.
  - Implemented Strict Date Immutability, Type-Level Substantive Fields & Modular Broadcast Policies:
    - In `ActivityPubListener::handleEntrySaving()`, existing internal entries on disk have their original `published` (`date`) creation timestamp strictly enforced and protected against overwriting by background touches or saves.
    - Decoupled `updated` in `ActivityPubObjectTransformer::buildBaseInfo()` from disk file modification time (`$entry->lastModified()`). `updated` is now purely driven by the entry's explicit `updated` field, which only gets populated when substantive content (body, title, summary, sensitive toggle, attachments) or registered type-level substantive fields genuinely change.
    - Extensible Substantive Field Registration (`ActivityPubTypes::register(..., substantiveFields: [...])`): Allowed modular addons to register type-specific fields (e.g. `['options', 'voters_count', 'closed', 'end_time']` in `ActivityPubQuestions`) that constitute genuine content changes triggering `Update` activities and `updated` timestamps without altering the base note creation date.
    - Extensible Broadcast Policies & Supplements:
      - Introduced contextual suppression via `$entry->setSupplement('activitypub_suppress_broadcast', true)` to suppress activity emission without touching flat-file YAML.
      - Introduced `ActivityPubTypes::registerBroadcastPolicy(callable $policy)` allowing addons to hook into outbox activity generation and suppress delivery dynamically.
    - In `AutoGenerateActivityListener::handleSaved()`, background metadata touches (e.g. updating `reply_count`, `boost_count`, or `search_tokens`) are gated from emitting unnecessary `Update` activities to fediverse followers.
  - Added comprehensive regression testing in `AutoGenerateActivityTest` (covering background touch suppression, substantive field detection, broadcast policies and supplements), `InboxHandlerTest`, and `FixActivityDatesTest`. All test suites passing with 100% green status.
  - Hardened Inbox Search API & Stache Lookups:
    - **Empty Prefix Guarding:** Resolved issue where typing `#` or `@` caused empty fallback queries (`tags like '%%'`) that returned all collection entries. Queries with empty symbol prefixes now cleanly match zero results (`where('id', '___MATCH_FAIL___')`).
    - **Pure ActivityPub Handle Indexing (`ActorSearchIndex`):** Indexed actors by their ActivityPub handles (`@username@domain` and short `@username`), actor UUIDs, and slugs without coupling to Statamic user models. Searching `@me` matches all federated instances (e.g. `@me@statamic.local`, `@me@domain1.com`), while searching full handles `@me@statamic.local` pinpoints specific actors.
    - **Unified Keyword & Author Querying:** Enhanced search in `InboxController::api` so that typing an actor handle with or without `@` resolves via `ActorSearchIndex`, and content keywords resolve via `search_tokens`.
    - Added automated unit tests in `InboxApiTest` (`test_inbox_api_search_empty_symbols_returns_empty` and `test_inbox_api_search_by_actor_handle`). All 10 tests passing (40 assertions).
- **2026-10-19**: Phase 5.1 Quality Assurance & Release Gates Stabilization (`v0.9.0` Milestone).
  - Resolved Intervention Image driver incompatibility in `GenerateActorAvatar`:
    - Added dynamic detection for both Intervention Image v3 (`$manager->read()`, `toJpeg()`) and v4 (`$manager->decodePath()`, `$image->save()`) with graceful GD extension fallback.
    - Fixed `ActorJsonTest::test_actor_json_icon_resolves_asset_id` and `test_actor_json_icon_generates_and_resolves_without_container_prefix`.
  - Fixed ActivityStreams 2.0 `Update` activity wrapper serialization:
    - Updated `ActivityPubObjectTransformer::wrapInActivity()` to ensure the outer `Update` activity wrapper carries the `updated` timestamp synchronized with the modified object payload.
    - Fixed `OutboxJsonValidationTest::test_update_activity_wraps_note_with_updated_field`.
  - Enforced test sandbox isolation & non-destructive compliance:
    - Integrated `ProvidesSandbox` trait and `setupCollections(['actors', 'activities'])` into `ActorJsonTest`.
    - Scoped all created/deleted test actors to unique `test-actor-*` slugs, safeguarding local actor flat-file collections from being cleared during test runs.
  - Aligned addon ecosystem licensing disclosures and pricing:
    - Badged `ActivityPubCore` at `v0.9.0` with clear $0 Single-Actor / $79 Multi-Actor commercial tiers.
    - Badged companion extensions `ActivityPubQuestions`, `ActivityPubPlaces`, and `ActivityPubEvents` at `v0.1.0` Early Adopter Labs ($10 Companion tier).
    - Positioned experimental addons (`ActivityPubLongFormat`, `ActivityPubDiscussions`, `ActivityPubReads`, `ActivityPubPhotos`, `ActivityPubPerspective`, `ActivityPubBookmarks`, `ActivityPubMusic`) at `dev` status under the Statamic Commercial License.
  - Full test suite verified: **339/339 tests passing (1,168 assertions)** across all addons with exit code `0`.
- *Consult `docs/sessions/` for detailed logs of specific development sessions.*



