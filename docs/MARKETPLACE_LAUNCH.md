# Statamic Marketplace Launch & Marketing Blueprint

This document defines the complete visual, editorial, and growth strategy for launching **ActivityPub Core** on the **Statamic Marketplace** and **Packagist**. It serves as the production punch-list for release assets, visual specifications, demonstration videos, and creator onboarding materials.

---

## 🎨 1. Marketplace Hero Banner Specifications

The hero banner is the primary visual anchor shown on the Statamic Marketplace addon directory, Twitter/Mastodon social cards (Open Graph), and community newsletters.

### Visual Architecture & Canvas Specs
- **Dimensions**: `1200 × 630 px` (Standard 1.91:1 aspect ratio, high-density retina export).
- **Background Palette**:
  - Deep Fediverse Obsidian Dark Mode: `#0B0F19` with a subtle radial gradient radiating from center-right (`#1E1B4B` indigo / `#311042` deep violet at 35% opacity).
  - Subtle grid overlay: Micro-dot or fine tech grid (`#334155` at 15% opacity).
- **Branding & Logo Elements**:
  - **Left Anchor**: Statamic Radnor "S" logo in warm coral (`#FF269E` / `#F43F5E`) linked via a glowing curved connection line to the official **ActivityPub W3C decentralized nodes logo** in Fediverse Indigo (`#6366F1`).
  - **Typography**: Inter Display or Outfit Bold.
    - Title: **ActivityPub Core** (`54px`, Bold, `#FFFFFF`).
    - Subtitle: **Connect Your Statamic Site to the Fediverse** (`24px`, Semi-Bold, `#94A3B8`).
  - **Badges**: Floating frosted glass chicklets (backdrop blur `12px`, border `1px solid rgba(255,255,255,0.1)`):
    - `Statamic 5 & 6 Ready` (Emerald green dot)
    - `Two-Way Federation` (Violet node icon)
    - `100% Free & Open Source` (MIT badge)
- **Right Visual Anchor**:
  - Perspective isometric mockup of the Statamic Control Panel **ActivityPub Inbox Feed**:
    - Showing a clean dark-mode card with federated Mastodon avatar, `@mastodon.social` handle, and a note card displaying live replies and reblogs.
    - Floating Fediverse interaction badges (+1 Boost, +3 Likes).

---

## 📸 2. Control Panel Screenshot Capture Specifications

These 4 screenshots must be captured in high-resolution (recommended viewport: `1440 × 900 px` or `1920 × 1080 px` scaled at 2x) with clean, realistic production-like data (no `lorem ipsum`).

### Screenshot 1: The Unified Fediverse Inbox Feed
- **Target Route**: `/cp/activitypub` (Inbox view)
- **What it Proves**: Two-way federated interaction right inside Statamic.
- **Data Setup**:
  - 3–4 incoming Notes/Replies from popular fediverse domains (e.g. `@dhh@mastodon.social`, `@statamic@fosstodon.org`, `@taylorotwell@mastodon.social`).
  - Rendered user avatars, handle chicklets, rich media attachments, and action buttons (`Reply`, `Like`, `Boost`, `View on Fediverse`).
- **Callout Banner**: *"Read and engage with incoming federated replies without leaving the Statamic CP."*

### Screenshot 2: Actor Identity & Webfinger Profile
- **Target Route**: `/cp/collections/actors/entries/editor` (or `/cp/activitypub/settings`)
- **What it Proves**: Seamless mapping between Statamic users and federated `@handle@domain.com` identities.
- **Data Setup**:
  - Display Name: "Statamic Editorial"
  - Handle: `@editor@yourdomain.com`
  - Bio: "Official updates, articles, and micro-posts federated directly from Statamic."
  - Avatar and Banner image populated.
  - Public Key PEM read-only display indicator.
- **Callout Banner**: *"Automated Webfinger discovery and cryptographic RSA-SHA256 key management."*

### Screenshot 3: Real-Time Federation Queue & Activity Stream
- **Target Route**: `/cp/activitypub/activities` (or Queue Dashboard)
- **What it Proves**: Enterprise-grade background processing and transparent audit trails.
- **Data Setup**:
  - Live activity table showing recent `Create`, `Like`, `Follow`, and `Announce` events.
  - Clean delivery status tags (`Delivered`, `Batched`, `Acknowledged 200 OK`).
  - Target server badges (e.g. `mastodon.online`, `threads.net`, `pixelfed.social`).
- **Callout Banner**: *"3-tier asynchronous queue pipeline with automated rate-limiting and retry logic."*

### Screenshot 4: Fediverse Moderation & Self-Healing Blocklist
- **Target Route**: `/cp/activitypub/blocklist`
- **What it Proves**: Safety and defense-in-depth protections against spam or abusive instances.
- **Data Setup**:
  - Domain block examples (e.g. `spam-instance.xyz` - Reason: "Automated Scraping").
  - Actor-specific block examples with auto-block indicators (`HTTP 410 Gone / Suspended`).
