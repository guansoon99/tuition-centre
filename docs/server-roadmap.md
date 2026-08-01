# Production Server Roadmap

Reference for the real production deployment. Written 2026-07-17 during
the demo-server deploy conversation.

## Current state (demo)

- **Server**: Hetzner Windows Server 2022 VPS (135.181.95.15)
- **Stack**: Chocolatey → PHP 8.3.14 + Composer + Git + nssm
- **Runtime**: `php artisan serve --host=0.0.0.0 --port=80` wrapped by
  the `TuitionLMS` nssm service
- **DB**: SQLite at `C:\Users\Administrator\tuition-centre\database\database.sqlite`
- **File storage**: Local filesystem under `storage/app/public/`
- **HTTPS**: None — plain HTTP demo only
- **Admin**: username `admin`, password `qwe123`
- **Purpose**: internal demo, not for real students

## Target state (real production)

For a Malaysian tuition centre expecting ~1000 students accessing
courses at peak, read-heavy workload (browsing sections, downloading
PDFs, watching externally-hosted videos).

### Stack

| Layer | Choice | Rationale |
|---|---|---|
| VPS | **Contabo Cloud VPS Singapore, 4 vCPU / 8 GB RAM / 75 GB SSD** (~€8/mo, ~$8.60, ~RM40) | Chosen over DO SGP ($24 for 2 vCPU / 4 GB) to save ~RM73/mo. Same Singapore location keeps latency <30ms for MY users. Double the specs of DO's $24 tier at 1/3 the price. **Trade-offs accepted**: slower support (24-72h response), noisy-neighbor CPU steal possible at peak, less mature UI, occasional APAC network jitter (per user reviews). **Migration plan if Contabo becomes flaky**: same Laravel stack works on DO SGP unchanged — 2-4 hour cutover. Keep off-site backups (already in this doc) so the migration option stays real. |
| **Fallback VPS if Contabo fails** | DigitalOcean SGP Basic 4 GB / 2 vCPU ($24/mo) | Boring, reliable, huge docs ecosystem. Every Laravel Stack Overflow answer assumes it. Only pick if Contabo actually causes problems in first 30 days. |
| OS | **Ubuntu 24.04 LTS** | Standard Laravel deploy target. LTS until 2029. Every tutorial applies. |
| Web server | **nginx + PHP 8.3 FPM** (~40 workers) | Standard prod stack. Handles hundreds of concurrent requests. FPM worker count sized to VPS RAM (rule: total RAM / 40MB per worker). |
| **Database** | **MySQL 8** (self-hosted on same VPS) | Chosen because of the concurrent-write burst pattern at class start, NOT for growth safety. Every course open triggers 2-3 writes (`enrollments.last_accessed_at`, `course_views` upsert). With 1000 students opening a course in the first minute of class, that's ~3000-4000 writes concentrated in a ~60-sec window with sub-5-sec peaks of 200-500 writes/sec. SQLite serializes all writes, so during the burst some page loads jump from 100ms to 500-1000ms — noticeable to students. MySQL's row-level locks handle the concurrent unrelated writes in parallel with no perceptible slowdown. This "many small concurrent writes" pattern is SQLite's single worst case; MySQL is its native strength. Do not revisit this decision unless the write pattern actually changes. |
| Cache / Session | **Redis** | Once MySQL and PHP-FPM contend for RAM, file-based sessions start to bottleneck on locks. Redis is `apt install` + `SESSION_DRIVER=redis`. |
| File storage | **Cloudflare R2** | Zero egress fees (vs S3), S3-compatible, ~$0.015/GB. Config already exists in `.env.production.example`. Videos should go on YouTube Unlisted instead of R2 to save cost. |
| CDN / HTTPS / DDoS | **Cloudflare (Free tier)** in front | Free auto HTTPS, global CDN for static assets, DDoS protection, one-click "Under Attack Mode". Change nameservers to Cloudflare, done. |
| Domain | Real `.com` or `.my` (~$10-30/yr, Namecheap or Cloudflare Registrar) | Not DuckDNS — students need to trust the URL. |
| Backups | **Nightly `mysqldump` → gzip → rclone to R2** | Simple cron job. R2 storage is cheap. Retain last 30 days. |
| Uptime monitoring | **UptimeRobot** free tier | 50 monitors, 5-min external pings, alerts via email/SMS/Telegram/Slack/Discord/webhook when the site stops responding. Catches server-down / nginx-crashed cases Sentry can't see. |
| Error tracking | **Sentry** free tier (5k events/mo) | Laravel SDK auto-captures every uncaught exception + 500 with full stack trace, request payload, user context, browser. Notifies via email by default; add Slack/Discord/Telegram/webhook integrations in the dashboard. |
| Deploy tool | `git pull` + bash script + systemd | Not Docker, not Forge, not Kubernetes. Solo dev at this scale, bare-metal is easier to debug. |

