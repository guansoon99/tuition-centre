# Deployment

Target: a single small VPS (DigitalOcean Singapore, Contabo, or similar), for a few thousands active students — PHP serves
only HTML, and files stream from Cloudflare R2 rather than the droplet. See
[Sizing](#sizing) for the measurements behind that.

## Server prerequisites

```bash
# Ubuntu 22.04 / 24.04
sudo apt-get update
sudo apt-get install -y nginx mysql-server certbot python3-certbot-nginx \
    php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml \
    php8.3-curl php8.3-zip php8.3-intl php8.3-gd php8.3-bcmath \
    unzip git
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
```

**No Redis, no supervisor, no queue worker.** Cache and sessions use the
`file` driver — on a single app server that's a sub-millisecond file op the OS
page-caches — and `app/Jobs` is empty, so `QUEUE_CONNECTION=sync` is correct
and a worker would idle forever. The one thing people expect to be queued and
isn't: student Excel imports run inline in `ImportStudentsController`. Fine at
a few hundred rows; queue them if they ever grow enough to risk a timeout.

Revisit only if you add a second app server (`file` cache and sessions are
per-box) or a genuine background job. `.env.production.example` records the
same decision.

⚠️ There _is_ scheduled work, and it is not optional:
`submissions:sweep-orphans` runs nightly — see [Uploads](#uploads-two-paths).
It needs one cron entry and no daemon:

```bash
sudo crontab -e -u www-data
# * * * * * cd /var/www/tuition && php artisan schedule:run >> /dev/null 2>&1
```

Verify with `php artisan schedule:list`. Without this, abandoned uploads
accumulate in R2 forever and nothing will ever report it.

## Sizing

Measured on this app, one course page with 72 materials:

```
wall 29.6 ms · CPU 29.2 ms · 44 MB peak RAM
```

Wall time ≈ CPU time, so requests are **CPU-bound**, not waiting on I/O — vCPU
count sets your throughput ceiling.

⚠️ **These figures assume OPcache is on.** They were measured with the files
already compiled, which is the state OPcache maintains. On a box without it,
every request re-compiles and the numbers below do not apply — see
[OPcache](#opcache--verify-its-actually-on). Verify it first; everything here
is conditional on it.

The exception is image uploads — GD decodes to an uncompressed bitmap, so an
8 MP phone photo peaks around **104 MB** in a single worker. That, not page
serving, is what sets the RAM floor.

| Droplet           | Workers | Throughput   | Verdict                            |
| ----------------- | ------- | ------------ | ---------------------------------- |
| 512 MB / 1 vCPU   | 3       | ~40/s        | ✗ one photo upload can OOM the box |
| **1 GB / 1 vCPU** | **8**   | **~40–50/s** | ✓ fine for a few hundred students  |
| 2 GB / 2 vCPU     | 16      | ~80–100/s    | comfortable                        |

1 GB budget: Ubuntu 120 + MySQL 300 + nginx 15 + (8 × 35) 280 ≈ **715 MB**,
leaving ~300 MB for upload spikes.

Note **1000 registered students is not 1000 requests/second.** A class of 50
opening the app together is ~10 req/s; 300 students within 10 seconds is ~30.
Only a simultaneous refresh by everyone at once would saturate a single vCPU.

Resizing a droplet takes minutes, so start small and move up on real traffic
rather than guessing. If you do outgrow 1 GB, you need **more vCPU** — RAM
won't be the thing that ran out.

## PHP-FPM tuning (`/etc/php/8.3/fpm/pool.d/www.conf`)

For **1 GB / 1 vCPU** — do not use 40 children on a small box, the workers
alone would exceed RAM and start swapping:

```
pm = dynamic
pm.max_children = 8
pm.start_servers = 2
pm.min_spare_servers = 2
pm.max_spare_servers = 4
pm.max_requests = 500
```

Scale `pm.max_children` with RAM, roughly `(available MB) / 40`, leaving
~150 MB headroom for an image upload. Each request renders Blade — file
streaming happens at Cloudflare R2, not here.

## OPcache — verify it's actually on

**Do not treat this as optional tuning.** Without OPcache, PHP re-parses and
recompiles every file of Laravel plus the app on _every single request_. It is
not a percentage improvement — it is the difference between the throughput
figures in [Sizing](#sizing) being real and being fiction.

Measured on this app, same page, same machine:

```
first request in a process (must compile)  217.4 ms
subsequent requests (already compiled)      25.6 ms
```

php-fpm **without** OPcache pays something close to that first number on every
request; **with** it, close to the second. Not all 192 ms is recoverable —
some is Laravel's first-request bootstrapping rather than compilation — but
the bulk is.

Real-world confirmation: the Windows staging box has no OPcache and serves
traffic with `php artisan serve` (PHP's single-threaded dev server), so
requests queue behind each other. It spends **~430 ms of server time on a
login page** (TTFB 0.65 s minus 0.22 s of network). That is what a no-OPcache
PHP box looks like — don't carry any of that configuration over to Linux.

Debian/Ubuntu ship it enabled (`/etc/php/8.3/fpm/conf.d/10-opcache.ini`), so
installing `php8.3-fpm` should be enough — but confirm rather than assume,
because when it's missing there is no error, just a slow site:

```bash
php -m | grep -i opcache            # must print: Zend OPcache
php -i | grep opcache.enable        # must be On/1
```

If it's absent: `sudo apt-get install -y php8.3-opcache`.

Worth setting explicitly in `/etc/php/8.3/fpm/conf.d/10-opcache.ini` — the
defaults are sized for a small app and Laravel has a lot of files:

```ini
opcache.enable=1
opcache.memory_consumption=192      ; MB of compiled bytecode
opcache.max_accelerated_files=20000 ; Laravel + vendor exceeds the 10k default
opcache.validate_timestamps=0       ; never stat files for changes — see below
```

`validate_timestamps=0` is the fast setting but means **PHP will not notice
edited files**. That's correct for production and requires
`sudo systemctl reload php8.3-fpm` as part of every deploy — which the update
procedure below already does. Leave it at `1` if you ever edit files directly
on the server.

## Uploads: two paths

Student submissions upload **directly to R2** — the browser asks for a signed
URL (`submissions.presign`), PUTs the file to Cloudflare, then tells the app
about it (`submissions.register`). The bytes never touch this server.

That matters for two reasons. **Cloudflare's free and Pro plans cap a proxied
request body at 100 MB**, and no server-side setting can raise it — Business is
200 MB, so paying does not meaningfully help. And a proxied upload occupies a
PHP-FPM worker for the whole transfer, so on a 1 vCPU box a few concurrent
uploads would stall page rendering for everyone.

**A proxied fallback still exists** (`submissions.upload`) and is a real
production path, not just a dev convenience: some school and corporate networks
block unfamiliar hostnames, and a student whose network blocks the R2 endpoint
would otherwise have no way to submit at all. The browser falls back to it
automatically on any transport failure. It is subject to every cap below.

### Cap chain — keep these consistent

The binding constraint is whichever is _lowest_. Get this wrong and students
hit an opaque wall:

| Layer                 | Setting                      | Value             |
| --------------------- | ---------------------------- | ----------------- |
| Cloudflare (free/Pro) | —                            | 100 MB, immovable |
| nginx                 | `client_max_body_size`       | 96M               |
| PHP                   | `post_max_size`              | 96M               |
| PHP                   | `upload_max_filesize`        | 50M               |
| App                   | `materials.max_file_size_mb` | 50 (default)      |

#### Setting the PHP limits

Write the settings to their own file rather than editing `php.ini` — a package
upgrade can replace `php.ini`, and it will not touch this:

```bash
sudo tee /etc/php/8.3/fpm/conf.d/99-uploads.ini > /dev/null <<'INI'
; Ceiling for one file. Must be >= the app's max_file_size_mb.
upload_max_filesize = 50M
; Ceiling for the WHOLE request body, not one file. Must exceed
; upload_max_filesize with room for multiple files plus form fields.
post_max_size = 96M
max_file_uploads = 20
INI

# Same file for the CLI SAPI, purely so the verification below is meaningful.
sudo cp /etc/php/8.3/fpm/conf.d/99-uploads.ini /etc/php/8.3/cli/conf.d/99-uploads.ini

sudo systemctl restart php8.3-fpm
```

Verify:

```bash
php -i | grep -E '^(post_max_size|upload_max_filesize)'
```

⚠️ **`php -i` reads the CLI configuration, not FPM's.** The two SAPIs load
different `conf.d` directories, so checking the CLI value proves nothing about
the process actually serving requests — which is why the copy above exists. If
you skip that copy, this check will happily report values your site is not
using. To confirm what FPM itself loaded, restart it and check
`journalctl -u php8.3-fpm --since '1 min ago'` for configuration errors.

#### Why `post_max_size` in particular

**Do not leave it at PHP's 8M default.** It is the setting whose failure mode
is worst, because it does not produce an error a student can act on.

When a request body exceeds `post_max_size`, PHP discards the **entire** body
before any application code runs. Measured on PHP 8 with `post_max_size=8M`
and `upload_max_filesize=2M`:

| Upload                                 | `$_FILES`                     | Result                                           |
| -------------------------------------- | ----------------------------- | ------------------------------------------------ |
| 5 MB — over `upload_max_filesize` only | entry present, `error: 1`     | Recoverable: Laravel can report "file too large" |
| 12 MB — over `post_max_size`           | **empty**, `$_POST` empty too | **419 Page Expired**                             |

In the second row the CSRF token is gone with the rest of `$_POST`, so
Laravel's `VerifyCsrfToken` middleware rejects the request before validation
ever runs. The student waits through the whole upload and is told their _page
expired_. Nothing is written to the application log, because the request never
reached the application.

Keep `post_max_size` comfortably above `upload_max_filesize`, and keep both
under nginx's `client_max_body_size`.

The direct-to-R2 path is subject to none of the above — the bytes never reach
PHP. Its ceiling is R2's own **5 GiB** single-part limit, and the app's
`max_file_size_mb` is what actually governs. These limits bind only the
proxied fallback, which is exactly when a student is most likely to be on a
constrained network already.

### Orphaned objects

A direct upload can succeed at R2 and then never be registered — closed tab,
dropped connection. Nothing on this server observes that, so there is no error
to catch; the object is simply there, referenced by nothing. This is the one
genuine cost of direct uploads, and `submissions:sweep-orphans` (nightly, see
[prerequisites](#server-prerequisites)) is the only thing that cleans them up.

```bash
php artisan submissions:sweep-orphans --dry-run   # safe: lists, deletes nothing
```

It skips anything younger than 24h so an in-flight upload is never deleted out
from under a student.

## App deploy

```bash
cd /var/www
git clone https://github.com/your/tuition-lms tuition && cd tuition
cp .env.production.example .env
# Edit .env: APP_KEY (php artisan key:generate), DB creds, R2 creds
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
# Only needed if UPLOADS_DISK=public. With UPLOADS_DISK=r2 (the production
# default) nothing is written to the local public disk and the symlink is inert.
php artisan storage:link
php artisan config:cache route:cache view:cache
php artisan filament:cache-components
sudo chown -R www-data:www-data storage bootstrap/cache
```

## Nginx (`/etc/nginx/sites-available/tuition`)

```nginx
server {
    listen 80;
    server_name your-domain.tld;
    root /var/www/tuition/public;

    index index.php;
    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    client_max_body_size 96M;  # see Uploads — must stay under Cloudflare's 100M

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    # Vite content-hashes these (app-CDOQqzMD.js), so the filename changes
    # whenever the contents do. That makes a year safe — and correct, since a
    # shorter TTL just makes returning students re-download identical bytes.
    location ^~ /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    # Everything else static. Note webp: the material icons are webp, and
    # leaving it out of this list silently excludes them from caching.
    location ~* \.(js|css|png|jpg|jpeg|gif|webp|avif|ico|svg|woff2?|ttf)$ {
        expires 1M;
        add_header Cache-Control "public";
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/tuition /etc/nginx/sites-enabled/
sudo certbot --nginx -d your-domain.tld
```

## Cloudflare R2 — two buckets

**Create two, not one.** The split is a security boundary, not tidiness:

| Bucket           | Holds                                                       | Exposure                                     |
| ---------------- | ----------------------------------------------------------- | -------------------------------------------- |
| `tuition-prod`   | material PDFs, **student submissions**                       | Private forever. Signed URLs only.            |
| `tuition-public` | banners, announcement images, inline editor images, videos    | Public, behind a custom domain                |

The public bucket needs a world-readable custom domain — that is what lets
Cloudflare cache it, and what makes video delivery permitted under their CDN
terms. Put that domain on a bucket that also holds submissions and you publish
every student's work. There is no prefix-based version of this that is safe.

### Setup

1. Create both buckets in the Cloudflare dashboard.
2. Generate one R2 API token (Account → R2 → Manage R2 API tokens). It can
   reach both buckets, so `R2_ACCESS_KEY_ID` / `R2_SECRET_ACCESS_KEY` are
   shared.
3. Attach a custom domain to **`tuition-public` only**: bucket → Settings →
   Public access → Connect Domain, e.g. `cdn.your-domain.tld`. Set
   `R2_PUBLIC_URL` to it.

    ⚠️ Leave `tuition-prod` with public access **disabled**. It has no
    `url` configured in `config/filesystems.php` precisely so that nothing can
    accidentally start linking to it.

4. Set the `R2_*` vars in `.env` — see `.env.production.example`.
5. **CORS on `tuition-prod` is required.** Student submissions PUT straight
   from the browser to the private bucket via signed URLs. Without CORS every
   direct upload fails and silently falls back to the slower proxied path,
   which still works — so this is easy to miss. Bucket → Settings → CORS:

    ```json
    [
        {
            "AllowedOrigins": ["https://your-domain.tld"],
            "AllowedMethods": ["PUT"],
            "AllowedHeaders": ["content-type"],
            "MaxAgeSeconds": 3600
        }
    ]
    ```

6. Verify before trusting any of it:

    ```bash
    php artisan storage:check
    ```

### Why `R2_PUBLIC_URL` is not optional

Without it, the S3 driver builds URLs from the API endpoint
(`https://<account>.r2.cloudflarestorage.com/<bucket>/<path>`). That address
requires SigV4 authentication, so **every banner, announcement image and video
returns 403** — with no exception thrown and nothing written to the log. The
page renders fine; the pictures are just missing.

`storage:check` catches this, and `PublicFile::url()` logs an error if it ever
gets that far in production.

## Cloudflare CDN/WAF (free tier)

- Proxy the apex/subdomain through Cloudflare (orange cloud).
- SSL/TLS mode: Full (strict).
- Cache rule — **`/build/*` for 1 year**. Those filenames are content-hashed,
  so a new deploy produces new URLs and stale bytes are impossible.
- Cache rule — 1 month for `*.css`, `*.js`, `*.woff2`, `*.ttf`, `*.png`,
  `*.jpg`, `*.svg`, **`*.webp`**, `*.ico`.
  ⚠️ `webp` is easy to forget and matters here: the material icons are webp, so
  omitting it excludes the most-requested images on every course page.
- Bypass cache on `/admin/*`, `/teach/*`, `/account*`, `/login`, `/logout`,
  `/materials/*`, and **`/announcement-images/*`**.
  ⚠️ That last one is not decoration. Announcement images are authorised
  per-user through `User::visibleAnnouncements()`, and the URL contains only
  the announcement id — so a cached response would be served to users who are
  not permitted to see it.
- WAF: enable "Bot Fight Mode" and the OWASP Core ruleset on the free plan.
- Rate limit `/login` to 10 req/min per IP.

### Don't enable "Cache Everything"

The rules above are safe because Cloudflare's default cache level only caches
by file extension and never caches a response carrying `Set-Cookie` — and
Laravel sets a session cookie on every response. A "Cache Everything" rule
removes both protections at once.

Every HTML page here is per-user: `/` renders differently signed in, and
course pages depend on enrolment. Caching HTML at the edge would serve one
student's page to another. There is no version of this worth the risk.

### Video — do NOT add `*.mp4` to the rules above

It looks like the obvious next entry in the cache list. It is the one
extension that belongs somewhere else, for two independent reasons.

**1. It would match nothing.** Teacher video uploads go through
`SectionController::uploadVideo` → `PublicFile` → `uploads_disk`, which is
`r2` in production. The videos are already on R2 and are never served from
this droplet, so a `*.mp4` rule on your own zone has nothing to act on.

**2. If it did match, it would be the restricted case.** Cloudflare's CDN
terms allow serving video only when the content is hosted on a Cloudflare
service — Stream, Images, or R2. Video sitting on your origin and pulled
through the orange cloud is exactly what the restriction covers. Because your
videos live on R2, you are on the right side of this — but only for as long as
they are served *from* R2.

**So configure caching on the R2 custom domain instead.** Binding a custom
domain (e.g. `cdn.your-domain.tld`) to the bucket puts Cloudflare's cache in
front of R2, is explicitly permitted for video, and costs nothing in egress
because R2-to-edge traffic is internal to Cloudflare. Byte-range requests work
through it, which is what makes seeking in a `<video>` element usable.

⚠️ **Cap video uploads below 512 MB.** That is Cloudflare's maximum cacheable
object size on Free, Pro *and* Business alike — only Enterprise raises it, to
5 GB. Anything larger is fetched from R2 on every single view and never cached
at the edge. `SectionController::uploadVideo` is currently unbounded by
design, so a teacher can upload a 700 MB lecture that permanently misses the
cache. Egress is still $0, but every viewer pays the full origin round-trip.

### How much is the CDN actually buying you?

Be clear-eyed: **it can only ever cache static assets**, because none of the
HTML is cacheable. Your entire static payload is four content-hashed build
files plus a handful of icons — small, and already served once per student per
deploy.

Video is the exception, and it is the one case where a CDN genuinely earns its
keep — a 50 MB lesson watched by 200 students is 10 GB that the edge serves
instead of R2. But that happens on the R2 custom domain, not here.

The CDN is worth configuring for TLS, WAF, login rate-limiting and DDoS
absorption. It is not what makes the app fast. [OPcache](#opcache--verify-its-actually-on)
and direct-to-R2 uploads are, and both are settled elsewhere in this document.

## Going live checklist

- [ ] `APP_DEBUG=false` and `APP_ENV=production` in `.env`
- [ ] `APP_KEY` set
- [ ] `php artisan migrate --force` ran without error
- [ ] An admin user exists (`php artisan tinker` → `User::factory()->create([...])->assignRole('admin')`)
- [ ] PDF upload, view, signed-URL download all work end to end with a real R2 bucket
- [ ] **A student submission uploads directly to R2** — watch the browser
      Network tab and confirm the PUT goes to `*.r2.cloudflarestorage.com`, not
      to your own domain. If it posts to `/assignments/*/upload` instead, the
      direct path failed and it fell back silently; check the bucket CORS
      policy first
- [ ] An oversized file is refused _before_ the upload starts, not after
- [ ] `php artisan storage:check` passes — two separate buckets, and the
      public one has a custom domain. Do this before the first upload of
      anything
- [ ] Fetch a banner image URL directly: it must be `cdn.your-domain.tld/...`,
      not `*.r2.cloudflarestorage.com`, and must return 200
- [ ] Fetch a submission path on the public domain and confirm it **404s** —
      student work must not be reachable there
- [ ] `php artisan schedule:list` shows `submissions:sweep-orphans`
- [ ] `php artisan submissions:sweep-orphans --dry-run` runs without error
      against the real bucket
- [ ] `php -m | grep -i opcache` prints "Zend OPcache" — do this FIRST, the
      sizing assumptions depend on it (see the OPcache section)
- [ ] Nothing is being served by `php artisan serve` — nginx owns port 80/443
- [ ] Cloudflare is in front (DNS resolves to Cloudflare IPs)
- [ ] HTTPS via certbot, auto-renew installed
- [ ] Nightly `mysqldump` to a backup location

## Update procedure (zero-ish downtime)

```bash
cd /var/www/tuition
git fetch && git reset --hard origin/main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan cache:clear        # AFTER migrate — see note below
php artisan config:cache route:cache view:cache
php artisan filament:cache-components
sudo systemctl reload php8.3-fpm
```

**Why `cache:clear` belongs there:** the application cache stores serialised
Eloquent models, and a serialised model carries the attribute set it had when
it was written. Deploy a migration that adds or renames a column and any warm
entry deserialises without it — reads return `null` rather than the real
value, silently, until that entry's TTL expires. Clearing straight after
`migrate` closes the window.

`config:cache` / `route:cache` / `view:cache` do **not** cover this. They
rebuild framework caches; the application cache is separate and untouched by
them.

## Local dev

The dev box is on PHP 8.1 + SQLite + file cache — no MySQL needed. Path
differences are summarised in `dev_environment.md` in the project memory; the
only friction is dot-sourcing `dev.ps1` once per shell.

Note the local disk cannot presign, so student uploads take the proxied
fallback path in dev. The direct-to-R2 path only exercises against a real
bucket — see the going-live checklist.
