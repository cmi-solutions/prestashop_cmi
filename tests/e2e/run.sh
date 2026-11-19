#!/usr/bin/env bash
# Deploy the module to the test shops, configure it against the mock CMI and run the scenarios.
# Usage: tests/e2e/run.sh [ps8] [ps9]   (both when omitted; the bench must be up)
set -uo pipefail
export MSYS_NO_PATHCONV=1
here="$(cd "$(dirname "$0")" && pwd)"
status=0
shops=("$@")
[ ${#shops[@]} -eq 0 ] && shops=(ps8 ps9)
for name in "${shops[@]}"; do
  echo "=== $name"
  "$here/deploy.sh" "$name" || { status=1; continue; }
  container="$(cd "$here" && docker compose ps -q "$name")"
  docker exec -u www-data "$container" php /cmi-tests/configure.php install=0 || { status=1; continue; }
  docker exec -u www-data "$container" php /cmi-tests/harness.php || status=1
done
exit $status
