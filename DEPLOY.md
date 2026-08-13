# Deployment

Target: single VPS (Contabo Singapore or similar), 4 GB RAM is enough for 500–2 000 concurrent students because PHP serves only HTML — PDFs are streamed by Cloudflare R2.

## Server prerequisites

```bash
# Ubuntu 22.04 / 24.04
sudo apt-get update
sudo apt-get install -y nginx mysql-server redis-server certbot python3-certbot-nginx \
    php8.3-fpm php8.3-cli php8.3-mysql php8.3-redis php8.3-mbstring php8.3-xml \
    php8.3-curl php8.3-zip php8.3-intl php8.3-gd php8.3-bcmath php8.3-sqlite3 \
    supervisor unzip git
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
```

## PHP-FPM tuning (`/etc/php/8.3/fpm/pool.d/www.conf`)

```
pm = dynamic
pm.max_children = 40
pm.start_servers = 8
pm.min_spare_servers = 4
pm.max_spare_servers = 12
pm.max_requests = 500
```

Forty workers × ~30 MB ≈ 1.2 GB. Each request just renders Blade — file streaming happens at Cloudflare/R2.

## OPcache — verify it's actually on

Without OPcache, PHP recompiles every file of Laravel plus the app on *every*
request. It's worth roughly 2–3× throughput, and it is the single biggest
lever on a PHP box.

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

> Note: the Windows staging box has no OPcache and serves traffic with
> `php artisan serve` (PHP's single-threaded dev server) on port 80. Requests
> there queue behind each other. Both problems disappear on this Linux setup;
> don't carry the Windows configuration over.

## App deploy

```bash
cd /var/www
git clone https://github.com/your/tuition-lms tuition && cd tuition
cp .env.production.example .env
# Edit .env: APP_KEY (php artisan key:generate), DB creds, R2 creds, Redis password
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
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

    client_max_body_size 60M;  # PDFs up to 50M plus form overhead

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

## Queue worker (supervisord) — NOT currently needed

**`app/Jobs` is empty: the app dispatches nothing.** `QUEUE_CONNECTION=sync`
is correct, and setting up a worker today would give you a process that idles
forever. Skip this section on first deploy.

The two things people expect to be queued are not:

- **Student Excel imports** run inline — `ImportStudentsController` calls
  `StudentImporter::processRows()` directly. Fine at a few hundred rows; if
  they grow enough to risk a request timeout, queueing them is the fix.
- **Material access logging** used to be a job, and was removed along with the
  whole access-log feature.

Keep the config below for when a first real job appears — at which point set
`QUEUE_CONNECTION=redis` and start the worker.

`/etc/supervisor/conf.d/tuition-worker.conf`:

```
[program:tuition-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/tuition/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/tuition-worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start tuition-worker:*
```

## Cloudflare R2

1. Create bucket `tuition-prod` in the Cloudflare dashboard.
2. Generate an R2 access key (Account → R2 → Manage R2 API tokens).
3. Set the four `R2_*` env vars in `.env`.
4. Bucket CORS — only needed if you ever serve direct browser uploads. The current app proxies uploads through PHP, so CORS is not required.
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
- [ ] PDF upload, view, signed-URL download, access log all work end to end with a real R2 bucket
- [ ] `php -m | grep -i opcache` prints "Zend OPcache" (see the OPcache section)
- [ ] Nothing is being served by `php artisan serve` — nginx owns port 80/443
- [ ] (No queue worker needed — `app/Jobs` is empty and `QUEUE_CONNECTION=sync`)
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
php artisan queue:restart   # tells supervisor workers to reload
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

The dev box is on PHP 8.1 + SQLite + file cache (no MySQL/Redis required). Path differences are summarised in `dev_environment.md` in the project memory; the only friction is dot-sourcing `dev.ps1` once per shell.
