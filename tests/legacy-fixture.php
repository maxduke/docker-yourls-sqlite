<?php
// Synthetic data only. This is the exact old driver's table shape, not a
// production database dump. Preserve nullable option_id and composite PK.
$prefix = getenv('YOURLS_DB_PREFIX') ?: 'yourls_';
$file = '/var/www/html/user/sqlite/yourls.sq3';
$db = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE `{$prefix}options` (option_id bigint(20) NULL, option_name varchar(64) NOT NULL default '', option_value longtext NOT NULL, PRIMARY KEY(option_id,option_name))");
$db->exec("CREATE TABLE `{$prefix}url` (keyword varchar(200) NOT NULL, url text BINARY NOT NULL, title text, timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, ip VARCHAR(41) NOT NULL, clicks INT(11) NOT NULL, PRIMARY KEY(keyword))");
$db->exec("CREATE TABLE `{$prefix}log` (click_id INTEGER PRIMARY KEY AUTOINCREMENT NULL, click_time datetime NOT NULL, shorturl varchar(200) NOT NULL, referrer varchar(200) NOT NULL, user_agent varchar(255) NOT NULL, ip_address varchar(41) NOT NULL, country_code char(2) NOT NULL)");
$stmt = $db->prepare("INSERT INTO `{$prefix}options` (option_name,option_value) VALUES (?,?)");
foreach (['version'=>'1.10.0', 'db_version'=>getenv('LEGACY_VERSION') ?: '506', 'next_id'=>'42', 'active_plugins'=>serialize([]), 'fixture_option'=>serialize(['你好', '🦆'])] as $name=>$value) {
    $stmt->execute([$name,$value]);
}
$stmt = $db->prepare("INSERT INTO `{$prefix}url` VALUES (?,?,?,?,?,?)");
$stmt->execute(['legacy','https://example.com/legacy?x=Case','旧链接 🦆', '2024-01-02 03:04:05','127.0.0.1',7]);
$db->exec("INSERT INTO `{$prefix}log` (click_time,shorturl,referrer,user_agent,ip_address,country_code) VALUES ('2024-01-02 03:04:05','legacy','direct','fixture','127.0.0.1','')");
