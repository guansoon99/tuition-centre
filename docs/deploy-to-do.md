# Production Deployment Checklist

Target stack: **DigitalOcean droplet (1 vCPU / 2 GB, SGP1) + Cloudflare (free
plan) + Cloudflare R2 for uploads.** Estimated cost ~$13/mo total ($12 droplet
+ ~$1 R2 storage), plus ~$10/yr domain if bought/renewed via CF Registrar.

Rough time to walk through the whole list: **one focused weekend (~8–10 hrs)**.

---

## Section 1 — Must-do (essentials before flipping DNS)

### 1.1  Server bootstrap
- [ ] Create Droplet: **Ubuntu 24.04 LTS**, **SGP1 region**, 1 vCPU / 2 GB
- [ ] Add SSH public key at creation time (skip password auth)
- [ ] `adduser deploy` → `usermod -aG sudo deploy`
- [ ] `~/.ssh/authorized_keys` for `deploy` user, then `PasswordAuthentication no` in `/etc/ssh/sshd_config`
- [ ] `timedatectl set-timezone Asia/Kuala_Lumpur`
- [ ] `apt install ufw fail2ban unattended-upgrades`
- [ ] `ufw allow 22,80,443/tcp && ufw enable`
- [ ] `dpkg-reconfigure unattended-upgrades` → enable automatic security updates

### 1.2  LEMP stack
- [ ] `apt install nginx mysql-server`
- [ ] `apt install php8.3-fpm php8.3-{mbstring,xml,curl,mysql,gd,zip,bcmath,intl,imagick}`
- [ ] `apt install composer git`
- [ ] `mysql_secure_installation` — set root password, remove anon users, disallow remote root
- [ ] MySQL: `bind-address = 127.0.0.1` (default on Ubuntu, verify)
- [ ] Create app DB + user:
  ```sql
  CREATE DATABASE tuition CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'tuition'@'localhost' IDENTIFIED BY 'strong-random-password';
  GRANT ALL ON tuition.* TO 'tuition'@'localhost';
  ```

### 1.3  Laravel deploy
- [ ] `cd /var/www && git clone git@github.com:guansoon99/tuition-centre.git tuition`
- [ ] `cd tuition && composer install --no-dev --optimize-autoloader`
- [ ] `cp .env.production.example .env` → fill in `APP_URL`, `DB_PASSWORD`, `R2_*`
- [ ] `php artisan key:generate`
- [ ] `php artisan migrate --force`
- [ ] `php artisan storage:link`
- [ ] `php artisan config:cache && php artisan route:cache && php artisan view:cache`
- [ ] `chown -R www-data:www-data /var/www/tuition/storage /var/www/tuition/bootstrap/cache`
- [ ] `chmod -R 775 /var/www/tuition/storage /var/www/tuition/bootstrap/cache`
- [ ] Create first admin via tinker:
  ```bash
  php artisan tinker --execute="\$u = App\\Models\\User::create(['username' => 'admin', 'name' => 'Admin', 'password' => bcrypt('strong-password'), 'is_active' => true]); \$u->assignRole('admin');"
  ```

### 1.4  Nginx site config
- [ ] `/etc/nginx/sites-available/tuition`:
  ```nginx
  server {
      listen 80;
      server_name yourdomain.com www.yourdomain.com;
      root /var/www/tuition/public;
      index index.php;

      client_max_body_size 100M;   # for PDF/video uploads

      add_header X-Frame-Options "SAMEORIGIN";
      add_header X-Content-Type-Options "nosniff";

      location / {
          try_files $uri $uri/ /index.php?$query_string;
      }

      location ~ \.php$ {
          fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
          fastcgi_index index.php;
          fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
          include fastcgi_params;
      }

      location ~ /\.(?!well-known).* {
          deny all;
      }
  }
  ```
- [ ] `ln -s /etc/nginx/sites-available/tuition /etc/nginx/sites-enabled/`
- [ ] `rm /etc/nginx/sites-enabled/default`
- [ ] `nginx -t && systemctl reload nginx`

### 1.5  HTTPS (two options — pick one)

