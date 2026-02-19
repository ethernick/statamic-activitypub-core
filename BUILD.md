# Statamic 5/6 Dual-Build System

This addon uses a dual-build system to support both Statamic 5 (Vue 2) and Statamic 6 (Vue 3).

## Build Commands

All build commands should be run from the addon directory:

```bash
cd addons/ethernick/ActivityPubCore
```

### Development
```bash
npm run dev
```
Uses Vite hot reload (Vue 2 for current Statamic 5 environment)

### Production Builds

**Build both versions (default):**
```bash
npm run build
```
This builds both Vue 2 (v5) and Vue 3 (v6) versions.

**Build for Statamic 5 only (Vue 2):**
```bash
npm run build:v5
```

**Build for Statamic 6 only (Vue 3):**
```bash
npm run build:v6
# or use the helper script:
./bin/build-v6.sh
```
The helper script temporarily swaps Vue 2 for Vue 3, builds, then restores Vue 2.

## Output Directories

- `dist/v5/` - Vue 2 build (Statamic 5.x)
- `dist/v6/` - Vue 3 build (Statamic 6.x)

## How It Works

The `ActivityPubServiceProvider` automatically detects the Statamic version at runtime and loads the appropriate assets:

```php
$version = \Statamic\Statamic::version();
$isV6 = version_compare($version, '6.0.0', '>=');
$distPath = $isV6 ? 'v6' : 'v5';
```

## Publishing Assets

After building, publish assets to the public directory:

```bash
php artisan vendor:publish --tag=activitypub
```

## Why Two Separate Builds?

Vue 2 and Vue 3 have incompatible compiled outputs. The `@vitejs/plugin-vue` (Vue 3) requires the `vue` package (v3.x) in dependencies, while `@vitejs/plugin-vue2` requires Vue 2.x. Since we need Vue 2 as the primary dependency for Statamic 5, we use the `build-v6.sh` script to temporarily swap dependencies for the Vue 3 build.
