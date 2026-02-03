# Installation Instructions

## For End Users (Installing via Composer)

### 1. Install the package

```bash
composer require ethernick/statamic-activitypub-core
```

### 2. Run the installer

```bash
php artisan activitypub:install
```

The installer will automatically:
- ✅ Set up Laravel database queue tables
- ✅ Run ActivityPub migrations
- ✅ Publish frontend assets to `public/vendor/activitypub/`
- ✅ Configure collections for Person, Note, and Activity types
- ✅ Set up taxonomies and blueprints
- ✅ Optionally create your first ActivityPub profile

### 3. Start the queue worker

ActivityPub requires a queue worker to send activities to the fediverse:

```bash
php artisan queue:work
```

**For production**, use a process manager like Supervisor to keep the worker running.

## For Package Maintainers

### Before Publishing to Packagist

1. **Build the frontend assets:**
   ```bash
   cd addons/ethernick/ActivityPubCore
   npm install
   npm run build
   ```

2. **Verify dist/ directory:**
   ```bash
   ls -la dist/
   # Should contain:
   # - dist/js/cp.js
   # - dist/css/cp.css
   # - dist/.vite/manifest.json
   ```

3. **Commit the built assets:**
   ```bash
   git add dist/
   git commit -m "Build assets for release"
   ```

4. **Tag and push:**
   ```bash
   git tag v1.0.0
   git push origin main --tags
   ```

See [BUILD.md](BUILD.md) for detailed build documentation.

## Development Setup

When developing locally with the addon in `addons/ethernick/ActivityPubCore/`:

1. **No build step required** - assets are served via Vite with hot reload
2. **Run the dev server** (optional):
   ```bash
   npm run dev
   ```

The ServiceProvider automatically detects development mode and uses Vite instead of published assets.

## Troubleshooting

### "Queue tables don't exist"
Run the installer or manually create tables:
```bash
php artisan queue:table
php artisan queue:failed-table
php artisan migrate
```

### "ActivityPub assets not loading"
Re-publish the assets:
```bash
php artisan vendor:publish --tag=activitypub-assets --force
```

### "Vue components not rendering"
Ensure assets are published and cached assets are cleared:
```bash
php artisan vendor:publish --tag=activitypub-assets --force
php artisan cache:clear
php artisan view:clear
```
