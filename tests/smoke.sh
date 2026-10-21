#!/usr/bin/env bash
set -Eeuo pipefail

IMAGE="${1:-yourls-sqlite:test}"
NAME="yourls-sqlite-smoke-$$"
VOLUME="yourls-sqlite-smoke-data-$$"
PORT="${SMOKE_PORT:-18080}"
PREFIX="${SMOKE_PREFIX:-yourls_}"
LEGACY="${SMOKE_LEGACY:-}"
BASE="http://127.0.0.1:${PORT}"
TMP=$(mktemp -d)
ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)

cleanup() {
  local status=$?
  if (( status != 0 )); then
    echo '=== Container logs ===' >&2
    docker logs "$NAME" >&2 || true
    for page in "$TMP"/*; do
      [[ -f "$page" ]] && { echo "=== $page ===" >&2; cat "$page" >&2; }
    done
  fi
  docker rm -f "$NAME" >/dev/null 2>&1 || true
  # This name was created below for this test invocation only.
  docker volume rm "$VOLUME" >/dev/null 2>&1 || true
  rm -rf -- "$TMP"
}
trap cleanup EXIT

wait_for_http() {
  for i in {1..30}; do
    if curl -fsS "$BASE/admin/install.php" >/dev/null 2>&1; then return 0; fi
    sleep 2
  done
  echo 'HTTP server did not become ready' >&2
  return 1
}

check_redirect() {
  curl -fsS -D "$TMP/headers" -o /dev/null "$BASE/cismoke"
  grep -Eq '^HTTP/[^ ]+ 301' "$TMP/headers"
  grep -qi '^Location: https://example.com/yourls-ci-test' "$TMP/headers"
}
api() {
  curl -fsS "$BASE/yourls-api.php" \
    --data-urlencode 'format=json' \
    --data-urlencode 'username=ciadmin' \
    --data-urlencode 'password=ci-test-only-password' "$@"
}
start_container() {
  docker run -d --name "$NAME" \
    -p "127.0.0.1:${PORT}:8080" \
    -v "$VOLUME:/var/www/html/user/sqlite" \
    -e "YOURLS_SITE=$BASE" -e YOURLS_DB_NAME=sqlite/yourls \
    -e "YOURLS_DB_PREFIX=$PREFIX" \
    -e YOURLS_USER=ciadmin -e YOURLS_PASS=ci-test-only-password \
    "$IMAGE" >/dev/null
  wait_for_http
}

docker volume create "$VOLUME" >/dev/null
start_container

if [[ -n "$LEGACY" ]]; then
  docker cp "$ROOT/tests/legacy-fixture.php" "$NAME:/tmp/legacy-fixture.php"
  docker exec -e "LEGACY_VERSION=$LEGACY" "$NAME" php /tmp/legacy-fixture.php
  # Migration is intentionally manual, using the copy inside the image.
  docker exec "$NAME" php /usr/local/bin/migrate-506-to-507.php
  docker exec "$NAME" php /usr/local/bin/migrate-506-to-507.php \
    --apply --backup=/tmp/before-migration.sq3
  docker exec "$NAME" php /usr/local/bin/migrate-506-to-507.php --apply
  docker exec "$NAME" php -r '
    $db = new PDO("sqlite:/tmp/before-migration.sq3");
    $prefix = getenv("YOURLS_DB_PREFIX");
    if ($db->query("PRAGMA integrity_check")->fetchColumn() !== "ok") exit(1);
    if ($db->query("SELECT clicks FROM {$prefix}url WHERE keyword = '\''legacy'\''")->fetchColumn() != 7) exit(2);
  '
else
  curl -fsS "$BASE/admin/install.php" > "$TMP/install-form.html"
  grep -q 'name="install"' "$TMP/install-form.html"
  curl -fsS --data-urlencode 'install=Install YOURLS' "$BASE/admin/install.php" > "$TMP/install-result.html"
  grep -q 'YOURLS Administration Page' "$TMP/install-result.html"
  if grep -q "class='error'" "$TMP/install-result.html"; then exit 1; fi
fi

# Always provide title so integration tests do not depend on external title fetches.
api --data-urlencode action=shorturl \
  --data-urlencode url=https://example.com/yourls-ci-test \
  --data-urlencode keyword=cismoke --data-urlencode "title=CI O'Reilly 中文 🦆" > "$TMP/create.json"
python3 - "$TMP/create.json" <<'PY'
import json, sys
x=json.load(open(sys.argv[1]))
assert x['status']=='success', x
assert x['url']['keyword']=='cismoke', x
assert x['url']['title']=="CI O'Reilly 中文 🦆", x
PY
check_redirect

# Restart and then recreate: both must preserve the same isolated volume.
docker restart "$NAME" >/dev/null
wait_for_http
docker rm -f "$NAME" >/dev/null
start_container
check_redirect

for action in stats url-stats; do
  api --data-urlencode "action=$action" --data-urlencode filter=rand \
    --data-urlencode limit=10 --data-urlencode "shorturl=$BASE/cismoke" > "$TMP/$action.json"
  python3 - "$TMP/$action.json" <<'PY'
import json,sys
x=json.load(open(sys.argv[1]))
assert int(x['statusCode'])==200, x
if 'link' in x: assert int(x['link']['clicks'])==2, x
PY
done

# Private admin/search and per-link/aggregate statistics through actual HTTP.
curl --fail-with-body -sS -c "$TMP/cookies" "$BASE/admin/" > "$TMP/login-form.html"
nonce=$(python3 - "$TMP/login-form.html" <<'PYCODE'
from html.parser import HTMLParser
import sys
class Form(HTMLParser):
    def handle_starttag(self, tag, attrs):
        attrs=dict(attrs)
        if tag=='input' and attrs.get('name')=='nonce': print(attrs['value'])
Form().feed(open(sys.argv[1]).read())
PYCODE
)
[[ -n "$nonce" ]]
curl --fail-with-body -sS -b "$TMP/cookies" -c "$TMP/cookies" \
  --data-urlencode username=ciadmin --data-urlencode password=ci-test-only-password \
  --data-urlencode "nonce=$nonce" "$BASE/admin/" > "$TMP/login.html"
for path in '/admin/?search=yourls-ci-test&search_in=url' '/cismoke+' '/cismoke+all'; do
  curl -fsS -b "$TMP/cookies" "$BASE$path" > "$TMP/page.html"
  grep -q 'cismoke' "$TMP/page.html"
  if grep -Eqi 'Fatal error|Uncaught|SQLSTATE|name="password"' "$TMP/page.html"; then exit 1; fi
done

docker cp "$ROOT/tests/driver-check.php" "$NAME:/tmp/driver-check.php"
docker exec -e "SMOKE_LEGACY=$LEGACY" "$NAME" php /tmp/driver-check.php
if docker logs "$NAME" 2>&1 | grep -Ei 'PHP (Fatal|Warning|Deprecated|Parse)|SQLSTATE|Uncaught'; then
  echo 'Unexpected PHP/SQL diagnostics' >&2
  exit 1
fi
echo "PASS: fresh/legacy=$LEGACY prefix=$PREFIX install, API, redirect, stats, restart/recreate persistence"
