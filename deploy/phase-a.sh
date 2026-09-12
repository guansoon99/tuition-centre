#!/usr/bin/env bash
# Phase A of DEPLOY.md — server setup for the tuition LMS.
#
# Run once, as root, on a fresh Ubuntu 24.04 droplet:
#
#     ssh root@<ip> 'bash -s -- <ip>' < phase-a.sh
#
# Every section names the part of DEPLOY.md it implements. It is safe to
# re-run: each step checks whether it has already been done.
#
# What it deliberately does NOT do:
#   - R2 credentials. Those are yours to paste into /var/www/tuition/.env
#     on the server afterwards (R2_ACCESS_KEY_ID, R2_SECRET_ACCESS_KEY,
#     R2_ENDPOINT, R2_BUCKET). Nothing before uploads needs them.
#   - Anything with the domain. That is Phase B.
#
# Secrets it creates — the MySQL password and the first admin's password —
# are generated here on the box and never leave it: the DB password goes
# straight into .env, the admin login into /root/admin-credentials.txt
# (root-only). Read that file, log in, change the password, delete the file.

set -euo pipefail

DROPLET_IP="${1:?usage: bash -s -- <droplet-ip>}"
REPO="https://github.com/guansoon99/tuition-centre.git"
APP_DIR=/var/www/tuition
export DEBIAN_FRONTEND=noninteractive

say() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }

# Replace KEY=... in .env, or append it if the example never had the key.
set_env() {
    local key="$1" value="$2"
    if grep -q "^${key}=" "$APP_DIR/.env"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "$APP_DIR/.env"
    else
        printf '%s=%s\n' "$key" "$value" >> "$APP_DIR/.env"
    fi
}

# ---------------------------------------------------------------- A2: user
say "A2 — deploy user"
if ! id deploy &>/dev/null; then
    adduser --disabled-password --gecos "" deploy
    usermod -aG sudo deploy
    # No password was set, so sudo has to be passwordless or it is unusable.
    # Access is by SSH key only, same as root.
    echo 'deploy ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/deploy
    chmod 440 /etc/sudoers.d/deploy
    rsync --archive --chown=deploy:deploy /root/.ssh /home/deploy
fi

# ---------------------------------------------------------------- A2: swap
say "A2 — 2 GB swap (a small box has none, and the OOM killer takes MySQL first)"
if ! swapon --show --noheadings | grep '^/swapfile' > /dev/null; then
    fallocate -l 2G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

say "A2 — swappiness 10: swap as a safety net, not something MySQL pages get pushed into eagerly"
tee /etc/sysctl.d/99-swappiness.conf > /dev/null <<'SYS'
vm.swappiness = 10
SYS
sysctl -q -p /etc/sysctl.d/99-swappiness.conf

# ---------------------------------------------------------------- A2: ufw
say "A2 — firewall (OpenSSH allowed BEFORE enable, or this is the last command that ever runs)"
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

# ---------------------------------------------------------------- A3: stack
say "A3 — packages (DEPLOY.md: Server prerequisites)"
apt-get update -q
apt-get install -y -q nginx mysql-server \
    php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml \
    php8.3-curl php8.3-zip php8.3-intl php8.3-gd php8.3-bcmath \
    unzip git

if ! command -v composer &>/dev/null; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

# Sized to the box it is actually on, not the box DEPLOY.md assumes. Its
# sizing table: 1 GB -> 8 workers; 512 MB -> 3, and marked "one photo upload
# can OOM the box". Eight workers on 458 MB would swap under normal load.
MEM_MB="$(free -m | awk '/^Mem:/{print $2}')"
if [ "$MEM_MB" -lt 900 ]; then
    CHILDREN=3; START=1; MIN_SPARE=1; MAX_SPARE=2
else
    # Workers measure 58 MB each on this app (not the 35 first assumed):
    # 6 x 58 = 348 MB, leaving room beside MySQL for the image-upload spike.
    CHILDREN=6; START=2; MIN_SPARE=2; MAX_SPARE=3
fi
say "A3 — PHP-FPM pool: ${MEM_MB} MB detected -> ${CHILDREN} workers (DEPLOY.md: PHP-FPM tuning)"
POOL=/etc/php/8.3/fpm/pool.d/www.conf
sed -i -E \
    -e 's/^pm = .*/pm = dynamic/' \
    -e "s/^pm\.max_children = .*/pm.max_children = ${CHILDREN}/" \
    -e "s/^pm\.start_servers = .*/pm.start_servers = ${START}/" \
    -e "s/^pm\.min_spare_servers = .*/pm.min_spare_servers = ${MIN_SPARE}/" \
    -e "s/^pm\.max_spare_servers = .*/pm.max_spare_servers = ${MAX_SPARE}/" \
    -e 's/^;?pm\.max_requests = .*/pm.max_requests = 500/' \
    "$POOL"