### Total monthly cost

| Item | Cost |
|---|---|
| Contabo Cloud VPS SGP (4 vCPU / 8 GB / 75 GB) | ~$8.60 (€8) |
| Cloudflare (Free) | $0 |
| Cloudflare R2 (~100 GB) | ~$2 |
| Domain (amortized) | ~$1 |
| UptimeRobot + Sentry free tiers | $0 |
| **Total** | **~$12/month (~RM56)** |

If Contabo fails 30-day evaluation, fallback is DO SGP $24/mo tier →
total becomes ~$27/month. Do NOT add managed MySQL — self-host on the
same box; Contabo has no managed-DB offering anyway.

## Deliberately skipped

- **Docker / containers** — extra abstraction, no benefit at this size
- **Laravel Forge / Ploi** — pays off if managing 5+ apps
- **Queue workers** — no background jobs yet; add when needed
- **Load balancer / multi-server** — not until >1000 truly concurrent users
- **Elasticsearch / Meilisearch** — MySQL FULLTEXT is enough
- **Kubernetes** — no
- **SQLite for prod** — considered; rejected for growth-safety reasons

## Video hosting decision

**Do not host videos on the LMS server**, even after the nginx upgrade.
Use YouTube Unlisted (or Vimeo / Cloudflare Stream) and add them via
the existing "Link" material type. Reasons:

- Videos = huge bandwidth. Even 20 TB/month egress evaporates fast
  with concurrent streams
- YouTube's CDN handles unlimited concurrent viewers for free
- Unlisted = not searchable, only findable via direct link
- Zero server load, zero disk usage

## Deploy sequence

Rough order when the user is ready to provision the real server:

1. **Buy domain** (Namecheap or Cloudflare Registrar). Point
   nameservers to Cloudflare (which enables the CDN + HTTPS
   automatically). ~15 min including DNS propagation.
2. **Spin up Ubuntu VPS** on DigitalOcean SGP1, 4 GB / 2 vCPU.
   **Paste the deploy SSH public key at creation** — DO lets you do
   this from the web UI, so no password bootstrap is needed like the
   Windows deploy required.
3. **DNS records** in Cloudflare: `A` record for `yoursite.com` → server IP,
   proxied (orange cloud). Also `www` → same, or a CNAME.
4. **Server bootstrap** (all via ssh from Claude Code):
   - `apt update && apt install -y nginx php8.3-fpm php8.3-{mysql,mbstring,xml,zip,curl,gd,intl,bcmath,sqlite3,redis} mysql-server redis-server git composer certbot python3-certbot-nginx`
   - Secure MySQL: `mysql_secure_installation`
   - Create DB + user for the app
   - Enable + start `nginx`, `php8.3-fpm`, `mysql`, `redis-server` via systemd
   - **Raise PHP upload limits** in `/etc/php/8.3/fpm/php.ini`:
     `upload_max_filesize = 20M`, `post_max_size = 25M`,
     `memory_limit = 256M`. Defaults (2M/8M/128M) silently reject any
     photo larger than ~1MP. Also set nginx `client_max_body_size 25M;`
     in the site vhost. Reload both after change.
5. **Deploy the app**:
   - `git clone` into `/var/www/tuition-centre`
   - `composer install --no-dev --optimize-autoloader`
   - Copy `.env.production.example` → `.env`; fill in APP_URL, DB creds,
     R2 creds, `SESSION_DRIVER=redis`, `CACHE_DRIVER=redis`
   - `php artisan key:generate`
   - `php artisan migrate --force`
   - `php artisan db:seed --force` (only seeds roles+permissions now)
   - `php artisan storage:link`
   - `php artisan config:cache route:cache view:cache`
   - Chown to `www-data:www-data`
6. **nginx vhost** pointing to `/var/www/tuition-centre/public/`, with
   FastCGI to `php8.3-fpm` socket. Standard Laravel vhost.
7. **Cloudflare R2 bucket** + generate API credentials, set them in
   `.env`, set `FILESYSTEM_DISK=r2`.
