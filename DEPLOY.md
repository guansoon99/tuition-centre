# Deployment

Target: a single small VPS (DigitalOcean Singapore, Contabo, or similar).
**1 GB RAM / 1 vCPU is enough** for a few hundred active students — PHP serves
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

⚠️ There *is* scheduled work, and it is not optional:
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

| Droplet | Workers | Throughput | Verdict |
|---|---|---|---|
| 512 MB / 1 vCPU | 3 | ~40/s | ✗ one photo upload can OOM the box |
| **1 GB / 1 vCPU** | **8** | **~40–50/s** | ✓ fine for a few hundred students |
| 2 GB / 2 vCPU | 16 | ~80–100/s | comfortable |

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
recompiles every file of Laravel plus the app on *every single request*. It is
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

The binding constraint is whichever is *lowest*. Get this wrong and students
hit an opaque wall:

| Layer | Setting | Value |
|---|---|---|
| Cloudflare (free/Pro) | — | 100 MB, immovable |
| nginx | `client_max_body_size` | 96M |
| PHP | `post_max_size` | 96M |
| PHP | `upload_max_filesize` | 50M |
| App | `materials.max_file_size_mb` | 50 (default) |

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

| Upload | `$_FILES` | Result |
|---|---|---|
| 5 MB — over `upload_max_filesize` only | entry present, `error: 1` | Recoverable: Laravel can report "file too large" |
| 12 MB — over `post_max_size` | **empty**, `$_POST` empty too | **419 Page Expired** |

In the second row the CSRF token is gone with the rest of `$_POST`, so
Laravel's `VerifyCsrfToken` middleware rejects the request before validation
ever runs. The student waits through the whole upload and is told their *page
expired*. Nothing is written to the application log, because the request never
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

    # Long-term cache for built static assets.
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff2?)$ {
        expires 1M;
        add_header Cache-Control "public, immutable";
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/tuition /etc/nginx/sites-enabled/
sudo certbot --nginx -d your-domain.tld
```

## Cloudflare R2

1. Create bucket `tuition-prod` in the Cloudflare dashboard.
2. Generate an R2 access key (Account → R2 → Manage R2 API tokens).
3. Set the four `R2_*` env vars in `.env`.
4. **Bucket CORS is required.** Student submissions upload straight from the
   browser to R2, so the bucket must allow `PUT` from your origin. Without it
   every direct upload fails CORS and silently falls back to the slower
   proxied path — which still works, so this misconfiguration is easy to miss.
   In the R2 dashboard → your bucket → Settings → CORS policy:

   ```json
   [{
     "AllowedOrigins": ["https://your-domain.tld"],
     "AllowedMethods": ["PUT"],
     "AllowedHeaders": ["content-type"],
     "MaxAgeSeconds": 3600
   }]
   ```

5. The bucket itself stays private. Materials are served exclusively via 15-minute signed URLs generated by `SignedUrlService`.

## Cloudflare CDN/WAF (free tier)

- Proxy the apex/subdomain through Cloudflare (orange cloud).
- SSL/TLS mode: Full (strict).
- Cache rule: cache `*.css`, `*.js`, `*.woff2`, `*.png`, `*.jpg`, `*.svg` for 1 month.
- Page rule: bypass cache on `/admin/*`, `/teach/*`, `/account*`, `/login`, `/logout`, `/materials/*`.
- WAF: enable "Bot Fight Mode" and the OWASP Core ruleset on the free plan.
- Rate limit `/login` to 10 req/min per IP.

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
- [ ] An oversized file is refused *before* the upload starts, not after
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
