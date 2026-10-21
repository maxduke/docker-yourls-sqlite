<?php
// Run against disposable installed containers only.
define('YOURLS_INSTALLING', true);
require '/var/www/html/includes/load-yourls.php';
function check($ok, $message) {
    if (!$ok) throw new RuntimeException($message);
}
$db = yourls_get_db('read-ci_driver');
check(yourls_is_installed(), 'Existing options not loaded');
check(yourls_check_database_version(), 'MySQL compatibility gate');
check((string)yourls_get_option('db_version') === '507', 'Schema version');
check($db->fetchValue('PRAGMA integrity_check') === 'ok', 'Integrity');
$prefix = getenv('YOURLS_DB_PREFIX') ?: 'yourls_';
$index = $prefix === 'yourls_' ? 'url_idx' : $prefix . 'url_url_idx';
check($db->fetchCol("PRAGMA index_info(`$index`)") !== [], 'URL index missing');
check(array_column($db->fetchAll("PRAGMA index_info(`$index`)"), 'name') === ['url'], 'Wrong URL index');
check((string)$db->fetchValue("SELECT clicks FROM `{$prefix}url` WHERE keyword = 'cismoke'") === '2', 'Redirect counter');
check((int)$db->fetchValue("SELECT COUNT(*) FROM `{$prefix}log` WHERE shorturl = 'cismoke'") === 2, 'Redirect log');
check(yourls_get_stats('rand', 1)['stats']['total_links'] >= 1, 'Random statistics');
// Serialized options, bound quotes, Unicode and option insert/update/delete.
$value = ['quote'=>"O'Reilly", 'unicode'=>'中文 🦆'];
check(yourls_update_option('ci_option', $value), 'Insert option');
check(yourls_get_option('ci_option') === $value, 'Option roundtrip');
check(yourls_update_option('ci_option', ['updated'=>true]), 'Update option');
check(yourls_delete_option('ci_option'), 'Delete option');
if (getenv('SMOKE_LEGACY')) {
    check(yourls_get_option('fixture_option') === ['你好','🦆'], 'Legacy serialized option');
    check((string)yourls_get_option('next_id') === '42', 'Legacy next_id');
    check($db->fetchOne("SELECT url,title,timestamp,clicks FROM `{$prefix}url` WHERE keyword='legacy'") === [
        'url'=>'https://example.com/legacy?x=Case','title'=>'旧链接 🦆','timestamp'=>'2024-01-02 03:04:05','clicks'=>7,
    ], 'Legacy link changed');
    check((int)$db->fetchValue("SELECT COUNT(*) FROM `{$prefix}log` WHERE shorturl='legacy'") === 1, 'Legacy log changed');
    check($db->fetchValue("SELECT sql FROM sqlite_master WHERE name='{$prefix}options'") !== false, 'Legacy schema');
}
// Exercise the core last24h query shape with bound IN lists and fractional
// positive/negative offsets, including midnight/AM labels (old code lost 00).
$log = YOURLS_DB_TABLE_LOG;
$db->perform("INSERT INTO `$log` (click_time,shorturl,referrer,user_agent,ip_address,country_code) VALUES (datetime('now'),'ci-hour','direct','ci','127.0.0.1','')");
foreach ([0, 8, -5, 5.5] as $offset) {
    $sql = "SELECT DATE_FORMAT(DATE_ADD(`click_time`, INTERVAL $offset HOUR), '%H %p') AS time, COUNT(*) AS count FROM `$log` WHERE shorturl IN (:list) AND DATE_ADD(`click_time`, INTERVAL $offset HOUR) > (DATE_ADD(CURRENT_TIMESTAMP, INTERVAL $offset HOUR) - INTERVAL 1 DAY) GROUP BY time";
    $rows = $db->fetchPairs(yourls_apply_filter('stat_query_last24h', $sql), ['list'=>['ci-hour', 'nonexistent']]);
    check(array_sum($rows) === 1, 'Last24h aggregate count');
    check(array_key_exists(gmdate('H A', time() + $offset * 3600), $rows), 'Timezone hour label');
}
$sql = "SELECT DATE_FORMAT(`click_time`, '%Y') AS year, COUNT(*) AS count FROM `$log` WHERE shorturl = :keyword GROUP BY year";
check(count($db->fetchAll(yourls_apply_filter('stat_query_dates', $sql), ['keyword'=>'cismoke'])) > 0, 'Date statistics');
$db->perform("DELETE FROM `$log` WHERE shorturl='ci-hour'");
echo "PASS: schema, options, queries, counters, log, random/date/hour stats, legacy data\n";