8. **HTTPS**: Cloudflare is set to "Flexible" SSL by default, but
   upgrade to "Full (Strict)" — for that, install Let's Encrypt on the
   origin: `certbot --nginx -d yoursite.com`. Auto-renews via cron.
9. **Bootstrap admin user** via `php artisan tinker` (same as the
   demo). Then change password immediately after first login.
10. **Systemd unit** for a queue worker (empty for now, ready for
    future): `php artisan queue:work --sleep=3 --tries=3`.
11. **Cron** for scheduled tasks (`* * * * * cd /var/www/tuition-centre && php artisan schedule:run`).
12. **Backup cron**: nightly `mysqldump` + gzip + rclone to R2, retain 30 days.
13. **Monitoring setup** — do both, they cover different failure modes:

    **Sentry** (code errors: 500s, exceptions, bugs):
    - Sign up at [sentry.io](https://sentry.io) → create project → Platform: Laravel → copy the DSN
    - `composer require sentry/sentry-laravel`
    - `php artisan sentry:publish --dsn=<paste-dsn>`
    - Add `SENTRY_LARAVEL_DSN=...` and `SENTRY_TRACES_SAMPLE_RATE=0.1` to `.env`
    - `php artisan config:cache`
    - Verify: `php artisan sentry:test` — should appear in the Sentry dashboard within seconds
    - In Sentry: **Settings → Integrations** → add Slack/Discord/Telegram/email routing to whoever should be alerted

    **UptimeRobot** (server down / network down):
    - Sign up at [uptimerobot.com](https://uptimerobot.com)
    - Add monitor → HTTP(s) → `https://yoursite.com/` → 5-min interval
    - Add a second monitor for `https://yoursite.com/login` (catches "server up but Laravel broken" cases where `/` might still hit a cached page)
    - Alert contacts: your email at minimum; add Telegram/SMS if you want push notifications

    Both surface into a single Slack/Telegram channel if you set them up that way — recommended so all "site problem" signals land in one place.

Total time end-to-end: **half a day**.

## Contabo evaluation period (first 30 days)

Since Contabo is the cheaper-but-less-proven pick, keep tabs on it
during the first month. Fall back to DO SGP if any of these show up:

- **UptimeRobot reports <99.9% uptime** — check the incident log; if
  it's network flapping (not a self-inflicted config issue), migrate
- **Peak-hour response times consistently >500ms** for cached pages —
  suggests noisy-neighbor CPU steal; a `top` showing high `%st` (steal
  time) is the smoking gun
- **Ticket response takes >48h on a real issue** — you can't run a SaaS
  where your provider might respond after your customer's exam
- **APAC network to your users routes badly** — traceroute from a MY
  Astro/Unifi connection should look sane; if it hairpins through EU,
  migrate
- **Payment/billing weirdness** — Contabo has been reported to bill
  overages or charge setup fees mid-contract

**Migration playbook** (2-4 hours, budget half a day):

1. Spin up DO SGP 4 GB / 2 vCPU
2. Run the same deploy sequence as below (nginx, PHP, MySQL, Redis)
3. Restore latest MySQL dump from R2 backup
4. Point Cloudflare DNS `A` record at new IP (TTL 5 min → cutover in
   ~5 min after change)
5. Verify, then destroy Contabo instance

Because backups live off-server on R2 and the stack is portable, this
is a real button-press, not a rewrite.

## Sentinel: when to consider scaling further

Move to a bigger stack when you observe any of these:

- **>3000 truly concurrent users** during peaks — bump to 8 GB VPS or
  add a second app server + load balancer
- **DB CPU >70% sustained** — move MySQL to a separate DB VPS or
  managed instance
- **Class-start bursts causing timeouts** — add read replicas (or
  switch to managed MySQL with auto-scaling)
- **Feature that adds heavy writes** (bulk email, analytics, live
  quizzes) — add queue workers on a separate VPS

Until then, the single-VPS setup above is the right size.

## Migration from demo to real

**Nothing to migrate.** The demo server has 1 admin user, no real
student/course/material data. When the real server goes live:

- Do NOT copy the SQLite file from the demo — it's a different DB engine
- Do NOT copy the `.env` — different creds, different `APP_URL`
- Do NOT reuse the same admin password — start fresh
- Do keep this same code repo — `git clone` the same `main` branch

Demo server can be decommissioned once the real server is live and
tested end-to-end.
