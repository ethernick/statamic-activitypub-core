# Production Operator & Installation Guide

Complete deployment and operations manual for running **ActivityPub Core** on Statamic 5.x and 6.x (Laravel 11.x & 12.x).

---

## 📋 System Requirements

| Requirement | Supported Version | Notes |
| :--- | :--- | :--- |
| **PHP** | `^8.2` \| `^8.3` \| `^8.4` | Extensions required: `openssl`, `curl`, `json`, `mbstring`, `sqlite3` or `pdo_mysql` / `pdo_pgsql` |
| **Statamic** | `^5.0` \| `^6.0` | Flat-file Stache content or database-backed |
| **Laravel** | `^11.0` \| `^12.0` | Queue worker runtime |
| **HTTPS** | Valid TLS certificate | **Mandatory** by the ActivityPub W3C standard. Fediverse servers will reject plain HTTP requests. |
| **Queue Connection** | `database`, `redis`, or `sqs` | `sync` connection is **strongly discouraged** for production federation. |

---

## 🚀 Step-by-Step Installation

### Step 1: Require via Composer

Install the core package into your Statamic application root:

```bash
composer require ethernick/statamic-activitypub-core
```

### Step 2: Run the Interactive Installer

Run the guided installer:

```bash
php artisan activitypub:install
```

The installer automates the complete setup:
- ✅ **Database Queue Infrastructure**: Detects and creates `jobs` and `failed_jobs` migrations (if not already present) and runs database migrations.
- ✅ **Configuration Publishing**: Publishes `config/activitypub.php` for queue settings, rate limits, timeouts, and retention windows.
- ✅ **Addon Migrations**: Runs ActivityPub tables (`activitypub_inbox_failures`, `activitypub_blocks`, etc.).
- ✅ **Frontend Asset Publishing**: Automatically detects Statamic version and publishes matching Vue 2 (Statamic 5) or Vue 3 (Statamic 6) Control Panel assets to `public/vendor/`.
- ✅ **Content Collections & Blueprints**: Proposes and provisions standard collections:
  - **Person** (`actors`) — Local and cached remote Fediverse profiles.
  - **Note** (`notes`) — Short-form posts and microblog entries.
  - **Activity** (`activities`) — Federation audit trail (Follow, Like, Boost, Announce).
- ✅ **Initial Profile Creation**: Associates an existing Control Panel user with your first local Actor profile (e.g. `@editor@yourdomain.com`).

---

### Manual / Advanced Overrides (Optional)

If you ever need to re-publish individual components manually:

```bash
# Re-publish configuration only
php artisan vendor:publish --tag=activitypub-config --force

# Re-publish Control Panel assets only
php artisan vendor:publish --tag=activitypub --force
```

---

## ⚙️ Queue Worker Configuration

ActivityPub relies on asynchronous queue workers to sign outbound HTTP payloads, query remote Webfinger servers, distribute posts to remote inboxes, and receive federated activities.

### Recommended Queue Architecture

The addon registers and uses three dedicated queue tiers:

| Queue Name | Priority | Purpose | Worker Recommendation |
| :--- | :--- | :--- | :--- |
| `activitypub-outbox` | **High** | Outgoing signed HTTP POST requests to remote server inboxes. | Process immediately to maintain real-time federation feel. |
| `default` | **Medium** | General background tasks (avatar downloads, link previews, thread indexing). | Standard priority worker. |
| `maintenance` | **Low** | Batch sidecar migrations, DLQ pruning, and retention maintenance (`activitypub:clean`). | Scheduled night tasks or low-priority background worker. |

### Running the Worker Manually

```bash
php artisan queue:work --queue=activitypub-outbox,default,maintenance --tries=3 --timeout=120
```

### Production Supervisor Configuration

To ensure background workers restart automatically after server reboots or fatal crashes, configure **Supervisor**:

Create `/etc/supervisor/conf.d/statamic-activitypub.conf`:

```ini
[program:statamic-activitypub-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/statamic/artisan queue:work --queue=activitypub-outbox,default --sleep=3 --tries=3 --max-time=3600 --timeout=120
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/statamic/storage/logs/activitypub-worker.log
stopwaitsecs=120
```

