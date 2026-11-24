#!/usr/bin/env bash
# Copy the module into a test shop, trust the mock CMI's HTTPS certificate, and copy the
# bench scripts. Usage: tests/e2e/deploy.sh ps8|ps9
#   CMI_PACKAGE=path/to/cmi_prestashop_vX.Y.Z.zip  install that release zip, unpacked over the
#                                                  module folder as "Upload a module" does
#   (otherwise)                                    copy the working tree
set -euo pipefail
# Git Bash on Windows would rewrite the container paths below.
export MSYS_NO_PATHCONV=1
service="${1:?ps8 or ps9}"
here="$(cd "$(dirname "$0")" && pwd)"
root="$(cd "$here/../.." && pwd)"
container="$(cd "$here" && docker compose ps -q "$service")"

if [ -n "${CMI_PACKAGE:-}" ]; then
  # The zip holds a cmi/ folder; PrestaShop unpacks it into modules/.
  docker exec -i "$container" sh -c 'cat > /tmp/cmi-package.zip' < "$CMI_PACKAGE"
  docker exec "$container" php -r '$z = new ZipArchive(); if ($z->open("/tmp/cmi-package.zip") !== true || !$z->extractTo("/var/www/html/modules")) { fwrite(STDERR, "cannot unpack the package\n"); exit(1); } echo "unpacked ", $z->numFiles, " entries\n";'
  docker exec "$container" chown -R www-data:www-data /var/www/html/modules/cmi
else
  # Module files, as in the release zip (without tests and tooling).
  tar -C "$root" --exclude=.git --exclude=tests --exclude=tools --exclude=docs --exclude=.github -cf - . \
    | docker exec -i "$container" sh -c 'rm -rf /tmp/cmi && mkdir -p /tmp/cmi && tar -C /tmp/cmi -xf - && mkdir -p /var/www/html/modules/cmi && cp -r /tmp/cmi/. /var/www/html/modules/cmi/ && chown -R www-data:www-data /var/www/html/modules/cmi'
fi

# The mock's internal CA (Caddy), so the module's https:// API calls are verified for real.
# Readable by everyone: OpenSSL 1.1.1 (PHP 7.2 images) refuses a CA directory with an unreadable file.
docker exec "$container" sh -c 'cp /cmi-ca/caddy/pki/authorities/local/root.crt /usr/local/share/ca-certificates/cmi-test.crt && chmod 644 /usr/local/share/ca-certificates/cmi-test.crt && update-ca-certificates >/dev/null 2>&1 || true'

# Bench scripts, through tar too (docker cp needs Windows paths under Git Bash).
tar -C "$here" -cf - bootstrap.php configure.php harness.php \
  | docker exec -i "$container" sh -c 'mkdir -p /cmi-tests && tar -C /cmi-tests -xf -'

# PrestaShop's cache must not keep an older copy of the module's classes or templates.
docker exec "$container" sh -c 'rm -rf /var/www/html/var/cache/* 2>/dev/null || true'

# Install (or upgrade) with PrestaShop's console: it boots the Symfony kernel modules need.
docker exec -u www-data "$container" sh -c 'cd /var/www/html && (php bin/console prestashop:module install cmi || php bin/console prestashop:module upgrade cmi) 2>&1 | tail -2'
echo "deployed to $service"
