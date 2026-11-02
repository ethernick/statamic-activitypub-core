# Federation Best Practices & Performance Tuning Guide

A guide for operators, engineers, and site architects running **ActivityPub Core** on production Statamic sites. This document outlines actor handle topology, moderation policies, queue tuning, and storage maintenance.

---

## 🎭 Actor Handle Architecture

In ActivityPub, an **Actor** represents the federated identity of your site or individual creators. Fediverse servers discover your actors via Webfinger (`acct:handle@yourdomain.com`).

### 1. Architectural Patterns

| Pattern | Handle Example | Primary Use Case | Advantages |
| :--- | :--- | :--- | :--- |
| **Site-Wide / Brand Identity** | `@blog@yourdomain.com`<br>`@news@yourdomain.com` | Company blogs, editorial magazines, brand publications. | Clean, centralized identity. Content from multiple CP authors can be aggregated under one canonical profile. |
| **Individual Author / Creator** | `@alice@yourdomain.com`<br>`@bob@yourdomain.com` | Multi-author publications, personal blogs, team sites. | Authentic peer-to-peer engagement, personal avatars, individual outbox streams, and direct mention replies. |
| **Hybrid (Site + Authors)** | `@blog@yourdomain.com` + individual staff handles | Organizations wanting both a corporate broadcast account and individual voice. | Maximum flexibility; followers choose whether to follow the publication or individual columnists. |

### 2. Local Handle Configuration Rules

1. **Internal Flag Required**: Local actors created in the `actors` collection must have `is_internal: true`. Remote cached actors from external Fediverse instances have `is_internal: false`.
2. **Slug Matching**: Webfinger queries match against the entry's `slug` or explicit `handle` field. For clean URLs, keep the actor slug alphanumeric (`@editor` -> `actors/editor.md`).
3. **User Association**: When using individual creator handles, link the actor entry to the Statamic User via the `actors` relationship field on the User blueprint.

---

## 🛡️ Moderation & Auto-Block Configuration

The Fediverse is open and decentralized. ActivityPub Core implements **defense-in-depth moderation** with automated self-healing mechanisms to protect your server resources and inbox feed.

### 1. Three-Tier Moderation Architecture

```
Incoming Activity (POST /activitypub/sharedInbox)
                  │
                  ▼
   [ 1. HTTP Signature Check ] ──── (Invalid / Tampered) ───► 401 Unauthorized
                  │
                  ▼
   [ 2. BlockList Filter ]      ──── (Blocked Domain/Actor) ──► 200 OK (Silently Ignored)
                  │
                  ▼
   [ 3. Actor Ingestion & Self-Healing ]
                  │
                  ├─► HTTP 410 Gone ───────────► Auto-Block (Database & Cache)
                  ├─► 'suspended: true' Flag ──► Auto-Block (Database & Cache)
                  └─► Valid Actor ─────────────► Dispatched to Inbox Queue
```

### 2. Manual Domain and Actor Blocking

You can block abusive instances, spam bots, or individual actors through the Control Panel (**ActivityPub > Blocklist**) or programmatically via the `BlockList` service:

```php
use Ethernick\ActivityPubCore\Services\BlockList;

// Block an entire remote domain (wildcard subdomain matching supported)
BlockList::add('spammer-instance.social', 'Persistent comment spam');

// Block a specific remote actor handle or URL
BlockList::add('@spammer@remote.org', 'Harassment');
BlockList::add('https://remote.org/users/spammer', 'Harassment');
```

- **Domain-Level Blocks**: Blocking `spam.social` automatically blocks `sub.spam.social` and all individual users on that host.
- **Silent Dropping**: When a blocked actor or domain pushes activities to your inbox, the endpoint returns a `200 OK` or `202 Accepted` to prevent spammers from probing your block status, but immediately drops the payload before running database queries or disk writes.

### 3. Automated Self-Healing Protections

ActivityPub Core automatically isolates bad remote actors:
- **HTTP 410 Gone**: If an actor's home instance returns `410 Gone` (user deleted or instance shut down), `ActorResolver` immediately adds the URL to the `activitypub_blocks` table and caches the response.
- **Suspension Flags**: If remote metadata contains `suspended: true` or `toot:suspended: true`, the actor is instantly auto-blocked.

---

## ⚡ Performance Tuning & Queue Concurrency

Fediverse delivery happens over HTTP POST requests to dozens or hundreds of independent remote inboxes. Tuning your queue and storage prevents database contention and keeps response times fast.

