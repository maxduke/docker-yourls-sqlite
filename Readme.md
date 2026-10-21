
# YOURLS 1.10.6 with SQLite

A lightweight Docker image for YOURLS using SQLite.

Based on the official YOURLS Apache image and the
SQLite driver from Flameborn/yourls-sqlite.

## Docker image

Docker Hub: maxduke/yourls-sqlite

## Docker Compose

```yaml
services:
  yourls:
    image: maxduke/yourls-sqlite:1.10.6-sqlite1
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

Set YOURLS_PASS securely in a local .env file.

Never commit real credentials.

## Persistence

Always persist:

/var/www/html/user/sqlite

Use YOURLS_DB_NAME=sqlite/yourls to ensure that the
database is stored in the persistent volume.

## Ports

The official YOURLS Apache image listens on port 8080.

## Upgrading

YOURLS database upgrades are designed primarily for
MySQL. SQLite migrations require separate verification.

See docs/UPGRADING.md.

## Testing

Build:

    docker build -t yourls-sqlite:test .

Test:

    bash tests/smoke.sh yourls-sqlite:test

## Compatibility

- YOURLS: 1.10.6
- PHP: supplied by the pinned YOURLS base image
- SQLite driver: Flameborn/yourls-sqlite (pinned submodule)

SQLite is a community integration, not an officially
supported YOURLS database backend.