say "A3 — upload limits (DEPLOY.md: Cap chain). Own file, so a package upgrade cannot undo it"
tee /etc/php/8.3/fpm/conf.d/99-uploads.ini > /dev/null <<'INI'
; Ceiling for one file. Must be >= the app's max_file_size_mb.
upload_max_filesize = 50M
; Ceiling for the WHOLE request body, not one file. Must exceed
; upload_max_filesize with room for multiple files plus form fields.
post_max_size = 96M
max_file_uploads = 20
INI
cp /etc/php/8.3/fpm/conf.d/99-uploads.ini /etc/php/8.3/cli/conf.d/99-uploads.ini

say "A3 — OPcache file limit (the app plus vendor is ~11,200 PHP files; the default 10,000 leaves the rest recompiling every request)"
tee /etc/php/8.3/fpm/conf.d/99-opcache.ini > /dev/null <<'INI'
opcache.max_accelerated_files = 20000
INI
cp /etc/php/8.3/fpm/conf.d/99-opcache.ini /etc/php/8.3/cli/conf.d/99-opcache.ini
systemctl restart php8.3-fpm

say "A3 — OPcache must be present (DEPLOY.md: OPcache). No error when it is missing, just a slow site"
php -m | grep -i 'Zend OPcache' > /dev/null || { echo "!! OPcache is not loaded — stop and fix before continuing"; exit 1; }

# ---------------------------------------------------------------- A3: MySQL
say "A3 — MySQL: performance_schema off (~240 MB of instrumentation nobody reads, on a 1 GB box)"
tee /etc/mysql/mysql.conf.d/zz-tuition.cnf > /dev/null <<'CNF'
[mysqld]
performance_schema = OFF
CNF
systemctl restart mysql

say "A3 — database and user"
DB_PASS="$(openssl rand -base64 36 | tr -dc 'A-Za-z0-9' | head -c 32)"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS tuition CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'tuition'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER 'tuition'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON tuition.* TO 'tuition'@'localhost';
FLUSH PRIVILEGES;
SQL

# ---------------------------------------------------------------- A3: app
say "A3 — clone and install (DEPLOY.md: App deploy)"
mkdir -p /var/www
if [ ! -d "$APP_DIR/.git" ]; then
    git clone "$REPO" "$APP_DIR"
fi
cd "$APP_DIR"
git fetch -q origin && git reset -q --hard origin/main
composer install --no-dev --optimize-autoloader --no-interaction --quiet

[ -f .env ] || cp .env.production.example .env

# Phase B leaves the origin certificate behind; its presence means TLS, the
# https APP_URL and secure cookies are in place, and rewriting them here would
# undo Phase B. That happened once: a re-run to re-tune FPM after a resize put
# the site back on plain HTTP. Everything else in this script is safe to
# repeat at any time.
PHASE_B_DONE=0
[ -s /etc/ssl/cloudflare/origin.pem ] && PHASE_B_DONE=1

if [ "$PHASE_B_DONE" = 1 ]; then
    say "A3 — .env: Phase B already applied, leaving APP_URL and SESSION_SECURE_COOKIE alone"
else
    say "A3 — .env for the HTTP-on-IP test phase (A4). B4 reverts APP_URL and SESSION_SECURE_COOKIE"
    set_env APP_URL               "http://${DROPLET_IP}"
    set_env SESSION_SECURE_COOKIE "false"
fi
set_env DB_CONNECTION         "mysql"
set_env DB_HOST               "127.0.0.1"
set_env DB_PORT               "3306"
set_env DB_DATABASE           "tuition"
set_env DB_USERNAME           "tuition"
set_env DB_PASSWORD           "${DB_PASS}"

if grep -q '^APP_KEY=$' .env; then
    php artisan key:generate --force --quiet
fi

php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
# Required: branding (logo, banner slides) is on the public disk and served
# through this symlink. See the note on it in DEPLOY.md.
[ -L public/storage ] || php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
chown -R www-data:www-data storage bootstrap/cache

# ---------------------------------------------------------------- A4: nginx
if [ "$PHASE_B_DONE" = 1 ]; then
    say "A4 — nginx: Phase B config (443) already in place, not touching it"
