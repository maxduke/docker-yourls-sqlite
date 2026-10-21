
# Upgrading YOURLS with SQLite

This image uses a custom SQLite driver rather than
the default MySQL database backend.

## Before upgrading

1. Back up the complete persistent SQLite directory.
2. Verify SQLite integrity using PRAGMA integrity_check.
3. Check the old and new YOURLS_DB_VERSION.
4. Review upstream SQL schema migrations.
5. Test the new image with a disposable database.
6. Deploy only after all tests pass.

Do not assume that the official YOURLS web upgrade
works with SQLite. Upstream migration SQL may be
MySQL-specific.

## YOURLS 1.10.0 -> 1.10.6

Database version: 506 -> 507.

SQLite equivalent of the required URL index:

    CREATE INDEX IF NOT EXISTS url_idx
    ON yourls_url(url);

Then update the db_version option to 507.

Use scripts/migrate-506-to-507.php only with a
verified backup and an existing version 506 database.

Do not run migrations automatically during startup.

## Future upgrades

Do not reuse this migration for version 508 or later.
Review and implement each new schema migration
separately.
