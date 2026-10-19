# ActivityPubCore Task List & Roadmap

> **Legend**
> - [ ] To Do
> - [/] In Progress
> - [x] Done

---

## 1. Core Architecture & Quality Hardening (v1.0 RC)

### 1.1 Code Quality, Types & Test Standards
- [x] **Add Type Hints & Strict Types** (Topic #12)
  - [x] Add `declare(strict_types=1);` to all PHP files
  - [x] Add return types and param types across all core services
  - [x] Update all tests for PHPUnit 12 using PHP 8 attributes
  - [x] Fix nullable parameter bugs in `ActivityPubListener::sanitizeUrl()` and `extractMentions()`
  - [x] Eliminate double event registration in `ActivityPubServiceProvider`
- [x] **Quote Authorization Test Suite** (FEP-044f)
  - [x] `SendQuoteRequestJobTest`, `AcceptControllerTest`, `QuoteJsonOutputTest`, `ActivityPubListenerQuoteTest`
  - [x] Full regression test stabilization (88/88 tests passing)
- [x] **Standalone Package & Fresh Installation Test Suite**
  - [x] Verify config publishing (`activitypub.php`) and default option resolution
  - [x] Verify zero-config initialization without requiring pre-existing content collections
  - [x] Verify service provider boot sequence in isolation (`FreshInstallationTest`)
- [x] **Extension API & Interoperability Test Suite**
  - [x] Verify `ActivityPubTypes::register()` with custom content types and duplicate registrations
  - [x] Verify `SavingHook` registration and payload transformer execution order
  - [x] Verify fallback handling when an unknown addon type is received in inbox (`ExtensionRegistrationTest`)
- [x] **HTTP Signature Security & Edge Case Test Suite**
  - [x] Clock skew window validation (`Date` header drift beyond ±300 seconds)
  - [x] Rejection of expired or tampered Digest / RSA-SHA256 headers
  - [x] Rate-limiting behavior on `sharedInbox` endpoints under burst requests (`HttpSignatureSecurityTest`)

### 1.2 Statamic 6.x Compatibility & Dual-Build Pipeline
- [x] **Dual-Build Setup**
  - [x] Install `vite-plugin-vue2` for legacy Statamic 5 support
  - [x] Configure `vite.config.js` with custom version-resolver plugin (`.v6.vue` overrides)
  - [x] Update `package.json` scripts (`build:v5`, `build`) with `bin/build-v6.sh` automated version swapper
- [x] **Runtime Detection & Assets**
  - [x] Update `ActivityPubServiceProvider` to detect Statamic version (`5.x` vs `6.x`)
  - [x] Implement conditional asset publishing and script loading (`dist/v5` vs `dist/v6`)
- [x] **Component Audit**
  - [x] Verify `v-model` behavior in custom components (`ActorSelector.vue` for S6 API)
  - [x] Normalize axios access across versions (`this.$axios` vs `Statamic.$axios`)
  - [x] Fix null date formatting in `InboxController`

### 1.3 Storage Architecture: Sidecar JSON Migration
- [x] **Core Event & Middleware Updates**
  - [x] Update `ActivityPubListener` (`handleEntrySaving` / `handleEntrySaved`) to write sidecar `.json` files and strip inline `activitypub_json` from Entry data
  - [x] Update `NegotiateActivityPubResponse` to read payloads directly from companion `.json` sidecar files
  - [x] Update `BaseObjectController` and ingress points to write incoming payloads to `.json` sidecars
- [x] **CP & Blueprints Integration**
  - [x] Adapt `activitypub_json` field injection in `ActivityPubListener@handleBlueprintFound`
  - [x] Populate manual JSON override code editor directly from sidecar file and persist changes on save
- [x] **Data Migration & Safety**
  - [x] Create batch migration command (`php artisan activitypub:migrate-json`) with chunking, batching, sleep delays, and `--dry-run` flag
  - [x] Add `ProvidesSandbox` compliance tests ensuring safe JSON extraction without mutating other content

---

## 2. CP Presentation & Cross-Addon Framework

### 2.1 Reusable Post Card & Modal Orchestration
- [x] **Reusable Post Card Component (`<activity-pub-post-card>`)**
  - [x] Create `ActivityPubPostCard.vue` (Vue 2) and `ActivityPubPostCard.v6.vue` (Vue 3) in `resources/js/components/feed/`
  - [x] Register `activitypub-post-card` globally in `cp.js` (with backward-compatible `activity-pub-post-card` alias)
  - [x] Support `readonly` prop (default `false`) to cleanly hide interactive action bars in satellite feeds
  - [x] Standardize color tokens to Statamic CP `gray-*` palette (fixing dark mode contrast issues)
  - [x] Provide scoped slots (`#title`, `#meta`, `#secondary-actions`, `#actions`, `#after-body`)
  - [x] Hook loader extension points: `post-card-badge`, `post-card-body-extra`, `post-card-meta`, `post-card-actions`, `inbox-activity-[Type]`, `inbox-note-content`
  - [x] Implement backend normalization helper `ActivityPubUtils::transformEntryForFeed()`
- [x] **Universal Modals & Stack Orchestration (`<activitypub-modals>`)**
  - [x] Create `ActivityPubModals.vue` (Vue 2) and `ActivityPubModals.v6.vue` (Vue 3) in `resources/js/components/modal/`
  - [x] Register `activitypub-modals` globally with aliases `activity-pub-modals`, `activitypub-modal-host`, `inbox-modals`
  - [x] Standardize global event bus namespacing (`activitypub:events:open-details`, `activitypub:places:open-checkin`, `activitypub:places:open-map`, `activitypub:polls:open-drawer`)
  - [x] Refactor Places, Events, and Poll dashboards to mount `<activitypub-modals>`

### 2.2 Inbox Stream, Filtering & Search
- [x] **Inbox Stream Search & Filtering**
  - [x] **Content Search**: Real-time debounced query across titles, contents, and summaries
  - [x] **Author Filter (`@handle`)**: Autocomplete / handle search filtering stream to specific actor; clickable author names, avatars, and handles on post cards
  - [x] **Hashtag Filter (`#tag`)**: Search posts by hashtag across content and taxonomies; clickable hashtags on post cards
- [x] **Tag Input & Taxonomy Overhaul**
  - [x] Multi-tag comma splitting on input and auto-commit on submission
  - [x] Automatic Statamic taxonomy term creation for manual tags via `ensureTermsExist`
  - [x] Settings UI for hashtag taxonomy and field binding
  - [x] Tag support across notes, quotes, and actor profiles

---

## 3. Moderation, Operations & Performance Tuning

### 3.1 Moderation & Blocklist System
- [x] **Granular Block List & Identity Checks**
  - [x] Support for individual handles (`@user@domain`), Actor URLs, and entire domains
  - [x] Webfinger resolution for manual handle blocking with alias resolution
  - [x] Automated self-healing blocks on HTTP 410 Gone / Suspension detection
  - [x] Database-backed storage (`activity_pub_blocklist_entries`) with zero-loss migration
- [x] **Record-Based Blocklist UI (Settings CP)**
  - [x] Decouple blocklist from general settings save payload (resolving HTTP 413 Entity Too Large errors)
  - [x] Dedicated "Block Domain" and "Block User / Actor" forms with inline **Bulk Import** textareas (one entry per line)
  - [x] Searchable, paginated Active Blocklist table with discrete grey delete action and clean text type indicators
  - [x] Auto-Block audit log viewer with pagination and log clearing

### 3.2 Observability & Maintenance Operations
- [x] **Queue Monitoring & DLQ Dashboard**
  - [x] Queue status view in CP with pending and failed job breakdown
  - [x] Retry and flush controls by queue type (`activitypub:retry-failed`, "Retry All ActivityPub", "Flush All ActivityPub")
- [x] **Scheduled & On-Demand Data Pruning**
  - [x] Scheduled cleanup job (`CleanOldActivityPubData`) pruning expired activities, notes, auto-block logs, stale `failed_jobs`, and orphaned JSON sidecars
  - [x] **CP Manual Maintenance Trigger**: "Run Maintenance Now" button under Settings → Maintenance with real-time console feedback
  - [x] Synchronized Stache repository eviction via explicit `$entry->delete()` in `ActivityPubClean`
- [x] **Actor Lookup Utility**
  - [x] Interactive CP Webfinger and Actor Lookup tool (`cp/activitypub/actor-lookup`)

### 3.3 Low-Resource Environment Performance Tuning
- [x] **Zero-Risk Quick Wins**
  - [x] 3-second timeout and 24-hour cache layer on `WebfingerService::resolve()`
  - [x] Concurrent link preview and oEmbed fetching via `Http::pool()` with 24-hour caching
  - [x] Low-resource config defaults: HTTP concurrency set to `3` and socket timeout to `15s`
- [x] **Performance Testing & Benchmarking Suite (`activitypub:test:*`)**
  - [x] Implemented `activitypub:test:search` benchmark tool (Actor lookup map latency, search token coverage %, query execution timings)
  - [x] Implemented `activitypub:test:fuzz` synthetic scale & fuzz data generator with non-destructive cleanup (`--cleanup`)
  - [x] Standardized OEmbed diagnostic command under `activitypub:test:oembed` (with backward-compatible alias)
  - [x] Automated test coverage in `TestSearchCommandTest.php` and `TestFuzzCommandTest.php`
- [x] **Asynchronous Operations**
  - [x] Extracted remote avatar downloads into queued background job (`DownloadActorAvatar`) with immediate remote URL fallback
- [x] **Query & Flat-File Bounds**
  - [x] Time-boxed in-memory searches and mention queries in `InboxController::api` to retention window (minimum 90 days)
  - [x] Pre-indexed mention URLs (`mentioned_urls`) at save time via `ActivityPubListener`
  - [x] 60-day query bounds on `$userBoosts` lookup to prevent full flat-file scans on feed load
  - [x] Short-circuited `@author` and keyword searches in `InboxController` to pure indexed lookups (`whereJsonOverlaps`), dropping 5,000-entry latency from 103ms to ~10-16ms
  - [x] Optimized `ActorSearchIndex::getLookupMap()` to extract raw YAML properties directly, preventing expensive full presenter builds across 800+ actors

---

## 4. Packaging, Documentation & Commercial Tier

### 4.1 Developer & Operator Documentation
- [x] **Packagist Metadata Optimization (`composer.json`)**
  - [x] Added fediverse keywords, author metadata, funding, and support links
  - [x] Enforced version constraints (PHP `^8.2`, Statamic `^5.0 | ^6.0`, Laravel `^11.0 | ^12.0`)
- [x] **Third-Party Extension Guide (`HOOKS.md`)**
  - [x] Complete documentation for `ActivityPubTypes` service registry and `SavingHook` lifecycle
- [x] **Marketplace Documentation Suite**
  - [x] Visual Marketplace `README.md` with feature grids, installation, and architecture diagrams
  - [x] Step-by-step `docs/INSTALLATION.md` and `docs/TROUBLESHOOTING.md` (Webfinger rules, signature headers, Cloudflare)
  - [x] Federation & Performance Guide (`docs/FEDERATION_GUIDE.md`)
- [x] **Automated Installation Command (`activitypub:install`)**
  - [x] Automatic config publishing and queue table detection/creation

### 4.2 Commercial Licensing & Marketplace Architecture
- [x] **Commercial Source-Available Licensing Transition**
  - [x] Converted license from MIT to `proprietary` in `composer.json` across Core and satellite addons
  - [x] Added Statamic Commercial Source-Available `LICENSE.md` (free for development/testing; commercial production requires Marketplace license; retains 100% public repository status)
- [x] **Commercial Tier: Multi-Actor & W3C Actor Types ($79 Pro/Teams)**
  - [x] Full support for W3C Actor types: `Person`, `Organization`, `Service` (Bot), `Application`, `Group`
  - [x] Actor blueprint template with `actor_type` selector and dynamic serialization in `ActivityPubObjectTransformer`
  - [x] CP representation with bot/org badges in `ActivityPubUtils::transformActorForCp`
- [x] **Launch Blueprint (`docs/MARKETPLACE_LAUNCH.md`)**
  - [x] Defined hero banner, CP screenshot specs, storyboard, and Creator onboarding guide

---

## 5. Quality Assurance & Release Gates (Pre-Launch Stabilization)

### 5.1 Immediate Test Suite Stabilization (Gate for `v0.9.0`) — Effort: ~0.5–1 Day
- [x] **Fix Intervention Image Driver Compatibility**
  - Update `GenerateActorAvatar.php` to support both Intervention Image v3 and v4 APIs (`method_exists($manager, 'read') ? $manager->read(...) : $manager->decodePath(...)`) with clean GD fallback.
  - Fixes failing tests in `ActorJsonTest`:
    - `test_actor_json_icon_resolves_asset_id`
    - `test_actor_json_icon_generates_and_resolves_without_container_prefix`
- [x] **Fix Outbox Update Activity Format**
  - Ensure the `updated` timestamp is stamped on the outer `Update` activity wrapper as well as the embedded `object` in `ActivityPubObjectTransformer.php` / outbox handler.
  - Fixes failing test in `OutboxJsonValidationTest`: `test_update_activity_format`.
- [x] **Enforce Non-Destructive Test Sandboxing on `ActorJsonTest`**
  - Add `ProvidesSandbox` trait to `ActorJsonTest.php`.
  - Scope all created/deleted test actors to unique `test-actor-*` slugs so local actor collections are never wiped during testing.
- [x] **Verify 100% Green Suite**
  - Re-run `php artisan test --filter=ActivityPub` and verify all 339+ tests pass with exit code `0`. (Passed: 339/339 tests, 1,168 assertions).

---

### 5.2 Frontend UI Smoke Suite & Build Verification (Gate for `v1.0.0-rc1`) — Effort: ~1–2 Days
- [ ] **Build Artifacts Automated Test (`BuildArtifactsTest.php`)**
  - Automated PHP/Node test executing `npm run build` and asserting:
    - `dist/v5/cp.js` and `dist/v6/cp.js` exist, are non-empty, and under 500KB.
    - Extracted stylesheets (`dist/v5/cp.css` and `dist/v6/cp.css`) exist and contain valid CSS rules.
    - Detects any accidental `"vue": "^3.*"` left in `package.json` at rest (enforcing BUILD.md Rule #1).
- [ ] **Playwright CP Browser Smoke Suite (`tests/E2E/`)**
  - Set up lightweight headless Playwright testing harness.
  - **Smoke Test 1 (Inbox Mounting)**: Log in as CP superuser, navigate to `/cp/activitypub/inbox`, and assert:
    - `<inbox-feed>` mounts without uncaught JavaScript exceptions in the browser console.
    - Filter tabs (`[Entries | Activities | Mentions]`) toggle active state.
  - **Smoke Test 2 (Reply Drawer)**: Click "Reply" on an inbox post, assert the slide-over drawer mounts, inputs accept text, and cancel closes without layout shift.
  - **Smoke Test 3 (Settings Save)**: Navigate to `/cp/activitypub/settings`, toggle a configuration switch, click "Save", and assert flash success toast.

---

### 5.3 Adversarial Protocol & Inbound Fuzz Testing (Gate for `v1.0.0`) — Effort: ~1–1.5 Days
- [ ] **Author `InboundSecurityFuzzTest.php`**
  - **Malformed JSON-LD Payloads**: Ingest payloads with missing `@context`, missing `type`, circular ID references (`inReplyTo: self`), and array-wrapped scalar fields. Assert clean HTTP 400/422 responses with zero unhandled 500 exceptions.
  - **Adversarial Ingestion & XSS Defenses**: Send notes containing `<script>alert(1)</script>`, `javascript:` URIs in links, and malformed UTF-8 byte sequences. Assert sanitization before persistence in sidecar JSON or flat-file entries.
  - **Oversized / DoS Payloads**: Ingest 5MB payload bodies and 5,000 array elements in `to`/`cc`. Assert that payload limits cleanly reject requests before database/queue allocation.
  - **Replay & Clock Skew Attacks**: Assert that HTTP signatures with `Date` headers drifting beyond ±300 seconds or replaying historical signatures are rejected instantly.

---

### 5.4 Release Execution & Marketplace Packaging — Effort: ~0.5 Day
- [ ] **Milestone 1: Tag `v0.9.0` (Public Beta / Early Adopters)**
  - Requirements: Section 5.1 complete (100% green unit/integration test suite).
  - Submit to Packagist as `0.9.0`.
  - Mark Marketplace listing as Early Adopter / Beta.
- [ ] **Milestone 2: Tag `v1.0.0-rc1` (Release Candidate)**
  - Requirements: Section 5.2 complete (Build artifact validation and Playwright UI smoke tests green).
- [ ] **Milestone 3: Tag `v1.0.0` (Commercial Gold Release)**
  - Requirements: Section 5.3 complete (Adversarial fuzz tests passing, live Webfinger verification complete).
- [ ] **Marketplace Distribution Punchlist**
  - [ ] Submit `ethernick/statamic-activitypub-core` to [Packagist.org](https://packagist.org)
  - [ ] Configure GitHub repository webhook for automatic package updates on git tag releases
  - [ ] Configure Statamic Marketplace listings (Single-Actor Free / Multi-Actor $79)
  - [ ] Upload launch artwork (Hero banner, CP screenshots, logo icon)
  - [ ] Verify production Webfinger resolution (`curl https://domain.com/.well-known/webfinger?resource=acct:handle@domain.com`)

---

## 6. Future Roadmap (Post-v1.0)

- [ ] **Queue Priority Support** (Topic #13)
  - Route high-priority immediate interactions (Follows, Direct Replies) ahead of bulk federated blasts
- [ ] **Activity Deduplication** (Topic #14)
  - Deduplicate redundant incoming activities from shared relay deliveries
- [ ] **Webhook Support** (Topic #15)
  - Outbound webhooks for external third-party integrations on received Activities
- [ ] **Automated Performance Testing Suite** (Topic #16)
  - Continuous benchmark suite measuring memory and response latency under high activity volume
- [ ] **Type Filtering Strategy in Inbox Feed**
  - *Option A (Advanced Search Syntax)*: Integrated search queries like `type:note`, `type:event`, `type:place`, `type:poll`
  - *Option B (Segmented Pill / Filter Tabs)*: Extending `[Entries | Activities | Mentions]` toggle or introducing a secondary compact filter dropdown without header clutter
