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
- [ ] **Dead Letter Queue (DLQ) Management** (Topic #8)
    - [ ] Commands to retry/flush failed jobs
    - [ ] `php artisan activitypub:retry-failed`

### Hash Tags Support
- [ ] Add hash tag support to notes
- [ ] Add hash tag support to quotes
- [ ] Add hash tag support to Actor profiles

## Medium Priority
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
