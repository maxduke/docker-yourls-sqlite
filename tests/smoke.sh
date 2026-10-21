
#!/usr/bin/env bash
set -Eeuo pipefail

IMAGE="${1:-yourls-sqlite:test}"
NAME="yourls-sqlite-smoke-$$"
VOLUME="yourls-sqlite-smoke-data-$$"
PORT="${SMOKE_PORT:-18080}"
BASE="http://127.0.0.1:${PORT}"

cleanup() {
  local status=$?
  if (( status != 0 )); then
    echo "=== Container logs ===" >&2
    docker logs "$NAME" >&2 || true
  fi
  docker rm -f "$NAME" >/dev/null 2>&1 || true
  docker volume rm "$VOLUME" >/dev/null 2>&1 || true
}
trap cleanup EXIT

wait_for_http() {
  for i in {1..30}; do
    if curl -fsS "$BASE/admin/install.php" >/dev/null 2>&1; then
      return 0
    fi
    sleep 2
  done
  echo "HTTP server did not become ready" >&2
  return 1
}

check_redirect() {
  local headers
  headers=$(curl -sSI "$BASE/cismoke")
  if ! grep -Eq '^HTTP/[^ ]+ 30[12378]' <<< "$headers" ||
     ! grep -qi '^Location: https://example.com/yourls-ci-test' <<< "$headers"; then
    echo "Redirect check failed: $headers" >&2
    return 1
  fi
}

# Create isolated test volume
docker volume create "$VOLUME" >/dev/null

docker run -d --name "$NAME" \
  -p "127.0.0.1:${PORT}:8080" \
  -v "$VOLUME:/var/www/html/user/sqlite" \
  -e "YOURLS_SITE=$BASE" \
  -e YOURLS_DB_NAME=sqlite/yourls \
  -e YOURLS_USER=ciadmin \
  -e YOURLS_PASS=ci-test-only-password \
  "$IMAGE" >/dev/null

wait_for_http

# Test fresh installation
page=$(curl -fsS "$BASE/admin/install.php")
grep -q 'name="install"' <<< "$page"

response=$(curl -fsS \
  --data-urlencode 'install=Install YOURLS' \
  "$BASE/admin/install.php")

if ! grep -q 'YOURLS Administration Page' <<< "$response"; then
  echo "Fresh installation failed" >&2
  exit 1
fi

# Check SQLite integrity and schema version
docker exec "$NAME" php -r '
  $file = "/var/www/html/user/sqlite/yourls.sq3";
  if (!is_file($file)) exit(1);
  $db = new PDO("sqlite:" . $file);
  if ($db->query("PRAGMA integrity_check")->fetchColumn() !== "ok") exit(2);
  $sql = "SELECT option_value FROM yourls_options WHERE option_name = '\''db_version'\''";
  if ((string)$db->query($sql)->fetchColumn() !== "507") exit(3);
'

# Create a short URL using the actual YOURLS API
created=$(curl -fsS "$BASE/yourls-api.php" \
  --data-urlencode 'action=shorturl' \
  --data-urlencode 'format=json' \
  --data-urlencode 'username=ciadmin' \
  --data-urlencode 'password=ci-test-only-password' \
  --data-urlencode 'url=https://example.com/yourls-ci-test' \
  --data-urlencode 'keyword=cismoke')

if ! grep -Eq '"status"[[:space:]]*:[[:space:]]*"success"' <<< "$created"; then
  echo "Short URL creation failed: $created" >&2
  exit 1
fi

# Check redirect
check_redirect

# Restart container, preserving test volume
docker restart "$NAME" >/dev/null
wait_for_http

# Existing link must still work after restart
check_redirect

echo "PASS: install, SQLite, API, redirect, persistence"
