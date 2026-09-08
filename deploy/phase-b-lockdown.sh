#!/usr/bin/env bash
# Firewall half of B5 in DEPLOY.md. Run ONLY after Phase C — once the domain
# resolves through Cloudflare. Before that it would also lock out the
# direct-to-origin rehearsal in B6, and you with it.
#
# Replaces the open 80/443 rules with Cloudflare's published ranges, so the
# X-Forwarded-* headers the app now trusts can only ever come from Cloudflare.
set -euo pipefail
say() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }

say "Cloudflare ranges (live from cloudflare.com)"
V4="$(curl -sf https://www.cloudflare.com/ips-v4)"
V6="$(curl -sf https://www.cloudflare.com/ips-v6)"
[ -n "$V4" ] && [ -n "$V6" ] || { echo "!! could not fetch Cloudflare ranges — not touching the firewall"; exit 1; }
echo "$V4" "$V6" | wc -w | sed 's/^/  ranges: /'

say "Allow 80/443 from Cloudflare only"
for ip in $V4 $V6; do
    ufw allow proto tcp from "$ip" to any port 80,443 comment cloudflare > /dev/null
done

say "Remove the open 80/443 rules"
ufw --force delete allow 80/tcp  > /dev/null || true
ufw --force delete allow 443/tcp > /dev/null || true
ufw reload > /dev/null

say "Result"
ufw status | grep -E "^Status|OpenSSH|80,443" | head -6
echo "  ... $(ufw status | grep -c cloudflare) Cloudflare rules"
echo
echo "Verify from OUTSIDE: curl -m 5 -sI http://157.245.149.36/ must now time out, while https://<domain>/ still works."
