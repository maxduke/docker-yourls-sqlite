# YOURLS 1.10.6 with SQLite

A lightweight Docker image based on the official `yourls:1.10.6-apache` image.
The SQLite drop-in is maintained directly in `yourls-sqlite/`; no submodule
checkout or core-file `sed` patch is needed.

## Run

Docker Hub: `maxduke/yourls-sqlite`. The next proposed tag is
`1.10.6-sqlite2` (available only after a separate successful release).
For local testing:

```sh
docker build -t yourls-sqlite:test .
```

Example for a **new** deployment (do not replace an existing deployment's
volume or database name with this example):

```yaml
services:
  yourls:
    image: yourls-sqlite:test
    restart: unless-stopped
    ports:
      - "8080:8080"
    environment:
      YOURLS_SITE: https://short.example.com
      YOURLS_DB_NAME: sqlite/yourls
      YOURLS_USER: admin
      YOURLS_PASS: ${YOURLS_PASS:?Set YOURLS_PASS}
    volumes:
      - yourls-data:/var/www/html/user/sqlite

volumes:
  yourls-data:
```

Visit `/admin/install.php` once for a new database. Set `YOURLS_PASS` securely
in a local `.env` file; never commit real credentials. Apache listens on 8080.

## Existing databases and persistence

The path rule is unchanged: `YOURLS_USERDIR/YOURLS_DB_NAME.sq3`.
With `YOURLS_DB_NAME=sqlite/yourls`, persist `/var/www/html/user/sqlite`;
the file is `/var/www/html/user/sqlite/yourls.sq3`. Keep your current name,
prefix, mounts and permissions. The driver does not move/rebuild existing
tables, change journal mode, or migrate schema during startup.

Existing Flameborn SQLite schemas (including nullable `option_id` and the old
composite options primary key) remain readable and writable. New installations
use an integer option ID, unique option name, and native SQLite indexes,
including `url_idx` (`<prefix>url_url_idx` for non-default prefixes).
Databases already marked 507 by the old driver may still lack this index;
the manual tool can repair that case without changing data.

See [upgrade and rollback instructions](docs/UPGRADING.md) before any upgrade.
A bind mount over the entire `user` directory or over `user/db.php` can hide
the image's new driver; verify this on a disposable copy before deployment.

## Compatibility and limits

- YOURLS: **1.10.6**, database schema **507**.
- PHP: **8.5**, checked explicitly in CI against the pinned YOURLS tag.
  The base tag is version-pinned but not digest-pinned; a changed PHP minor
  deliberately fails CI and requires review.
- SQLite: PDO SQLite from the official image; Unicode, including emoji, works.
- Community integration, not an officially supported YOURLS database backend.
- Core install, options, API, redirect/logging, admin search and statistics are
  tested. Third-party plugins that issue MySQL-specific SQL need separate review.
- The driver uses the supported database-version shunt with a `5.5` compatibility
  token for YOURLS's MySQL-only installer gate. This is not SQLite's real version.
- Random stats register SQLite `RAND()`; date/hour stats retain core bound
  parameters, aggregate queries, table prefixes and timezone offsets.

## Tests and publishing

```sh
bash tests/smoke.sh yourls-sqlite:test
SMOKE_PREFIX=custom_ bash tests/smoke.sh yourls-sqlite:test
SMOKE_LEGACY=506 bash tests/smoke.sh yourls-sqlite:test
SMOKE_LEGACY=507 SMOKE_PREFIX=legacy_ bash tests/smoke.sh yourls-sqlite:test
docker run --rm --entrypoint php -v "$PWD:/repo:ro" yourls-sqlite:test /repo/tests/migration-check.php
```

Tests create uniquely named disposable Docker volumes and remove only those
volumes. No production Compose or database is used. CI checks fresh HTTP
installation, API creation, UTF-8/quotes, redirects, counters/logs, actual admin
and statistics pages, container restart/recreation, legacy 506/507 fixtures,
and migration backups/refusal/rollback. Failed tests print HTTP/container logs.

Publishing is a separate manual workflow on `master`, gated by the tests.
Pull requests never push an image. Merging and publishing are separate actions.

## Driver attribution

Based on Flameborn/yourls-sqlite at
`67835bed79236700f035b0cd93120c70f9118ed6`, originally by Ozh, via reanimus.
See [provenance and redistribution permissions](yourls-sqlite/NOTICE.md), the
preserved upstream README, and the full YOURLS MIT license alongside the driver.