Reload Supervisor:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start statamic-activitypub-worker:*
```

---

## 🌐 Web Server Routing & Webfinger Rules

The ActivityPub protocol requires that discovery endpoints respond at `/.well-known/webfinger` and that profile URLs respond to both browser visits (HTML) and Fediverse crawlers (`application/activity+json`).

Ensure your web server routes all `/.well-known/webfinger` requests to Laravel's `public/index.php` front-controller.

### Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name yourdomain.com;
    root /path/to/statamic/public;

    index index.php;

    # Explicitly ensure .well-known is not blocked by hidden-file rules
    location ^~ /.well-known/ {
        try_files $uri $uri/ /index.php?$query_string;
        allow all;
    }

    # Pass all other requests to index.php
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;

        # Ensure headers required for HTTP signature verification are passed
        fastcgi_pass_request_headers on;
    }
}
```

### Apache (`.htaccess`)

Statamic includes standard `.htaccess` rewrite rules in `public/`. Ensure `mod_rewrite` is enabled and hidden `.well-known` directories are not forbidden:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On

    # Prevent Apache from denying access to .well-known
    RewriteRule ^\.well-known/ - [L]

    # Redirect Trailing Slashes If Not A Folder...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} (.+)/$
    RewriteRule ^ %1 [L,R=301]

    # Handle Front Controller...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>

# Ensure Authorization and Date headers reach PHP-FPM
<IfModule mod_setenvif.c>
    SetEnvIfNoCase ^Authorization$ "(.+)" HTTP_AUTHORIZATION=$1
</IfModule>
```

### Caddy

```caddy
yourdomain.com {
    root * /path/to/statamic/public
    encode gzip zstd
    php_fastcgi unix//var/run/php/php8.2-fpm.sock

    # Allow discovery endpoints
    handle_path /.well-known/* {
        try_files {path} /index.php?{query}
    }

    file_server
}
```

### Cloudflare Edge Configuration

When using Cloudflare in front of your Statamic site:

1. **SSL/TLS Mode**: Must be set to **Full** or **Full (Strict)**. Flexible SSL will cause redirect loops and break HTTPS signature matching.
2. **Transform Rules / Stripped Headers**:
   - Ensure Cloudflare does not strip the incoming `Signature`, `Digest`, or `Date` request headers.
   - Do **NOT** enable Cloudflare "Bot Fight Mode" or "Under Attack Mode" on the following paths:
     - `/.well-known/webfinger`
     - `/activitypub/sharedInbox`
     - `/@*` (Actor profiles and personal inboxes)
   - Remote servers will not solve JavaScript challenges or CAPTCHAs, causing immediate `403 Forbidden` handshake failures.

---

## ⏱️ Scheduled Maintenance Tasks

Add the Laravel Scheduler to your server crontab:

```bash
* * * * * cd /path/to/statamic && php artisan schedule:run >> /dev/null 2>&1
```

The addon automatically schedules:
- **`activitypub:clean`**: Runs daily at 02:00 AM to purge expired external activities, orphan payloads, and failed inbox entries beyond the retention window (`config/activitypub.php`).
- **`activitypub:verify`**: Validates actor public/private cryptographic key integrity.

---

## 🔍 Verification & Smoke Testing

1. **Test Webfinger Resolution**:
   ```bash
   curl -i "https://yourdomain.com/.well-known/webfinger?resource=acct:editor@yourdomain.com"
   ```
   *Expected Response:* `200 OK` with `Content-Type: application/jrd+json` containing actor links.

2. **Test ActivityPub JSON Header Negotiation**:
   ```bash
   curl -i -H "Accept: application/activity+json" "https://yourdomain.com/@editor"
   ```
   *Expected Response:* `200 OK` with `Content-Type: application/activity+json` containing actor details, public key PEM, and outbox endpoints.

3. **Verify Mastodon Search**:
   In any Mastodon or Fediverse search box, search for:
   `@editor@yourdomain.com`
   Your Statamic profile should appear immediately with its avatar, display name, and bio.

For troubleshooting edge cases, header stripping, or queue stalls, refer to [TROUBLESHOOTING.md](TROUBLESHOOTING.md).
