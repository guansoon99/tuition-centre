#!/usr/bin/env bash
# Phase B (server side) of DEPLOY.md — TLS via Cloudflare Origin Certificate.
#
#     ssh root@<ip> 'bash -s -- <domain>' < phase-b.sh
#
# Expects the Origin Certificate already copied to the box:
#     /etc/ssl/cloudflare/origin.pem
#     /etc/ssl/cloudflare/origin.key
#
# Does B3 (nginx 443 + 80 redirect), B4 (.env back to https), and the
# TRUSTED_PROXIES half of B5. The firewall half of B5 is deliberately a
# separate script (phase-b-lockdown.sh), run only after Phase C confirms
# Cloudflare is live in front — restricting 80/443 to Cloudflare's ranges
# before then would also lock out the direct-to-origin rehearsal in B6.
#
# Re-runnable.

set -euo pipefail

DOMAIN="${1:?usage: bash -s -- <domain>}"
APP_DIR=/var/www/tuition
CERT=/etc/ssl/cloudflare/origin.pem
KEY=/etc/ssl/cloudflare/origin.key

say() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }

set_env() {
    local key="$1" value="$2"
    if grep -q "^${key}=" "$APP_DIR/.env"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "$APP_DIR/.env"
    else
        printf '%s=%s\n' "$key" "$value" >> "$APP_DIR/.env"
    fi
}

# ---------------------------------------------------------------- B3: cert
say "B3 — Origin Certificate present and sane"
[ -s "$CERT" ] || { echo "!! $CERT missing or empty"; exit 1; }
[ -s "$KEY" ]  || { echo "!! $KEY missing or empty";  exit 1; }
chmod 644 "$CERT"
chmod 600 "$KEY"
openssl x509 -in "$CERT" -noout -subject -enddate -ext subjectAltName 2>/dev/null | sed 's/^/  /'
# The key must match the certificate, or nginx refuses to start and every
# request 5xxs — checked here rather than discovered at reload.
if [ "$(openssl x509 -in "$CERT" -noout -pubkey)" != "$(openssl pkey -in "$KEY" -pubout 2>/dev/null)" ]; then
    echo "!! certificate and key do not match"; exit 1
fi
echo "  key matches certificate"

# ---------------------------------------------------------------- B3: nginx
say "B3 — nginx: 443 with the origin cert, 80 redirects. X-Frame-Options and nosniff come from the app (SecurityHeaders), not repeated here"
tee /etc/nginx/sites-available/tuition > /dev/null <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN} www.${DOMAIN};
    return 301 https://\$host\$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name ${DOMAIN} www.${DOMAIN};
    root /var/www/tuition/public;

    ssl_certificate     ${CERT};
    ssl_certificate_key ${KEY};
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;

    index index.php;

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

    location / { try_files \$uri \$uri/ /index.php?\$query_string; }

    location ~ \\.php\$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    location ^~ /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    location ~* \\.(js|css|png|jpg|jpeg|gif|webp|avif|ico|svg|woff2?|ttf)\$ {
        expires 1M;
        add_header Cache-Control "public";
    }

    location ~ /\\.(?!well-known).* { deny all; }
}
NGINX
nginx -t
systemctl reload nginx

# ---------------------------------------------------------------- B4: env
say "B4 — .env back to https, secure cookies on"
cd "$APP_DIR"
set_env APP_URL               "https://${DOMAIN}"
set_env SESSION_SECURE_COOKIE "true"

# ---------------------------------------------------------------- B5 (trust half)
say "B5 — trust Cloudflare's edge ranges (firewall half is phase-b-lockdown.sh, after go-live)"
set_env TRUSTED_PROXIES "cloudflare"

php artisan config:cache
php artisan route:cache
php artisan view:cache

# ---------------------------------------------------------------- report
say "Done. Checks:"
printf '  %-30s %s\n' "nginx" "$(systemctl is-active nginx)"
printf '  %-30s %s\n' "443 answers (direct, own cert)" "$(curl -sk -o /dev/null -w '%{http_code}' --resolve "${DOMAIN}:443:127.0.0.1" "https://${DOMAIN}/login")"
printf '  %-30s %s\n' "80 redirects to https" "$(curl -s -o /dev/null -w '%{http_code} -> %{redirect_url}' --resolve "${DOMAIN}:80:127.0.0.1" "http://${DOMAIN}/")"
printf '  %-30s %s\n' "APP_URL" "$(grep '^APP_URL=' .env)"
printf '  %-30s %s\n' "SESSION_SECURE_COOKIE" "$(grep '^SESSION_SECURE_COOKIE=' .env)"
printf '  %-30s %s\n' "TRUSTED_PROXIES" "$(grep '^TRUSTED_PROXIES=' .env)"
echo
echo "Next: Cloudflare -> SSL/TLS -> Full (strict). Then B6 rehearsal, B7 rules, Phase C."