- **Callout Banner**: *"Full domain and actor moderation with automated 410 Gone self-healing."*

---

## 🎬 3. Video / Animated GIF Demo Storyboard (15 Seconds)

**Length**: 15 seconds  
**Format**: High-FPS MP4 / WebP animated loop (no audio required, high-contrast captions).  
**Resolution**: `1280 × 720 px`.

| Timeline | Scene / Action | Visual Focus | On-Screen Caption |
| :--- | :--- | :--- | :--- |
| **0:00 - 0:03** | **Publish Note in Statamic** | User types *"Excited to announce our new blog running on Statamic 5 federated via ActivityPub! 🚀"* into the Note form and clicks **Save & Publish**. | Statamic CP Note Form | *"1. Publish directly from Statamic..."* |
| **0:03 - 0:06** | **Outbox Dispatch** | Quick camera slide to the terminal / queue monitor showing `SendActivityPubPost` dispatched to `activitypub-outbox`. | High-speed worker ping | *"2. Instant signed HTTP delivery..."* |
| **0:06 - 0:10** | **Live on Mastodon** | Screen switches to a Mastodon web client. The post immediately appears in the follower's timeline under `@editor@yourdomain.com`. A remote user clicks **Favorite** and writes a reply: *"Looks incredible! Does this support polls too?"* | Mastodon Timeline | *"3. Live on Mastodon, Threads & Pixelfed..."* |
| **0:10 - 0:15** | **Inbound Inbox Sync** | Screen switches back to Statamic CP. The **Inbox Feed** updates with a subtle flash badge: new reply from `@mastodon_user` and +1 Like counter incremented. | Statamic Inbox Feed | *"4. Two-way federated interaction inside Statamic."* |

---

## 🚀 4. Creator Guide: "Connect Your Statamic Blog to the Fediverse in 5 Minutes"

*(Designed for the Marketplace Listing Description Tab, Statamic Blog Post, and Documentation Hub).*

---

### Connect Your Statamic Blog to the Fediverse in 5 Minutes

The Fediverse is the fastest-growing open social network in the world. Powered by the W3C **ActivityPub** standard, millions of creators on Mastodon, Threads, Pixelfed, and Flipboard are interacting on open protocols instead of closed walled gardens.

With **ActivityPub Core**, your Statamic site becomes a first-class citizen of the fediverse. Anyone on any fediverse platform can search for your blog, follow your posts, and reply directly to your authors.

Here is how to get running in under 5 minutes:

#### Step 1: Install the Addon
Run the composer requirement from your project root:
```bash
composer require ethernick/statamic-activitypub-core
```

#### Step 2: Run the 1-Command Setup
Run the guided interactive installer:
```bash
php artisan activitypub:install
```
The installer automatically configures your queue tables, publishes settings and Control Panel assets, creates standard content collections (`notes`, `actors`, `activities`), and links your CP user to your first Fediverse handle (e.g. `@editor@yourdomain.com`).

#### Step 3: Start Your Queue Worker
ActivityPub signs cryptographic payloads and delivers them in the background:
```bash
php artisan queue:work --queue=activitypub-outbox,default
```
*(For production, configure Supervisor as detailed in our Operator Guide).*

#### Step 4: Test in Any Fediverse App!
1. Open Mastodon, Elk, or Threads.
2. In the search bar, type:
   ```text
   @editor@yourdomain.com
   ```
3. Your Statamic profile appears instantly with your avatar and bio. Click **Follow**!

Whenever you publish a Note or Article in Statamic, all your followers across the Fediverse receive it instantly in their timelines. When they reply, their comments stream directly into your Statamic Control Panel Inbox.

Welcome to the decentralized web.

---

## 📦 5. Marketplace Listing Metadata & Copy Pack

- **Title**: ActivityPub Core
- **Tagline**: Native Fediverse & ActivityPub federation engine for Statamic 5 & 6.
- **Categories**: `Social`, `Integration`, `Community`, `Content`
- **Price**: **Free** (MIT License)
- **Compatibility**: Statamic 5.x, Statamic 6.x | Laravel 11.x, 12.x | PHP 8.2+
- **Keywords**: `activitypub`, `fediverse`, `mastodon`, `threads`, `pixelfed`, `social`, `federation`, `microblogging`
- **First-Party Extension Addons**:
  - *ActivityPub LongFormat*: Rich federated Articles & read-time formatting ($39).
  - *ActivityPub Questions*: Federated single and multi-choice Polls with real-time vote tallying ($29).
  - *ActivityPub Places*: Federated geolocation check-ins, OpenStreetMap integration, and places directory ($29).
