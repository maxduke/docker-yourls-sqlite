
<?php
declare(strict_types=1);

// Manual migration ONLY for YOURLS DB 506 -> 507.
// Never run automatically at container startup.

$dbName = getenv('YOURLS_DB_NAME') ?: 'sqlite/yourls';
$prefix = getenv('YOURLS_DB_PREFIX') ?: 'yourls_';

if (
    !preg_match('~^sqlite/[a-zA-Z0-9_.-]+$~D', $dbName) ||
    !preg_match('/^[a-zA-Z0-9_]+$/D', $prefix)
) {
    exit("Unsupported database path or prefix\n");
}

$file = "/var/www/html/user/{$dbName}.sq3";

if (!is_file($file)) {
    exit("Database not found: {$file}\n");
}

$db = new PDO("sqlite:{$file}", null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 5,
]);

if ($db->query("PRAGMA integrity_check")->fetchColumn() !== 'ok') {
    exit("Database integrity check failed\n");
}

$options = "`{$prefix}options`";
$urls = "`{$prefix}url`";

$version = $db->query(
    "SELECT option_value FROM {$options}
     WHERE option_name = 'db_version'"
)->fetchColumn();

if ((string)$version !== '506') {
    exit("Expected DB version 506; found: {$version}\n");
}

try {
    $db->beginTransaction();

    $db->exec(
        "CREATE INDEX IF NOT EXISTS url_idx
         ON {$urls} (url)"
    );

    $stmt = $db->prepare(
        "UPDATE {$options} SET option_value = '507'
         WHERE option_name = 'db_version'
           AND option_value = '506'"
    );
    $stmt->execute();

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Version update failed');
    }

    $db->commit();
    echo "SQLite DB successfully migrated to 507\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $e;
}
