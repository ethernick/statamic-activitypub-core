# ActivityPub Core Troubleshooting & Operations Runbook

A diagnostic matrix and resolution guide for production issues, federation handshakes, web server conflicts, and queue workers in **ActivityPub Core**.

---

## 🧭 Diagnostic Command Center

Run these artisan commands first to quickly inspect addon health:

```bash
# Verify cryptographic keys and actor profile integrity
php artisan activitypub:verify

# Inspect Dead Letter Queue (DLQ) and failed inbound requests
php artisan activitypub:inbox-failures

# Retry failed inbox activities
php artisan activitypub:retry-failed

# Diagnose missing sidecars or corrupted payload storage
php artisan activitypub:diagnose-bad-data

# Clean stale activities and expired cache records
php artisan activitypub:clean
```

---

## 🧩 Troubleshooting Matrix

### 1. Webfinger Issues (`/.well-known/webfinger`)

| Symptom | Probable Cause | Resolution |
| :--- | :--- | :--- |
| **`404 Not Found`** | Web server blocking dotfiles (hidden files) or missing rewrite rules. | Check Nginx/Apache configuration. Ensure `location ^~ /.well-known/` allows all and forwards to `/index.php?$query_string`. |
| **`404 Actor not found`** | Requested handle does not exist or `is_internal` is not set. | 1. Confirm actor slug matches the requested handle.<br>2. In the actor entry YAML or CP, verify `is_internal: true`.<br>3. Verify the actor is published (`published: true`). |
| **`400 Bad Request`** | Query parameter `resource` is missing or missing `acct:` prefix. | Ensure search format is `resource=acct:handle@yourdomain.com`. |
| **`500 Internal Error`** | Statamic Stache cache corruption or database migration missing. | Run `php artisan cache:clear` and `php artisan statamic:stache:warm`. |

---

### 2. HTTP Signatures & Federation Rejections

Fediverse servers enforce strict **IETF Draft Cavage HTTP Signatures** with RSA-SHA256. If signatures fail, remote instances will silently drop your posts or reply with `401 Unauthorized` or `400 Bad Request`.

#### Issue: Cloudflare Stripping HTTP Signature Headers
- **Symptom:** Remote servers can query Webfinger and view actor profiles, but inbound posts fail with `401 Unauthorized` or `Signature validation failed (library and fallback)`.
- **Cause:** Cloudflare reverse proxy, Nginx reverse proxy, or Web Application Firewall (WAF) stripping or rewriting the `Signature`, `Authorization`, `Date`, or `Digest` headers.
- **Resolution:**
  1. In Cloudflare, ensure no Transform Rules strip `Signature` or `Digest`.
  2. Disable Cloudflare "Bot Fight Mode" on `/activitypub/sharedInbox` and `/@*/inbox`.
  3. If running Apache behind Nginx/Cloudflare, ensure `HTTP_AUTHORIZATION` and `HTTP_SIGNATURE` are forwarded to PHP-FPM:
     ```nginx
     fastcgi_pass_request_headers on;
     fastcgi_param HTTP_SIGNATURE $http_signature;
     fastcgi_param HTTP_AUTHORIZATION $http_authorization;
     ```

#### Issue: Clock Skew Drift (`Date` Header Errors)
- **Symptom:** Verification log contains `Date header drift beyond ±300 seconds` or incoming activities are rejected by Mastodon/Pleroma.
- **Cause:** Fediverse protocol requires server time to match UTC within ±300 seconds (5 minutes). If your host machine time drifts, all outgoing and inbound signatures are rejected.
- **Resolution:**
  1. Synchronize server system clock with NTP:
     ```bash
     sudo timedatectl set-ntp on
     sudo timedatectl status
     ```
  2. Verify PHP's internal date resolution:
     ```bash
     php -r "echo gmdate('D, d M Y H:i:s T') . PHP_EOL;"
     ```

#### Issue: Digest Header Mismatch
- **Symptom:** Outbound jobs fail with `Digest does not match body payload`.
- **Cause:** Middleware modifying the raw HTTP JSON payload (e.g. trimming whitespace, auto-converting unicode characters, or minifying JSON) after the digest was calculated.
- **Resolution:**
  1. ActivityPub payloads must be transmitted as raw `application/activity+json`.
  2. Ensure no custom Laravel middleware modifies outgoing responses or parses requests prematurely.

