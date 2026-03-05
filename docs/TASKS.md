# Active Tasks

> **Legend**
> - [ ] To Do
> - [/] In Progress
> - [x] Done

## High Priority (v1.0 Release Candidate)
### Code Quality
- [x] **Add Type Hints & Strict Types** (Topic #12)
    - [x] Add `declare(strict_types=1);` to all files
    - [x] Add return types and param types
    - [x] Status: **✅ COMPLETE** for `ActivityPubCore` (**96/96 tests passing, 100%**)
    - [x] Update all tests for PHPUnit 12 to use PHP attributes
    - [x] Fixed nullable parameter bug in `ActivityPubListener::sanitizeUrl()`
    - [x] Fixed nullable parameter bug in `ActivityPubListener::extractMentions()`
    - [x] Fixed double event registration in `ActivityPubServiceProvider`

### Statamic 6.x Compatibility
- [x] **Dual-Build Setup**
    - [x] Install `vite-plugin-vue2` for legacy support
    - [x] Configure `vite.config.js` for version switching
    - [x] Update `package.json` scripts (`build:v5`, `build:v6`)
- [x] **Runtime Detection**
    - [x] Update `ActivityPubServiceProvider` to detect version
    - [x] Implement conditional asset loading
- [x] **Component Audit**
    - [x] Verify `v-model` behavior in custom components (Fixed `ActorSelector.vue` for S6 API)
    - [x] Test cross-version compatibility for `ActivityPubInbox.vue`
    - [x] Fixed `Statamic.$axios` undefined issue in v6 by using `this.$axios`
    - [x] Fixed `InboxController` null date format error

### Observability & Operations
- [x] **Queue Monitoring Dashboard** (Topic #6)
    - [x] Create `QUEUE_STATUS` view in CP
    - [x] Show failed jobs count/details
    - [x] Added pending jobs filtering and flushing by type
- [x] **ActivityPub Utilities & Actor Lookup**
    - [x] Webfinger & Actor Lookup interface
    - [x] Renamed to "Actor Lookup" and updated path to `cp/activitypub/actor-lookup`
- [x] **Dead Letter Queue (DLQ) Management** (Topic #8)
    - [x] Commands to retry/flush failed jobs
    - [x] `php artisan activitypub:retry-failed`
    - [x] **UI Support**: Added "Retry All ActivityPub" and "Flush All ActivityPub" to Queue Dashboard

### Environmental & Build Maintenance
- [x] **Vite 6 Downgrade**: Downgraded from Vite 7 to Vite 6 to resolve Vue 2 compiler crashes (`currentInput.slice`).
- [x] **Vue 2.7 Alignment**: Pinned Vue to 2.7 in `package.json` for build stability.
- [x] **String-based Routing**: Transitioned `cp.php` to `'Controller@method'` syntax to bypass environmental `::class` ParseErrors.

## Medium Priority
### Hash Tags Support
- [ ] Add hash tag support to notes
- [ ] Add hash tag support to quotes
- [ ] Add hash tag support to Actor profiles

### Advanced ActivityPub Experimentation
- [ ] Add the ability to provide specific Activitypub JSON to notes
  - [ ] Validate proper ActivityPub JSON format before saving
- [ ] Add the ability to provide specific ActivityPub JSON to activities
  - [ ] Validate proper ActivityPub JSON format before saving

### Testing
- [x] **Quote Authorization Test Suite** (FEP-044f)
    - [x] SendQuoteRequestJobTest
    - [x] AcceptControllerTest
    - [x] QuoteJsonOutputTest
    - [x] ActivityPubListenerQuoteTest
    - [x] All regression tests fixed
    - [x] PHPUnit 12 migration (PHP 8 attributes)
    - **Status**: ✅ All 88 tests passing (299 assertions)

### Documentation
- [ ] **Performance Tuning Guide** (Topic #21)
- [ ] **Troubleshooting Guide** (Topic #22)
- [ ] **Federation Best Practices** (Topic #23)

## Future / Nice to Have
- [ ] **Queue Priority Support** (Topic #13)
- [ ] **Activity Deduplication** (Topic #14)
- [ ] **Webhook Support** (Topic #15)
- [ ] **Performance Testing Suite** (Topic #16)