### 1. Queue Connection: Redis vs Database

| Feature | `database` Queue (SQLite / MySQL) | `redis` Queue |
| :--- | :--- | :--- |
| **Suitability** | Small to medium sites (< 1,000 followers) | High-volume sites (> 1,000 followers, frequent posts) |
| **Setup Overhead** | Zero additional services (uses existing DB) | Requires Redis server or managed cluster |
| **Throughput** | ~50–150 jobs/sec | 2,000+ jobs/sec |
| **Lock Contention** | Can lock SQLite during heavy broadcast bursts | Non-blocking in-memory atomic pop |

> [!TIP]
> If using SQLite for content and queues, consider setting `QUEUE_CONNECTION=redis` in `.env` if you federate with more than 500 remote followers to prevent database table locking during bulk delivery.

### 2. Queue Worker Concurrency & Batching

Configure worker concurrency in `config/activitypub.php`:

```php
'http' => [
    'timeout' => 15,          // Timeout in seconds for remote HTTP responses
    'max_concurrent' => 10,   // Max concurrent HTTP client requests
],
'rate_limits' => [
    'per_minute' => 30,       // Max requests per minute to a single domain
    'enabled' => true,
],
```

For Supervisor, allocate workers proportionally:
- **`activitypub-outbox`** (2–4 workers): Prioritized for outbound post federation.
- **`default`** (1–2 workers): Handles avatar downloads, link previews, and thread tracking.

```bash
php artisan queue:work --queue=activitypub-outbox,default --sleep=2 --tries=3 --timeout=120
```

---

## 💾 Storage Optimization: Sidecar JSON Files

ActivityPub Core uses a **Sidecar JSON Architecture** to decouple federation payloads from Statamic's flat-file content layer (`.md` / `.yaml`).

### Why Sidecars?
1. **Zero Stache Bloat**: Statamic parses front-matter on every warm. Storing complete ActivityPub JSON structures (often 10–30KB per entry) inside Markdown files degrades CP indexing speed and memory usage.
2. **Direct Wire Serving**: The `NegotiateActivityPubResponse` middleware streams the `.json` file straight to remote clients using `file_get_contents()`, bypassing Statamic's parser entirely.

### Sidecar Maintenance Commands

```bash
# Check for missing sidecar JSON files without modifying disk
php artisan activitypub:migrate-json --dry-run

# Migrate or regenerate sidecars in safe, chunked batches
php artisan activitypub:migrate-json --chunk=50 --delay=100
```

---

## 🧹 Scheduled Data Retention (`activitypub:clean`)

Remote federated instances generate thousands of ephemeral activities (Follows, Likes, Boosts, Delete notifications). Without pruning, external activity data will gradually consume disk space.

### 1. Retention Configuration

Configure retention windows in `config/activitypub.php` or `.env`:

```env
# Days to retain external activities (Follow, Like, Announce)
ACTIVITYPUB_RETENTION_ACTIVITIES=2

# Days to retain external cached posts from other servers
ACTIVITYPUB_RETENTION_ENTRIES=30
```

### 2. High-Performance Cleanup Engine

The `php artisan activitypub:clean` command:
- **Protects Internal Content**: Never deletes entries where `is_internal: true`.
- **Fast Direct File Unlinking**: Removes target `.md` and `.json` sidecars directly from disk rather than dispatching individual Statamic events.
- **Single Cache Rebuild**: Issues a single `Stache::clear()` at the end of the batch, preventing repetitive index rebuilds.

Add `schedule:run` to your server crontab to run maintenance automatically:
```bash
* * * * * cd /path/to/statamic && php artisan schedule:run >> /dev/null 2>&1
```
*(Default maintenance window is set to 02:00 AM server time).*

---

## 📊 Monitoring & Production Health Checks

| Metric | Target | Warning Threshold | Command / Check |
| :--- | :--- | :--- | :--- |
| **Failed Inbound Activities** | `0` | `> 25` | `php artisan activitypub:inbox-failures` |
| **Failed Queue Jobs** | `0` | `> 10` | `php artisan queue:failed` |
| **System Clock Skew** | `± 0s` | `> ± 60s` | `timedatectl status` |
| **Stale Sidecars** | `0` | `> 0` | `php artisan activitypub:diagnose-bad-data` |
| **Actor Key Health** | Valid RSA-2048 | Missing Key | `php artisan activitypub:verify` |
