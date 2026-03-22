# Build Architecture: ActivityPubCore

> **Audience:** AI agents and developers. Read this before touching `package.json`, `vite.config.js`, or any build script.

## Overview

ActivityPubCore ships **two separate JS bundles** from a single codebase:

| Bundle | Target      | Vue Version | Output Dir | Compiler Plugin       |
|--------|-------------|-------------|------------|-----------------------|
| **v5** | Statamic 5  | Vue 2.7     | `dist/v5/` | `@vitejs/plugin-vue2` |
| **v6** | Statamic 6  | Vue 3.5     | `dist/v6/` | `@vitejs/plugin-vue`  |

Both bundles are built from the same `resources/js/cp.js` entrypoint. Vue is **externalised** — Statamic provides it at runtime on `window.Vue`.

## How the Dual Build Works

```
npm run build
  ├── ./bin/build-v6.sh          # Step 1: Build Vue 3 bundle
  │     ├── npm install vue@^3   # Temporarily swap Vue dep
  │     ├── VUE_VERSION=3 vite build  → dist/v6/
  │     └── (trap EXIT) restore vue@^2 + npm install
  └── npm run build:v5           # Step 2: Build Vue 2 bundle
        └── VUE_VERSION=2 vite build  → dist/v5/
```

### Version-Specific Components

`vite.config.js` includes a `versionResolverPlugin` that automatically resolves imports to version-specific files:

```
import Inbox from './Inbox.vue'
  → VUE_VERSION=2: resolves to ./Inbox.v5.vue (if it exists), else ./Inbox.vue
  → VUE_VERSION=3: resolves to ./Inbox.v6.vue (if it exists), else ./Inbox.vue
```

**Convention:** If a component needs Vue-version-specific code, create both `Foo.vue` (base/v5) and `Foo.v6.vue` (v6 override). The base `.vue` file is **always** the Vue 2 version — there is no `.v5.vue` file by convention.

> [!IMPORTANT]
> Wait — that's not quite right. The resolver *does* look for `.v5.vue`. But in practice, the convention used in this project is: `.vue` = shared/Vue 2, `.v6.vue` = Vue 3 override. Check existing files to confirm the pattern before adding new versioned components.

## Critical Rules

### 1. Never Modify `package.json` Vue Version

`package.json` must always have `"vue": "^2.7.16"` in `dependencies`. The v6 build script temporarily swaps this to Vue 3, but **always restores it**. If you see `vue@^3.x` in `package.json` at rest, it's broken — restore it to `^2.7.16` and run `rm -rf node_modules && npm install --legacy-peer-deps`.

### 2. Never Modify `vite.config.js` to Fix Build Errors

Build errors are almost always caused by:
- **Bad Vue syntax in a `.vue` template** (unclosed tags, broken attribute bindings)
- **`package.json` left with Vue 3** after an interrupted build

They are **never** caused by `vite.config.js` being wrong. Do not add aliases, change plugins, or restructure the config to "fix" a compile error.

### 3. Never Run `npm install vue@3` Directly

Only `bin/build-v6.sh` should do this, and it has a `trap` to auto-restore. Running it manually will break the v5 build.

### 4. Template Syntax Compatibility

Vue 2 and Vue 3 templates have subtle differences. In `.vue` (base) files that are compiled by **both** versions:
- Avoid Vue 3-only syntax (`v-slot` shorthand `#`, `<Teleport>`, `<Suspense>`)
- Avoid the Composition API in base files
- Use Options API exclusively

In `.v6.vue` files (Vue 3 only):
- You can use Vue 3 features (`<template #slot>`, `<Teleport>`, etc.)
- Still use Options API for consistency (Statamic CP uses Options API)

## Common Failure Modes

### `currentInput.slice is not a function`

**Cause:** Vue 3 compiler packages are installed while `@vitejs/plugin-vue2` is trying to compile. The Vue 3 `@vue/compiler-sfc` returns a different internal structure than Vue 2's, causing the slice crash.

**Fix:** Restore `vue@^2.7.16` in `package.json`, then `rm -rf node_modules && npm install --legacy-peer-deps`.

### `Module "X.v6.vue" not found` or Wrong Version Loaded

**Cause:** A `.v6.vue` file was created but the base `.vue` file was deleted, or vice versa.

**Fix:** Ensure the base `.vue` file always exists. The `.v6.vue` is an optional override.

### Build Hangs or Fails Silently

**Cause:** `bin/build-v6.sh` lost execute permission.

**Fix:** `chmod +x bin/build-v6.sh`

## File Map

```
package.json          # vue must be ^2.7.16 at rest
vite.config.js        # DO NOT MODIFY to fix build errors
bin/build-v6.sh       # Dual-build orchestrator (has trap for safety)
dist/v5/              # Vue 2 compiled output (Statamic 5)
dist/v6/              # Vue 3 compiled output (Statamic 6)
resources/js/
  cp.js               # Entrypoint (shared)
  components/
    Foo.vue           # Base component (Vue 2 compatible)
    Foo.v6.vue        # Vue 3 override (optional)
```

## Quick Reference

```bash
# Full build (v6 + v5)
npm run build

# v5 only (useful for quick iteration on Vue 2)
npm run build:v5

# Verify package.json is clean after build
grep '"vue"' package.json
# Expected: "vue": "^2.7.16"

# Nuclear recovery if builds are broken
rm -rf node_modules
# Ensure package.json has vue@^2.7.16
npm install --legacy-peer-deps
npm run build
```