**Option A — Cloudflare Origin Certificate (simpler, 15-year cert)**
- [ ] Cloudflare dashboard → SSL/TLS → Origin Server → Create Certificate
- [ ] Save `.pem` + `.key` to `/etc/ssl/cf-origin/`
- [ ] Update nginx site to `listen 443 ssl` + `ssl_certificate` paths
- [ ] Redirect `:80` → `:443`

**Option B — Let's Encrypt via certbot (industry standard)**
- [ ] `apt install certbot python3-certbot-nginx`
- [ ] `certbot --nginx -d yourdomain.com -d www.yourdomain.com`
- [ ] Cert auto-renews via cron

Then set **Cloudflare SSL/TLS mode: Full (strict)** — anything less either doesn't encrypt CF↔origin or accepts self-signed which defeats the purpose.

### 1.6  Cloudflare DNS
- [ ] Add domain in Cloudflare dashboard (free plan)
- [ ] Change nameservers at GoDaddy → CF's two nameservers (24-hour propagation)
- [ ] Add A record: `@` → droplet IP, **proxied** (orange cloud)
- [ ] Add CNAME: `www` → `@`, **proxied**
- [ ] Optionally: transfer domain to CF Registrar for wholesale renewal (~$10/yr vs GoDaddy $20+)

### 1.7  Cloudflare R2
- [ ] R2 dashboard → Create Bucket (e.g. `tuition-media`)
- [ ] Manage R2 API Tokens → Create API Token
  - Permissions: Object Read & Write for that bucket
  - Save Access Key ID + Secret + Account ID + Endpoint
- [ ] In `.env`:
  ```
  UPLOADS_DISK=r2
  R2_ACCESS_KEY_ID=<from token>
  R2_SECRET_ACCESS_KEY=<from token>
  R2_BUCKET=tuition-media
  R2_ENDPOINT=https://<account_id>.r2.cloudflarestorage.com
  ```
- [ ] `php artisan config:clear`
- [ ] Test: upload a banner image via admin UI, verify it appears from R2 URL
- [ ] Optional: connect a subdomain (e.g. `media.yourdomain.com`) to the R2 bucket for public serving with your own domain

---

## Section 2 — Should-do (safety net, do within first week)

### 2.1  Database backups
- [ ] Create backup script `/root/backup-db.sh`:
  ```bash
  #!/bin/bash
  DATE=$(date +%Y-%m-%d-%H%M)
  mysqldump -u tuition -p'DB_PASSWORD' tuition | gzip > /tmp/tuition-$DATE.sql.gz
  # Push to R2 (install rclone: apt install rclone, configure with R2 credentials)
  rclone copy /tmp/tuition-$DATE.sql.gz r2:tuition-backups/
  find /tmp -name 'tuition-*.sql.gz' -mtime +2 -delete
  ```
- [ ] `chmod +x /root/backup-db.sh`
- [ ] `crontab -e` → `0 3 * * * /root/backup-db.sh` (daily 3 AM)
- [ ] **Once a month:** actually test restoring from a backup on a scratch DB. Untested backups aren't backups.

