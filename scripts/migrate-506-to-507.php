<?php
declare(strict_types=1);

// CLI only, opt-in, never loaded by the driver or container entrypoint.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

try {
    $args = getopt('', ['file:', 'prefix:', 'apply', 'backup:']);
    $dbName = getenv('YOURLS_DB_NAME') ?: 'sqlite/yourls';
    $prefix = $args['prefix'] ?? (getenv('YOURLS_DB_PREFIX') ?: 'yourls_');
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $prefix)) {
        throw new RuntimeException('Unsupported table prefix');
    }
    if (!isset($args['file']) && !preg_match('~^sqlite/[a-zA-Z0-9_.-]+$~D', $dbName)) {
        throw new RuntimeException('Unsupported database name; supply --file explicitly');
    }
    $file = $args['file'] ?? "/var/www/html/user/{$dbName}.sq3";
    if (!is_file($file) || !is_readable($file)) {
        throw new RuntimeException("Existing readable database required: {$file}");
    }
    // URI mode=ro/rw prevents PDO silently creating a database on a bad path.
    $uri = 'file:' . str_replace(['%', '?', '#'], ['%25', '%3F', '%23'], realpath($file));
    $apply = isset($args['apply']);
    $db = new PDO('sqlite:' . $uri . '?mode=' . ($apply ? 'rw' : 'ro'), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    if ($db->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
        throw new RuntimeException('Database integrity check failed');
    }
    $options = "`{$prefix}options`";
    $urls = "`{$prefix}url`";
    $readVersion = static function () use ($db, $options): string {
        $versions = $db->query("SELECT option_value FROM {$options} WHERE option_name = 'db_version'")->fetchAll(PDO::FETCH_COLUMN);
        if (count($versions) !== 1 || !in_array((string)$versions[0], ['506', '507'], true)) {
            throw new RuntimeException('Exactly one DB version 506 or 507 option is required');
        }
        return (string)$versions[0];
    };
    $version = $readVersion();
    $index = $prefix === 'yourls_' ? 'url_idx' : $prefix . 'url_url_idx';
    $validateIndex = static function () use ($db, $index, $prefix): bool {
        $stmt = $db->prepare("SELECT tbl_name FROM sqlite_master WHERE type = 'index' AND name = ?");
        $stmt->execute([$index]);
        $owner = $stmt->fetchColumn();
        if ($owner === false) {
            return false;
        }
        $columns = $db->query("PRAGMA index_info(`{$index}`)")->fetchAll(PDO::FETCH_COLUMN, 2);
        $metadata = $db->query('PRAGMA index_list(`' . $prefix . 'url`)')->fetchAll(PDO::FETCH_ASSOC);
        $entry = array_values(array_filter($metadata, static fn($row) => $row['name'] === $index));
        if ($owner !== $prefix . 'url' || $columns !== ['url'] || count($entry) !== 1 || $entry[0]['unique'] || $entry[0]['partial']) {
            throw new RuntimeException('Existing index name has incompatible definition; no changes made');
        }
        return true;
    };
    $hasIndex = $validateIndex();
    // Fail early on malformed URL tables, even for a read-only inspection.
    $db->query("SELECT keyword, url, title, timestamp, ip, clicks FROM {$urls} LIMIT 0");
    echo "DB {$version}; index {$index}: " . ($hasIndex ? 'valid' : 'missing') . "\n";
    if (!$apply) {
        echo "Read-only check. Stop the app; use --apply --backup=/safe/new-backup.sq3 to migrate.\n";
        exit(0);
    }
    if ($version === '507' && $hasIndex) {
        echo "Already current; no changes made.\n";
        exit(0);
    }
    $backup = $args['backup'] ?? '';
    if ($backup === '' || file_exists($backup) || !is_dir(dirname($backup))) {
        throw new RuntimeException('--backup must name a new file in an existing directory');
    }
    // VACUUM INTO provides a consistent SQLite backup, including any WAL content.
    // Operators must stop other writers for the entire backup/migration window.
    $oldUmask = umask(0077);
    try {
        $db->exec('VACUUM INTO ' . $db->quote($backup));
    } finally {
        umask($oldUmask);
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        if ($readVersion() !== $version) {
            throw new RuntimeException('Database version changed; stop all writers and retry');
        }
        if (!$validateIndex()) {
            $db->exec("CREATE INDEX `{$index}` ON {$urls} (url)");
        }
        $validateIndex();
        if ($version === '506') {
            $stmt = $db->prepare("UPDATE {$options} SET option_value = '507' WHERE option_name = 'db_version' AND option_value = '506'");
            $stmt->execute();
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Version update failed');
            }
        }
        if ($db->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('Post-migration integrity check failed');
        }
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
    echo "SQLite DB successfully migrated to 507; backup: {$backup}\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
