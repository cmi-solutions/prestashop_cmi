#!/usr/bin/env bash
# Upgrade test: install version 1.x (git tag/branch given, default the 1.x release for PrestaShop 8, "prestashop_v8"), give it 1.x settings
# and a 1.x order, upgrade to the working tree, and check the result.
# Usage: tests/e2e/upgrade.sh ps8|ps872|ps9 [git-ref]. It replaces the module installed in that shop.
set -euo pipefail
export MSYS_NO_PATHCONV=1
service="${1:?ps8 or ps9}"
ref="${2:-prestashop_v8}"
here="$(cd "$(dirname "$0")" && pwd)"
root="$(cd "$here/../.." && pwd)"
container="$(cd "$here" && docker compose ps -q "$service")"
console() { docker exec -u www-data "$container" sh -c "cd /var/www/html && php bin/console prestashop:module $1 cmi 2>&1 | tail -2"; }

console uninstall || true
docker exec "$container" rm -rf /var/www/html/modules/cmi /var/www/html/var/cache/prod /var/www/html/var/cache/dev
# Some 1.x releases (prestashop_v8) keep the module in a cmi/ subfolder, others at the root.
tree="$ref"
if (cd "$root" && git cat-file -e "$ref:cmi/cmi.php" 2>/dev/null); then tree="$ref:cmi"; fi
(cd "$root" && git archive "$tree") | docker exec -i "$container" sh -c 'mkdir -p /var/www/html/modules/cmi && tar -C /var/www/html/modules/cmi -xf - && chown -R www-data:www-data /var/www/html/modules/cmi'
console install
tar -C "$here" -cf - bootstrap.php upgrade-check.php | docker exec -i "$container" sh -c 'mkdir -p /cmi-tests && tar -C /cmi-tests -xf -'
docker exec -u www-data "$container" php /cmi-tests/upgrade-check.php before

# Upload of the new version over the old one (an upgrade by zip keeps the old files).
# CMI_PACKAGE=path/to/release.zip uploads that zip; otherwise the working tree.
if [ -n "${CMI_PACKAGE:-}" ]; then
  docker exec -i "$container" sh -c 'cat > /tmp/cmi-package.zip' < "$CMI_PACKAGE"
  docker exec "$container" php -r '$z = new ZipArchive(); if ($z->open("/tmp/cmi-package.zip") !== true || !$z->extractTo("/var/www/html/modules")) { exit(1); }'
  docker exec "$container" sh -c 'chown -R www-data:www-data /var/www/html/modules/cmi && rm -rf /var/www/html/var/cache/prod /var/www/html/var/cache/dev'
else
  tar -C "$root" --exclude=.git --exclude=tests --exclude=tools --exclude=docs --exclude=.github -cf - . \
    | docker exec -i "$container" sh -c 'tar -C /var/www/html/modules/cmi -xf - && chown -R www-data:www-data /var/www/html/modules/cmi && rm -rf /var/www/html/var/cache/prod /var/www/html/var/cache/dev'
fi
docker exec "$container" sh -c 'cp /cmi-ca/caddy/pki/authorities/local/root.crt /usr/local/share/ca-certificates/cmi-test.crt && update-ca-certificates >/dev/null 2>&1 || true'
console upgrade
docker exec -u www-data "$container" php /cmi-tests/upgrade-check.php after