### 2.2  Uptime monitoring
- [ ] Add health-check route in Laravel: `Route::get('/health', fn () => response()->json(['ok' => true]));`
- [ ] Sign up for UptimeRobot / BetterUptime / Cronitor (all have free tiers)
- [ ] Set monitor: `https://yourdomain.com/health` every 1 min
- [ ] Alert channel: email + SMS + push (whichever you'll actually check)

### 2.3  Error tracking
- [ ] Sign up Sentry (free tier: 5k errors/mo)
- [ ] `composer require sentry/sentry-laravel`
- [ ] `php artisan sentry:publish --dsn=YOUR_DSN`
- [ ] Test: throw an exception, verify it lands in Sentry dashboard

### 2.4  Log hygiene
- [ ] Verify `.env` has `LOG_CHANNEL=daily` (rotates daily)
- [ ] Confirm `logrotate` is picking up nginx/mysql/php-fpm logs (default on Ubuntu)

### 2.5  Security check
- [ ] `.env` has `APP_DEBUG=false` and `APP_ENV=production`
- [ ] MySQL only listening on 127.0.0.1: `ss -tlnp | grep mysql`
- [ ] Only ports 22/80/443 open: `ss -tlnp`
- [ ] `sudo -u www-data ls /var/www/tuition/storage` (verify perms not too permissive)

---

## Section 3 — Nice-to-have (add when it becomes worth it)

### 3.1  Deploy automation
- [ ] **Simple option:** SSH + `git pull && composer install --no-dev && php artisan migrate --force && php artisan config:cache && php artisan view:cache`. Wrap in a bash script `/root/deploy.sh`.
- [ ] **Nicer option:** [Deployer](https://deployer.org) — zero-downtime deploys, atomic symlink swaps. ~30 min setup.
- [ ] **Team option:** GitHub Actions workflow on push to `main` that SSHes and runs the deploy script.

### 3.2  Cloudflare optimizations
- [ ] Speed → Auto Minify (CSS/JS/HTML)
- [ ] Cache Rules: cache `/build/*` for 1 year (hashed filenames)
- [ ] Rate Limiting: 5 login attempts per 10 minutes per IP (free plan gets some rules)
- [ ] Optional: Turnstile widget on login form for bot protection

### 3.3  Cron for Laravel scheduler
Only needed if you add scheduled tasks in `app/Console/Kernel.php`. Currently the app doesn't use any.
- [ ] `crontab -e -u www-data`:
  ```
  * * * * * cd /var/www/tuition && php artisan schedule:run >> /dev/null 2>&1
  ```

### 3.4  Queue worker
Only needed if you enable queued jobs (currently `QUEUE_CONNECTION=sync`).
- [ ] `apt install supervisor`
- [ ] `/etc/supervisor/conf.d/tuition-worker.conf` with `php artisan queue:work`
- [ ] `supervisorctl reread && update && start tuition-worker:*`

### 3.5  Email
For password reset, notifications, etc. Currently app has no email features.
- [ ] Sign up for **Postmark** ($10/mo for 10k emails, best deliverability) or **AWS SES** ($0.10 per 1000)
- [ ] `.env`:
  ```
  MAIL_MAILER=smtp
  MAIL_HOST=smtp.postmarkapp.com
  MAIL_USERNAME=your-server-token
  MAIL_PASSWORD=your-server-token
  MAIL_ENCRYPTION=tls
  MAIL_FROM_ADDRESS=noreply@yourdomain.com
  MAIL_FROM_NAME="Your Tuition Centre"
  ```
- [ ] Set up SPF + DKIM + DMARC DNS records (Postmark's dashboard walks you through it)

---

## Section 4 — Deliberately skipping (mentioned so you don't wonder)

- **Redis** — file cache + database sessions handle your scale. Revisit when adding a second app server, background jobs, or real-time features.
- **Load balancer** — single droplet suffices at 1000 concurrent readers.
- **Kubernetes / Docker** — overkill for a single-app single-server deployment.
- **Cloudflare CDN for R2** — R2 already serves from CF's edge network globally.
- **Read replicas** — not needed until you're doing >100k queries/sec.
- **APM (New Relic / Datadog)** — Sentry covers errors; server-side APM is overkill at this size.

---

## Post-launch — first 24 hours

- [ ] Log in as admin, walk through core flows: create a course, enroll a student, upload a PDF, publish an announcement
- [ ] Check Sentry — no errors? Good.
- [ ] Check the first daily backup ran overnight (list files in R2 backup bucket)
- [ ] Check UptimeRobot shows 100% uptime
- [ ] Run `tail -f /var/www/tuition/storage/logs/laravel.log` and click around the app — no warnings?
- [ ] Test a student account end-to-end from their perspective on a phone

## When to migrate off this stack

Not soon. Concrete triggers:
- **~5000 concurrent readers** — vertical-scale the droplet first (4 vCPU / 8 GB is $24/mo, still one server)
- **You need multi-region redundancy** — DO Managed Load Balancer + two droplets across regions
- **Real-time features** (live chat, presence) — add Redis + WebSocket server
- **Heavy background processing** (video transcoding, batch reports) — add Redis queues + Supervisor workers, or dedicate a second worker droplet

Until then: **one droplet + Cloudflare + R2 is the right shape.**