else
say "A4 — nginx on port 80 (DEPLOY.md: Nginx, without the certbot line). server_name is a catch-all until Phase B"
tee /etc/nginx/sites-available/tuition > /dev/null <<'NGINX'
server {
    listen 80;
    server_name _;
    root /var/www/tuition/public;

    index index.php;
    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    client_max_body_size 96M;  # see DEPLOY.md Uploads — must stay under Cloudflare's 100M

    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_proxied any;
    gzip_comp_level 5;
    gzip_types
        text/css
        text/plain
        text/xml
        application/javascript
        application/json
        application/xml
        image/svg+xml;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    location ^~ /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|webp|avif|ico|svg|woff2?|ttf)$ {
        expires 1M;
        add_header Cache-Control "public";
    }

    location ~ /\.(?!well-known).* { deny all; }
}
NGINX
ln -sf /etc/nginx/sites-available/tuition /etc/nginx/sites-enabled/tuition
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx
fi

# ---------------------------------------------------------------- cron
say "Cron — nightly orphan sweep is not optional (DEPLOY.md: Server prerequisites)"
CRON_LINE="* * * * * cd ${APP_DIR} && php artisan schedule:run >> /dev/null 2>&1"
# Built in a temp file, not a pipeline: crontab -l exits 1 for a user who has
# no crontab yet, and under set -e + pipefail that aborted the very command
# that was about to create one. First run only, which is the worst time.
CRON_TMP="$(mktemp)"
crontab -u www-data -l 2>/dev/null | grep -vF 'schedule:run' > "$CRON_TMP" || true
echo "$CRON_LINE" >> "$CRON_TMP"
crontab -u www-data "$CRON_TMP"
rm -f "$CRON_TMP"

# ---------------------------------------------------------------- queue worker
say "Queue worker — the student import runs as a job (DEPLOY.md: Queue worker)"
install -m 0644 "${APP_DIR}/deploy/tuition-queue.service" /etc/systemd/system/tuition-queue.service
systemctl daemon-reload
systemctl enable --now tuition-queue
printf '  tuition-queue: %s\n' "$(systemctl is-active tuition-queue)"

# ---------------------------------------------------------------- admin user
say "First admin user — credentials to /root/admin-credentials.txt (root-only), never printed"
ADMIN_PASS="$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 20)"
php artisan tinker --execute="
    \$u = App\Models\User::firstOrCreate(
        ['username' => 'admin'],
        ['name' => 'Administrator', 'password' => '${ADMIN_PASS}', 'is_active' => true]
    );
    \$u->assignRole('admin');
    echo \$u->wasRecentlyCreated ? 'created' : 'already existed (password unchanged)';
" 2>/dev/null
umask 077
if ! grep -q '^username=' /root/admin-credentials.txt 2>/dev/null; then
    printf 'username=admin\npassword=%s\n' "$ADMIN_PASS" > /root/admin-credentials.txt
fi

# ---------------------------------------------------------------- report
say "Done. Checks:"
printf '  %-28s %s\n' "PHP-FPM" "$(systemctl is-active php8.3-fpm)"
printf '  %-28s %s\n' "nginx"   "$(systemctl is-active nginx)"
printf '  %-28s %s\n' "MySQL"   "$(systemctl is-active mysql)"
printf '  %-28s %s\n' "OPcache" "$(php -m | grep -i opcache > /dev/null && echo loaded || echo MISSING)"
printf '  %-28s %s\n' "swap"    "$(swapon --show --noheadings | awk '{print $3}' | head -1)"
printf '  %-28s %s\n' "FPM workers" "${CHILDREN} (for ${MEM_MB} MB)"
printf '  %-28s %s\n' "migrations" "$(php artisan migrate:status 2>/dev/null | grep -c Ran) ran"
printf '  %-28s %s\n' "cron" "$(crontab -u www-data -l 2>/dev/null | grep -c schedule:run) entry"
printf '  %-28s %s\n' "http://${DROPLET_IP}/" "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1/" -H "Host: ${DROPLET_IP}")"
echo
echo "Still to do by hand:"
echo "  1. R2 credentials into ${APP_DIR}/.env, then: php artisan config:cache && php artisan storage:check"
echo "  2. cat /root/admin-credentials.txt  →  log in at http://${DROPLET_IP}/  →  change the password  →  rm the file"
echo "  3. Then Phase B in DEPLOY.md."
