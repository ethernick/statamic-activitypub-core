# Building ActivityPub Core Assets

## For Package Maintainers

Before publishing a new version to Packagist, you must build the frontend assets.

### Build Process

1. Install dependencies:
   ```bash
   npm install
   ```

2. Build production assets:
   ```bash
   npm run build
   ```

3. Verify the `dist/` directory contains:
   - `dist/js/cp.js` - Compiled JavaScript
   - `dist/css/cp.css` - Compiled CSS
   - `dist/.vite/manifest.json` - Vite manifest

4. Commit the built assets:
   ```bash
   git add dist/
   git commit -m "Build assets for release"
   ```

### Important Notes

- **The `dist/` directory must be committed to git** so users installing via Composer get the pre-built assets
- **Don't add `dist/` to `.gitignore`** - users need these files
- **Build before every release** to ensure assets are up to date

## Development vs Production

### Local Development (addons/ directory)
- Assets are served via Vite with hot reload
- No build step required
- Run `npm run dev` for hot reload during development

### Production (vendor/ directory via Composer)
- Pre-built assets from `dist/` are published to `public/vendor/activitypub/`
- Automatically published during `php artisan activitypub:install`
- Can be re-published with: `php artisan vendor:publish --tag=activitypub-assets --force`

## File Structure

```
ActivityPubCore/
├── resources/
│   ├── js/
│   │   ├── cp.js              # Entry point
│   │   └── components/        # Vue components
│   └── css/
│       └── cp.css             # Styles
├── dist/                      # Built assets (committed to git)
│   ├── js/
│   │   └── cp.js
│   ├── css/
│   │   └── cp.css
│   └── .vite/
│       └── manifest.json
├── package.json               # NPM dependencies
└── vite.config.js             # Build configuration
```

## Troubleshooting

### Assets not loading in production
1. Check `dist/` directory exists and has built files
2. Run `php artisan vendor:publish --tag=activitypub-assets --force`
3. Verify files exist in `public/vendor/activitypub/`

### Build errors
- Ensure Node.js 18+ is installed
- Delete `node_modules` and run `npm install` again
- Check for Vue component syntax errors
