#!/usr/bin/env bash
# Copy the module (working tree) into a test shop, trust the mock CMI's HTTPS certificate,
# and copy the bench scripts. Usage: tests/e2e/deploy.sh ps8|ps9
set -euo pipefail
# Git Bash on Windows would rewrite the container paths below.
export MSYS_NO_PATHCONV=1
service="${1:?ps8 or ps9}"
here="$(cd "$(dirname "$0")" && pwd)"
root="$(cd "$here/../.." && pwd)"
container="$(cd "$here" && docker compose ps -q "$service")"

# Module files, as in the release zip (without tests and tooling).
tar -C "$root" --exclude=.git --exclude=tests --exclude=tools --exclude=docs --exclude=.github -cf - . \
  | docker exec -i "$container" sh -c 'rm -rf /tmp/cmi && mkdir -p /tmp/cmi && tar -C /tmp/cmi -xf - && mkdir -p /var/www/html/modules/cmi && cp -r /tmp/cmi/. /var/www/html/modules/cmi/ && chown -R www-data:www-data /var/www/html/modules/cmi'

# The mock's internal CA (Caddy), so the module's https:// API calls are verified for real.
docker exec "$container" sh -c 'cp /cmi-ca/caddy/pki/authorities/local/root.crt /usr/local/share/ca-certificates/cmi-test.crt && update-ca-certificates >/dev/null 2>&1 || true'

# Bench scripts, through tar too (docker cp needs Windows paths under Git Bash).
tar -C "$here" -cf - bootstrap.php configure.php harness.php \
  | docker exec -i "$container" sh -c 'mkdir -p /cmi-tests && tar -C /cmi-tests -xf -'

# PrestaShop's cache must not keep an older copy of the module's classes or templates.
docker exec "$container" sh -c 'rm -rf /var/www/html/var/cache/* 2>/dev/null || true'

# Install (or upgrade) with PrestaShop's console: it boots the Symfony kernel modules need.
docker exec -u www-data "$container" sh -c 'cd /var/www/html && (php bin/console prestashop:module install cmi || php bin/console prestashop:module upgrade cmi) 2>&1 | tail -2'
echo "deployed to $service"
