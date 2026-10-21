<?php
declare(strict_types=1);
$script = '/repo/scripts/migrate-506-to-507.php';
$dir = sys_get_temp_dir() . '/yourls-migration-' . bin2hex(random_bytes(6));
mkdir($dir);
function check($ok, $message) {
    if (!$ok) throw new RuntimeException($message);
}
function runMigration(array $args, int $expected = 0): string {
    global $script;
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    foreach ($args as $arg) $command .= ' ' . escapeshellarg($arg);
    exec($command . ' 2>&1', $lines, $code);
    $output = implode("\n", $lines);
    check($code === $expected, "Exit $code expected $expected: $output");
    return $output;
}
function fixture(string $file, string $version = '506'): PDO {
    $db = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE yourls_options (option_id bigint(20) NULL, option_name varchar(64) NOT NULL, option_value longtext NOT NULL, PRIMARY KEY(option_id,option_name))');
    $db->exec('CREATE TABLE yourls_url (keyword text PRIMARY KEY, url text, title text, timestamp text, ip text, clicks integer)');
    $db->exec("INSERT INTO yourls_options (option_name, option_value) VALUES ('db_version','$version'),('next_id','42')");
    $db->exec("INSERT INTO yourls_url VALUES ('test','https://example.com/Case','中文 🦆','2024-01-01','127.0.0.1',7)");
    return $db;
}
runMigration(['--file=' . $dir . '/missing.sq3'], 1);
check(!file_exists($dir . '/missing.sq3'), 'Missing file was created');
$file = $dir . '/506.sq3';
$db = fixture($file);
$before = $db->query('SELECT * FROM yourls_url')->fetchAll();
$schema = $db->query("SELECT sql FROM sqlite_master WHERE name='yourls_options'")->fetchColumn();
$hash = hash_file('sha256', $file);
runMigration(['--file=' . $file]);
check(hash_file('sha256', $file) === $hash, 'Dry-run changed database');
runMigration(['--file=' . $file, '--apply'], 1);
check(hash_file('sha256', $file) === $hash, 'Missing backup changed database');
runMigration(['--file=' . $file, '--prefix=bad-prefix'], 1);
runMigration(['--file=' . $file, '--apply', '--backup=' . $dir . '/backup.sq3']);
check($db->query("SELECT option_value FROM yourls_options WHERE option_name='db_version'")->fetchColumn() === '507', 'Migration version');
check($before === $db->query('SELECT * FROM yourls_url')->fetchAll(), 'Links changed');
check($schema === $db->query("SELECT sql FROM sqlite_master WHERE name='yourls_options'")->fetchColumn(), 'Legacy options table rebuilt');
$backup = new PDO('sqlite:' . $dir . '/backup.sq3');
check($backup->query("SELECT option_value FROM yourls_options WHERE option_name='db_version'")->fetchColumn() === '506', 'Backup version');
$hash = hash_file('sha256', $file);
runMigration(['--file=' . $file, '--apply']);
check(hash_file('sha256', $file) === $hash, 'Idempotent migration changed database');

$file = $dir . '/507.sq3';
$db = fixture($file, '507');
runMigration(['--file=' . $file, '--apply', '--backup=' . $dir . '/507-backup.sq3']);
check($db->query("SELECT COUNT(*) FROM sqlite_master WHERE name='url_idx'")->fetchColumn() === 1, '507 missing index repair');

$file = $dir . '/future.sq3';
$db = fixture($file, '508');
runMigration(['--file=' . $file, '--apply', '--backup=' . $dir . '/future-backup.sq3'], 1);
check(!file_exists($dir . '/future-backup.sq3'), 'Future version reached backup stage');

$file = $dir . '/conflict.sq3';
$db = fixture($file);
$db->exec('CREATE INDEX url_idx ON yourls_url(title)');
runMigration(['--file=' . $file, '--apply', '--backup=' . $dir . '/conflict-backup.sq3'], 1);
check($db->query("SELECT option_value FROM yourls_options WHERE option_name='db_version'")->fetchColumn() === '506', 'Conflicting index advanced version');

$file = $dir . '/rollback.sq3';
$db = fixture($file);
$db->exec("CREATE TRIGGER fail_version BEFORE UPDATE ON yourls_options BEGIN SELECT RAISE(ABORT,'injected failure'); END");
runMigration(['--file=' . $file, '--apply', '--backup=' . $dir . '/rollback-backup.sq3'], 1);
check($db->query("SELECT COUNT(*) FROM sqlite_master WHERE name='url_idx'")->fetchColumn() === 0, 'Index not rolled back');
check($db->query("SELECT option_value FROM yourls_options WHERE option_name='db_version'")->fetchColumn() === '506', 'Version not rolled back');
echo "PASS: read-only check, backup, 506 migration, 507 repair, idempotence, refusal, transactional rollback\n";
