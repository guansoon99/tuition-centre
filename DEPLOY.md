# Deployment

Target: a single small VPS (DigitalOcean Singapore, Contabo, or similar), for a few thousands active students — PHP serves
only HTML, and files stream from Cloudflare R2 rather than the droplet. See
[Sizing](#sizing) for the measurements behind that.

## Server prerequisites

**Use Ubuntu 24.04 LTS.** Not for any deep technical reason — Debian is
slightly lighter and just as capable — but because when something breaks at
11pm, the search result that matches your error will have been written for
Ubuntu. At this scale that is worth more than the differences between distros.

⚠️ **24.04 specifically, not 22.04.** The PHP version is the whole reason:

| | Ships PHP |
| --- | --- |
| Ubuntu 22.04 LTS | 8.1 — `apt-get install php8.3-fpm` fails outright |
| **Ubuntu 24.04 LTS** | **8.3** — everything below just works |

On 22.04 you would need `sudo add-apt-repository ppa:ondrej/php` first, which
is a well-maintained third-party repo but one more thing to trust and keep
current. 24.04 avoids it entirely, and is supported to 2029.

```bash
# Ubuntu 24.04 LTS
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

### R2 does not enforce a signed content length

Verified against a live bucket, not assumed. A presigned PUT comes back with
`X-Amz-SignedHeaders=host` — the host and nothing else. A URL signed for 100
bytes accepted a 5,009-byte body and returned 200.

So the size and MIME type sent to the presign endpoint only filter honest
clients. Anyone willing to script a PUT can send whatever they like to a URL
they were legitimately issued. `SubmissionController::register()` is what
actually enforces both, by `HEAD`ing the stored object and sniffing its
leading bytes — and deleting it when either fails.

The consequence worth knowing: an oversized object **does briefly exist** in
the bucket before it is rejected. It is removed within the same request when
the browser calls register, and by `submissions:sweep-orphans` if it never
does. Storage is metered, so a determined student could waste some until the
nightly sweep. Bounded and cheap, but not zero.

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

## Cloudflare R2 — one bucket, never public

Everything a user uploaded lives in a single bucket that has **no public
access at all**: material PDFs, student submissions, lesson images and video,
announcement images. Each is reached through a controller that authorises the
caller and then redirects to a signed URL valid for 15 minutes.

⚠️ **Never enable public access on this bucket** — no custom domain, no
`r2.dev` subdomain. R2 exposes a bucket **all-or-nothing**; there is no
per-object or per-prefix visibility. One domain here publishes every student
submission it holds, at a URL whose shape is guessable
(`/submissions/{course}/{material}/{user}/{uuid}.pdf`).

### Branding is not in R2

The site logo, banner slides and course banners stay on this server's own
disk (`UPLOADS_DISK=public`), served by nginx via the `storage:link` symlink.

They render *before* login, so they cannot be gated. Putting them in R2 would
mean either a second bucket, or making this one public — and the second is
what the warning above forbids. Keeping them local sidesteps the question.

The trade-off: they do not survive a server rebuild. They are a logo and a few
banners, so re-uploading is a small price against publishing schoolwork. An
`r2_public` disk already exists in `config/filesystems.php` if you later add a
second app server, where local files stop being shared between them.

### Setup

1. Create the bucket in the Cloudflare dashboard.
2. Generate an R2 API token (Account → R2 → Manage R2 API tokens) with
   **Object Read & Write**. Admin permissions let it create and delete
   *buckets*, which this app never does.
3. Set `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_ENDPOINT` and
   `R2_BUCKET` in `.env` — see `.env.production.example`.
4. Confirm public access is **off**: bucket → Settings → Public access should
   show no connected domain and r2.dev not allowed. A bucket is private by
   default, so this is normally just a check.
5. **CORS is required.** Student submissions and teacher video PUT straight
   from the browser to R2 via signed URLs. Without CORS every direct upload
   fails and silently falls back to the slower proxied path — which still
   works, so the misconfiguration is easy to miss. Bucket → Settings → CORS:

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

    `AllowedOrigins` must match the browser's origin exactly — scheme, host and
    port. `http://` and `https://`, and `www` and bare, are different origins.
    Add staging as a second entry rather than replacing production.

6. Verify:

    ```bash
    php artisan storage:check
    ```

### Confirming the bucket really is private

There is no public/private toggle to read — private is the absence of a
public domain, not a setting. To check from outside, request an object with no
credentials:

```bash
curl -s -o /dev/null -w '%{http_code}
'   "https://<account_id>.r2.cloudflarestorage.com/<bucket>/<any-key>"
```

A private bucket answers **400/403** (`InvalidArgument`/`Authorization`). A
200 means public access is enabled and student work is exposed.

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
extension that belongs nowhere, and the reason changed once lesson media went
private.

**1. It would match nothing.** Teacher video goes to R2 via `CourseMedia`, so
it is never served from this droplet. A `*.mp4` rule on your own zone has
nothing to act on.

**2. It cannot be cached anywhere else either.** Video is served through
`CourseMediaController`, which authorises the viewer and redirects to a signed
R2 URL. Cloudflare's docs are explicit that "presigned URLs work with the S3
API domain and cannot be used with custom domains", and caching only applies to
custom domains — so the two are mutually exclusive by construction, not by
configuration.

That is a deliberate trade. Access control was worth more than caching here:
the Moodle install this replaces gates course files behind a login, and serving
them publicly would have been a downgrade. The cost is small at this geography
— an uncached fetch travels Kuala Lumpur → Cloudflare backbone → R2 in
Singapore, a short hop that adds milliseconds, and R2 egress is $0 either way.

If students outside the region ever enrol, that calculus shifts. Getting
caching back **with** access control then means Cloudflare Pro plus WAF HMAC
token validation, or a Worker in front of the bucket — not making the bucket
public.

⚠️ Video is capped at **500 MB** (`CourseMediaController::MAX_VIDEO_MB`),
checked in the browser before the upload starts and again on the stored object.
It used to be unbounded, which was never true in practice — the proxied path
simply failed at whatever limit it hit first.

### How much is the CDN actually buying you?

Be clear-eyed: **it can only ever cache static assets**, because none of the
HTML is cacheable and every user upload is behind a signed URL. Your entire
cacheable payload is four content-hashed build files plus a handful of icons —
small, and already served once per student per deploy.

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
- [ ] `php artisan storage:check` passes. Do this before the first upload of
      anything
- [ ] **The R2 bucket has no public access** — no connected domain, r2.dev not
      allowed. Confirm from outside with an unauthenticated request; it must
      answer 400/403, never 200:

      curl -s -o /dev/null -w '%{http_code}
'         "https://<account_id>.r2.cloudflarestorage.com/<bucket>/anything"

- [ ] The site logo and banners load on the login page — those come from this
      server's disk, so `php artisan storage:link` must have run
- [ ] `php artisan schedule:list` shows `submissions:sweep-orphans`
- [ ] `php artisan submissions:sweep-orphans --dry-run` runs without error
      against the real bucket
- [ ] `php -m | grep -i opcache` prints "Zend OPcache" — do this FIRST, the
      sizing assumptions depend on it (see the OPcache section)
- [ ] Nothing is being served by `php artisan serve` — nginx owns port 80/443
- [ ] Cloudflare is in front (DNS resolves to Cloudflare IPs)
- [ ] HTTPS via certbot, auto-renew installed
- [ ] `php artisan backup:run --dry-run` reports a dump, and
      `php artisan schedule:list` shows `backup:run` — see [Backups](#backups)
- [ ] **Restore one backup onto a scratch database before going live.** An
      untested backup is a hypothesis

## Backups

`php artisan backup:run`, scheduled nightly at 02:30 by the same `schedule:run`
cron as the orphan sweep. Nothing else to install.

It writes **to R2**, not to the droplet. That distinction is the whole point: a
backup in `/var/backups` shares the fate of the machine it protects. It covers
the common failure — a course deleted by mistake, a migration gone wrong — and
none of the total ones: disk failure, the droplet being destroyed, a
compromise that wipes local files too. Those are the cases you keep backups
for.

**The database only.** Every uploaded file — submissions, material PDFs,
lesson media, banners, the logo — already lives in R2, so copying them back
into R2 would buy nothing. The database is the one thing that exists solely on
the droplet.

It holds rows, not files, so a dump stays small: a few hundred KB today, and
still modest at a few thousand students.

```bash
php artisan backup:run --dry-run   # report, upload nothing
php artisan backup:run --keep=30   # change retention from the default 14 days
```

Old backups are pruned by the same command, so they do not accumulate. At
current data volumes a dump is a few hundred KB, so a fortnight costs a
fraction of a cent in R2 storage.

The command **refuses to run** when `FILESYSTEM_DISK` is not R2/S3, rather than
quietly writing a backup onto the same disk it is meant to protect.

### Restoring

```bash
# List what is available.
php artisan tinker --execute="print_r(Storage::disk('r2')->files('backups'));"

# Pull one down, then restore it.
php artisan tinker --execute="file_put_contents('/tmp/db.sql', Storage::disk('r2')->get('backups/db-<stamp>.sql'));"
mysql tuition < /tmp/db.sql

```

⚠️ **A backup you have never restored is a hypothesis.** Do the restore once,
onto a scratch database, before you need it — that is when you find out the
dump was empty, or the credentials were wrong, or the cron never fired.

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
