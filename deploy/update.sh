#!/usr/bin/env bash
# Deploy the current origin/main to this production box.
#
#     bash /var/www/tuition/deploy/update.sh          # latest main
#     bash /var/www/tuition/deploy/update.sh <sha>    # roll back (or forward) to a specific commit
#     bash /var/www/tuition/deploy/update.sh --force  # skip the CI check (see below)
#
# Implements "Update procedure" in DEPLOY.md, in the order that document
# explains — the one that matters is cache:clear AFTER migrate, because the
# application cache holds serialised models and a warm entry written before
# a migration deserialises without the new columns.
#
# Refuses to deploy a commit whose CI is red or still running. The MySQL
# leg of CI is the only thing that exercises production's database engine
# before this script does.

set -euo pipefail

APP_DIR=/var/www/tuition
REPO_API=https://api.github.com/repos/guansoon99/tuition-centre
DOMAIN=osterqin.com

TARGET=origin/main
FORCE=0
for arg in "$@"; do
    case "$arg" in
        --force) FORCE=1 ;;
        *)       TARGET="$arg" ;;
    esac
done

say()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
fail() { printf '\n\033[1;31m!! %s\033[0m\n' "$*"; exit 1; }

cd "$APP_DIR"

# ---------------------------------------------------------------- 1. what
say "1 — resolve the target"
git fetch -q origin
BEFORE="$(git rev-parse --short HEAD)"
AFTER="$(git rev-parse --short "$TARGET")" || fail "unknown ref: $TARGET"
echo "  deployed now: $BEFORE"
echo "  target:       $AFTER  ($TARGET)"
[ "$BEFORE" = "$AFTER" ] && echo "  already there — nothing to deploy" && exit 0

# ---------------------------------------------------------------- 2. CI gate
say "2 — CI for $AFTER"
FULL_SHA="$(git rev-parse "$TARGET")"
CI="$(curl -sf "$REPO_API/commits/$FULL_SHA/check-runs" | python3 -c '
import json, sys
runs = json.load(sys.stdin).get("check_runs", [])
if not runs:
    print("none"); sys.exit()
bad = [r["name"] for r in runs if r["conclusion"] not in ("success", "skipped", "neutral")]
pending = [r["name"] for r in runs if r["status"] != "completed"]
print("pending: " + ", ".join(pending) if pending else ("failed: " + ", ".join(bad) if bad else "green"))
' 2>/dev/null || echo "unreachable")"
echo "  $CI"
case "$CI" in
    green) ;;
    none|unreachable)
        [ "$FORCE" = 1 ] || fail "no CI result available for this commit — re-run with --force to deploy anyway" ;;
    *)
        [ "$FORCE" = 1 ] || fail "CI is not green — fix it, or --force if you have a reason and know what it is" ;;
esac

# ---------------------------------------------------------------- 3. code
say "3 — code $BEFORE -> $AFTER"
git reset -q --hard "$TARGET"

# ---------------------------------------------------------------- 4. deps
if git diff --name-only "$BEFORE" "$AFTER" -- composer.lock | grep -q .; then
    say "4 — composer.lock changed: installing"
    composer install --no-dev --optimize-autoloader --no-interaction --quiet
else
    say "4 — composer.lock unchanged: skipping install"
fi

# ---------------------------------------------------------------- 5. migrate
say "5 — migrate"
php artisan migrate --force

# ---------------------------------------------------------------- 6. caches
say "6 — caches: clear after migrate (see DEPLOY.md), then rebuild"
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
chown -R www-data:www-data storage bootstrap/cache

# ---------------------------------------------------------------- 7. fpm + worker
say "7 — reload PHP-FPM, restart the queue worker"
systemctl reload php8.3-fpm
# The worker holds the old code in memory until it exits. queue:restart asks
# it to finish its current job and stop; systemd (Restart=always) brings it
# back on the new code. See deploy/tuition-queue.service. As www-data: the
# signal is a cache entry, and this runs after step 6's chown, so root would
# leave a root-owned file under storage/framework/cache for the app to trip
# over (see "Running artisan on the box" in deploy-production.md).
sudo -u www-data php artisan queue:restart

# ---------------------------------------------------------------- 8. verify
say "8 — verify"
STATUS="$(curl -sk -o /dev/null -w '%{http_code}' --resolve "${DOMAIN}:443:127.0.0.1" "https://${DOMAIN}/login")"
echo "  https://${DOMAIN}/login -> $STATUS"
[ "$STATUS" = 200 ] || fail "site is not answering 200 after deploy — roll back with: $0 $BEFORE"
echo "  migrations: $(php artisan migrate:status 2>/dev/null | grep -c Ran) ran, $(php artisan migrate:status 2>/dev/null | grep -c Pending) pending"
# The restart signal in step 7 makes the worker exit and systemd bring it
# back (RestartSec=5), so checked straight away it reads "activating". Give
# it a few seconds before reporting.
for _ in 1 2 3 4 5 6; do
    systemctl is-active --quiet tuition-queue 2>/dev/null && break
    sleep 2
done
echo "  queue worker: $(systemctl is-active tuition-queue 2>/dev/null || echo not-installed)"

# An app route that ends in an image extension. nginx's static-asset block
# swallowed these with a bare 404 until 2026-09-08; the fix is a try_files
# fall-through in that block, and this is the check that it stays. Signed
# out, Laravel answers 302 to /login; nginx answering itself gives 404.
MEDIA="$(curl -sk -o /dev/null -w '%{http_code}' --resolve "${DOMAIN}:443:127.0.0.1" "https://${DOMAIN}/courses/1/media/materials/probe.webp")"
echo "  course-media route through nginx -> $MEDIA (302 = handed to Laravel)"
[ "$MEDIA" = 302 ] || fail "nginx is answering the course-media route itself - check the try_files fall-through in the static-asset block"

say "deployed $BEFORE -> $AFTER"
echo "  roll back with:  bash $0 $BEFORE"
echo "  (a migration in this deploy is NOT undone by rolling the code back — see Rollback in DEPLOY.md)"