---

### 3. Queue Workers & Asynchronous Federation

#### Issue: Outbox Jobs Piling Up / Stuck in Queue
- **Symptom:** Posts created in Statamic do not appear on Mastodon, and the `jobs` table count keeps growing.
- **Cause:** No worker listening on the `activitypub-outbox` queue.
- **Resolution:**
  1. Check what queues your worker is actively polling:
     ```bash
     # WRONG: Only processes the default queue
     php artisan queue:work

     # CORRECT: Explicitly processes the ActivityPub outbox and default queues
     php artisan queue:work --queue=activitypub-outbox,default
     ```
  2. If using Supervisor, ensure `--queue=activitypub-outbox,default` is set in the program command.

#### Issue: Queue Workers Timing Out or Crashing on Dead Remote Instances
- **Symptom:** Workers stall on single unreachable Fediverse instances.
- **Cause:** A remote instance is down, DNS is failing, or connection takes longer than PHP's socket timeout.
- **Resolution:**
  1. In `config/activitypub.php`, tune timeouts:
     ```php
     'http' => [
         'timeout' => 15, // seconds
         'max_concurrent' => 10,
     ],
     ```
  2. Ensure failed attempts back off and move to `failed_jobs` after 3 retries.
  3. The addon's self-healing layer automatically registers a temporary or permanent block if an instance returns `410 Gone`.

#### Issue: "Queue tables don't exist"
- **Symptom:** Error `Table 'jobs' does not exist` when dispatching federation events.
- **Resolution:**
  Run Laravel's queue migrations:
  ```bash
  php artisan queue:table
  php artisan queue:failed-table
  php artisan migrate
  ```

---

### 4. Storage & Sidecar JSON Files

#### Issue: Content Cache Out of Sync or Missing `.json` Sidecars
- **Symptom:** Post displays in Statamic CP, but visiting `https://yourdomain.com/@handle/notes/slug` returns standard HTML or empty JSON.
- **Cause:** Markdown front-matter was saved, but sidecar `.json` file generation failed or was bypassed.
- **Resolution:**
  Run the sidecar migration and repair command:
  ```bash
  # Check for entries missing sidecars
  php artisan activitypub:migrate-json --dry-run

  # Generate missing sidecar JSON files
  php artisan activitypub:migrate-json --chunk=50
  ```

#### Issue: File Permissions on Sidecar Directories
- **Symptom:** Logs show `Unable to write file at content/collections/...`.
- **Cause:** Web server user (`www-data`) and CLI user (running `php artisan queue:work`) have mismatched permissions.
- **Resolution:**
  Ensure both the web server and the queue worker user have write permissions to `content/collections/`:
  ```bash
  sudo chown -R www-data:www-data content/collections/
  sudo chmod -R 775 content/collections/
  ```

---

### 5. Control Panel & Asset Loading

#### Issue: Vue Components Failing to Render / White Screen in CP
- **Symptom:** Navigating to ActivityPub > Inbox shows blank views or JavaScript console errors (`TypeError: Cannot read properties of undefined`).
- **Cause:** Mismatch between Statamic version (Vue 2 vs Vue 3) or stale published assets.
- **Resolution:**
  1. Re-publish the correct version bundle:
     ```bash
     php artisan vendor:publish --tag=activitypub --force
     ```
  2. Clear Statamic compiled view and route caches:
     ```bash
     php artisan cache:clear
     php artisan view:clear
     ```
  3. Hard refresh the browser (`Cmd+Shift+R` or `Ctrl+Shift+R`).

---

## 🩺 Diagnostic Runbook Checklist

When opening an issue or triaging a federation failure, collect the following:

- [ ] Output of `php artisan activitypub:verify`
- [ ] Response headers from `curl -i "https://yourdomain.com/.well-known/webfinger?resource=acct:handle@yourdomain.com"`
- [ ] Response headers from `curl -i -H "Accept: application/activity+json" "https://yourdomain.com/@handle"`
- [ ] Status of queue workers: `supervisorctl status` or `ps aux | grep queue:work`
- [ ] Tail of recent ActivityPub logs: `grep -i "ActivityPub" storage/logs/laravel.log | tail -n 50`
