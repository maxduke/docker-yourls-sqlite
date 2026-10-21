# Upgrade and rollback with SQLite

These instructions describe operator actions; nothing migrates at startup.
Never experiment on the only copy of a database. Keep your existing Compose,
volume, `YOURLS_DB_NAME` and prefix. Do not run `docker compose down -v`.

## Compatibility

The maintained drop-in keeps the old `user/<YOURLS_DB_NAME>.sq3` filename,
columns, keyword matching and existing nullable/composite option IDs. It does
not rebuild old tables or rewrite links/logs. Its improved new-install schema
applies only when installing a new database. A valid existing version 507 DB
requires no migration if the URL index exists.

YOURLS 1.10.6 expects DB version 507. The MySQL web upgrader is not supported
for SQLite. Use the manual tool for **506 → 507** or for an old **507 missing
its URL index**. Versions older than 506 or newer than 507 are refused; review
their upstream migrations separately. Do not change the version by hand.

## Rehearse on a copy

1. Stop all application processes/writers for a consistent snapshot. Back up
   the entire persistent directory, including any `-wal`/`-shm` files, plus
   config and the old image identifier. Use a SQLite backup method or a stopped
   filesystem copy; copying only the main file while writers run is unsafe.
2. Restore to an isolated directory/volume and run `PRAGMA integrity_check`.
   Use the proposed image there with the same DB name and table prefix.
3. Inspect without writes (default mode, opens SQLite read-only):

   ```sh
   php /usr/local/bin/migrate-506-to-507.php \
     --file=/isolated/yourls.sq3 --prefix=yourls_
   ```

4. With writers still stopped, explicitly apply on that copy:

   ```sh
   php /usr/local/bin/migrate-506-to-507.php \
     --file=/isolated/yourls.sq3 --prefix=yourls_ \
     --apply --backup=/isolated/backups/before-507.sq3
   ```

   The backup path must be a **new** file in an existing directory. The tool
   uses SQLite `VACUUM INTO` to capture a consistent backup, then `BEGIN
   IMMEDIATE` to create a non-unique full-URL index and update only the
   `db_version` row from 506 to 507. Any failure rolls back that transaction;
   the backup remains available. A preexisting conflicting index is refused.
   Valid 507/index state is a no-op, including on repeat runs. No links, logs,
   IDs or other options are updated. Core may update its own `version` option
   when the app is subsequently opened.
5. Verify integrity, link/log counts, representative redirects, option updates,
   admin search, individual and aggregate statistics, restart and recreation.
   If the entire `user` directory is mounted, ensure its `db.php` is the new
   drop-in, rather than a file hiding the one supplied by the image.

For a container, the tool is included at `/usr/local/bin/migrate-506-to-507.php`.
It defaults to `/var/www/html/user/<YOURLS_DB_NAME>.sq3` and
`YOURLS_DB_PREFIX` (default `yourls_`); `--file`/`--prefix` override those values.
Run as a user with appropriate file/directory permissions. Save backups in
persistent storage **outside the web-served directory**, not in `/tmp` or a
container layer. No root ownership change is required by this migration.

## Deployment and rollback

After a successful rehearsal and CI run, an operator can repeat the verified
backup/migration steps during downtime and deploy the reviewed image. This PR
does not perform that production action or change Compose.

To roll back, stop all writers, retain a separate copy of the failed state,
then restore the complete pre-upgrade backup and old image/config together.
Do not overwrite an open SQLite file or leave WAL files from a different
snapshot beside a restored database. A backup rollback discards changes made
after that backup, so reconcile any later links before switching traffic.

## Risks

The tests use synthetic old-driver schemas, not your production data or
plugins. Unknown plugin MySQL queries, preexisting inconsistent schemas,
insufficient disk space for the backup/full-URL index, filesystem locking,
and old bind-mounted driver files require local verification. Index creation
can hold a write lock and needs maintenance downtime on a large database.
Do not use this migration for future schema 508+.
