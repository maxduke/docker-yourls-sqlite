<?php
/*
SQLite driver for YOURLS.
Locally maintained for YOURLS 1.10.6 / PHP 8.5.
Original author: Ozh; subsequent contributors: reanimus, Flameborn, Niduroki.
Based on Flameborn/yourls-sqlite at 67835bed79236700f035b0cd93120c70f9118ed6.
See NOTICE.md, UPSTREAM-README.md and LICENSE-YOURLS.txt for permissions and attribution.
*/

class CustomYDB extends \YOURLS\Database\YDB {
  public function connect_to_DB() {
    // Aura SQL 5 uses lazyConnect(); connect() is a static factory.
    try {
      $this->lazyConnect();
    } catch (\Exception $e) {
      $this->dead_or_error($e);
    }
  }

  public function escape($string) {
    // Copied escape function from base YDB
    // https://github.com/YOURLS/YOURLS/blob/23d21fd996e771c4015f5d8b4ec1b06af122d60b/includes/Database/YDB.php#L429
    return substr($this->quote($string), 1, -1);
  }
}

yourls_db_sqlite_connect();

/**
 * Connect to SQLite DB
 */
function yourls_db_sqlite_connect() {
    global $ydb;

	if (!defined( 'YOURLS_DB_NAME' ))
        yourls_die ( yourls__( 'Incorrect DB config (set YOURLS_DB_NAME)' ), yourls__( 'Fatal error' ), 503 );

    $dbname = YOURLS_USERDIR . '/' . YOURLS_DB_NAME . '.sq3';

    /**
     * Data Source Name (dsn) used to connect the DB
     *
     * DSN with PDO is something like:
     * 'mysql:host=123.4.5.6;dbname=test_db;port=3306'
     * 'sqlite:/opt/databases/mydb.sq3'
     * 'pgsql:host=192.168.13.37;port=5432;dbname=omgwtf'
     */
    $dsn = sprintf( 'sqlite:%s', $dbname );

    /**
     * PDO driver options and attributes

     * The PDO constructor is something like:
     *   new PDO( string $dsn, string $username, string $password [, array $options ] )
     * The driver options are passed to the PDO constructor, eg array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
     * The attribute options are then set in a foreach($attr as $k=>$v){$db->setAttribute($k, $v)} loop
     */
    $attributes     = yourls_apply_filter( 'db_connect_attributes',    array() ); // attributes as key-value pairs

    $options = yourls_apply_filter('db_connect_driver_option', [PDO::ATTR_TIMEOUT => 5]);
    $ydb = new CustomYDB($dsn, '', '', $options);
    $ydb->init();
    foreach ($attributes as $attribute => $value) {
        $ydb->setAttribute($attribute, $value);
    }
    // Supported core random stats query, without rewriting arbitrary SQL text.
    $ydb->sqliteCreateFunction('RAND', static fn() => mt_rand() / mt_getrandmax(), 0);

    // Past this point, we're connected
    yourls_debug_log(sprintf('Opened database %s ', $dbname));

    // Custom tables to be created upon install
    yourls_add_filter( 'shunt_yourls_create_sql_tables', 'yourls_create_sqlite_tables' );

    // Custom stat query to replace MySQL DATE_FORMAT with SQLite strftime
    yourls_add_filter( 'stat_query_dates', 'yourls_sqlite_stat_query_dates' );
    
    // Preserve core's bound keyword/list parameters and timezone-aware hour labels.
    yourls_add_filter('stat_query_last24h', 'yourls_sqlite_stat_query_last24h');

    // YOURLS 1.10.6 has a MySQL-only version gate and no check-result hook.
    // 5.5 is a compatibility token, NOT the SQLite server version. SQLite is
    // validated by PDO connection and the real fresh-install integration test.
    yourls_add_filter('shunt_get_database_version', static fn() => '5.5');

    // Shunt get_all_options to prevent error from SHOW TABLES query
    yourls_add_filter( 'shunt_all_options', 'yourls_sqlite_get_all_options' );
    
    yourls_debug_mode(YOURLS_DEBUG);

	return $ydb;
}

function yourls_sqlite_get_all_options() {
    global $ydb;
    $table = YOURLS_DB_TABLE_OPTIONS;
    // rowCount()/fetchAffected() is not reliable for SQLite SELECT statements.
    $exists = $ydb->fetchValue(
        "SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name",
        ['name' => $table]
    );
    if ($exists === false) {
        $ydb->set_installed(false);
        return true; // handled: never fall back to MySQL SHOW TABLES
    }

    // Query errors on an existing table must surface, not masquerade as uninstalled.
    $options = $ydb->fetchPairs("SELECT option_name, option_value FROM `$table`");
    $ydb->set_installed(!empty($options));
    foreach ($options as $name => $value) {
        $ydb->set_option($name, yourls_maybe_unserialize($value));
    }
    yourls_do_action('get_all_options', $options);
    return true;
}

/** Translate only the core last-24-hour query, preserving table and binds. */
function yourls_sqlite_stat_query_last24h($query) {
    $query = preg_replace_callback(
        '/DATE_ADD\((`click_time`|CURRENT_TIMESTAMP), INTERVAL ([+-]?[0-9.]+) HOUR\)/',
        static fn($m) => "datetime({$m[1]}, '{$m[2]} hours')",
        $query
    );
    $query = preg_replace_callback(
        "/DATE_FORMAT\((datetime\(`click_time`, '[^']+ hours'\)), '%H %p'\)/",
        static fn($m) => "strftime('%H', {$m[1]}) || CASE WHEN strftime('%H', {$m[1]}) < '12' THEN ' AM' ELSE ' PM' END",
        $query
    );
    return preg_replace(
        "/\(datetime\(CURRENT_TIMESTAMP, '([^']+) hours'\) - INTERVAL 1 DAY\)/",
        "datetime(CURRENT_TIMESTAMP, '$1 hours', '-1 day')",
        $query
    );
}

/**
 * Format SQL queries for SQLite
 *
 * Replace all
 *    DATE_FORMAT(`field`, '%FORMAT')        with    strftime('%FORMAT', `field`)
 *    (CURRENT_TIMESTAMP - INTERVAL 1 DAY)   with    datetime('now', '-1 day')
 *    Date format string that SQLite doesn't understand (eg "%p")
 *
 * @param string $query Query
 * @return string Modified query
 */
function yourls_sqlite_stat_query_dates( $query ) {
    // from: DATE_FORMAT(`field`, '%FORMAT')
    // to:   strftime('%FORMAT', `field`)
    preg_match_all( '/DATE_FORMAT\(\s*([^,]+),\s*([^\)]+)\)/', $query, $matches );
    // $matches[0] is an array of all the "DATE_FORMAT(`THING`, '%STUFF')"
    // $matches[1] is an array of all the `THING`
    // $matches[2] is an array of all the '%STUFF'
    $replace = array();
    foreach( $matches[1] as $k => $v ) {
        $replace[] = sprintf( 'strftime(%s, %s)', $matches[2][$k], $matches[1][$k] );
    }
    $query = str_replace( $matches[0], $replace, $query );
    
    // from: `field` > (CURRENT_TIMESTAMP - INTERVAL 1 DAY)
    // to:   `field` > datetime('now', '-1 day');
    $query = str_replace( "(CURRENT_TIMESTAMP - INTERVAL 1 DAY)", "datetime('now', '-1 day')", $query );    
    
    // Remove %p from date formats, as SQLite doesn't get it
    $query = preg_replace( '/\s*%p\s*/', '', $query );    
    
    return $query;
}

/**
 * Create tables. Return array( 'success' => array of success strings, 'errors' => array of error strings )
 *
 */
function yourls_create_sqlite_tables() {
    global $ydb;
    
    $error_msg = array();
    $success_msg = array();

    // Create Table Query
    $create_tables = array();
    
        
    $create_tables[YOURLS_DB_TABLE_OPTIONS] = 
        'CREATE TABLE IF NOT EXISTS `'.YOURLS_DB_TABLE_OPTIONS.'` ('.
        '`option_id` INTEGER PRIMARY KEY AUTOINCREMENT,'.
        '`option_name` varchar(64) NOT NULL default "",'.
        '`option_value` longtext NOT NULL,'.
        'UNIQUE (`option_name`)'.
        ');';

    $create_tables[YOURLS_DB_TABLE_URL] =
        'CREATE TABLE IF NOT EXISTS `'.YOURLS_DB_TABLE_URL.'` ('.
        '`keyword` varchar(200) NOT NULL,'.
        '`url` text COLLATE BINARY NOT NULL,'.
        '`title` text,'.
        '`timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'.
        '`ip` VARCHAR(41) NOT NULL,'.
        '`clicks` INT(11) NOT NULL,'.
        ' PRIMARY KEY  (`keyword`)'.
        ');';

    $create_tables[YOURLS_DB_TABLE_LOG] = 
        'CREATE TABLE IF NOT EXISTS `'.YOURLS_DB_TABLE_LOG.'` ('.
        '`click_id`  INTEGER PRIMARY KEY AUTOINCREMENT NULL,'.
        '`click_time` datetime NOT NULL,'.
        '`shorturl` varchar(200) NOT NULL,'.
        '`referrer` varchar(200) NOT NULL,'.
        '`user_agent` varchar(255) NOT NULL,'.
        '`ip_address` varchar(41) NOT NULL,'.
        '`country_code` char(2) NOT NULL'.
        '); ';

    $create_table_count = 0;
    
    // Create tables
    foreach ( $create_tables as $table_name => $table_query ) {
        $ydb->perform( $table_query );
    }    

    // SQLite index names are database-wide; keep the historical default url_idx,
    // and qualify it for other prefixes to avoid collisions in shared files.
    $url_index = YOURLS_DB_TABLE_URL === 'yourls_url' ? 'url_idx' : YOURLS_DB_TABLE_URL . '_url_idx';
    foreach ([
        [$url_index, YOURLS_DB_TABLE_URL, 'url'],
        [YOURLS_DB_TABLE_URL . '_ip_idx', YOURLS_DB_TABLE_URL, 'ip'],
        [YOURLS_DB_TABLE_URL . '_timestamp_idx', YOURLS_DB_TABLE_URL, 'timestamp'],
        [YOURLS_DB_TABLE_LOG . '_shorturl_idx', YOURLS_DB_TABLE_LOG, 'shorturl'],
    ] as [$index, $table, $column]) {
        $ydb->perform("CREATE INDEX IF NOT EXISTS `$index` ON `$table` (`$column`)");
    }

    // Get list of created tables
    $create_success = $ydb->fetchObjects( 'SELECT name FROM sqlite_master WHERE type = "table" AND name NOT LIKE "sqlite_%"' );
    
    $created_tables = [];
    $i = 0;
    foreach( $create_success as $table ) {
        $created_tables[] = $table->name;
    }
    
    // Compare list of created tables with needed tables
    foreach( $create_tables as $table_name => $table_query  ) {
        if( in_array( $table_name, $created_tables ) ) {
            $create_table_count++;
            $success_msg[] = yourls_s( "Table '%s' created.", $table_name ); 
        } else {
            $error_msg[] = yourls_s( "Error creating table '%s'.", $table_name );
        }
    }
    
    // Initializes the option table
    if( !yourls_initialize_options() )
        $error_msg[] = yourls__( 'Could not initialize options' );
    
    // Insert sample links
    if( !yourls_insert_sample_links() )
        $error_msg[] = yourls__( 'Could not insert sample short URLs' );
    
    // Check results of operations
    if ( sizeof( $create_tables ) == $create_table_count ) {
        $success_msg[] = yourls__( 'YOURLS tables successfully created.' );
    } else {
        $error_msg[] = yourls__( 'Error creating YOURLS tables.' ); 
    }

    return array( 'success' => $success_msg, 'error' => $error_msg );
}

/**
 * @return \YOURLS\Database\YDB
 */
function yourls_get_db($context = '') {
    $pre = yourls_apply_filter('shunt_get_db', yourls_shunt_default(), $context);
    if (yourls_shunt_default() !== $pre) {
        return $pre;
    }
    yourls_do_action('get_db_action', $context);
    global $ydb;
    $ydb = $ydb ?? yourls_db_sqlite_connect();
    return yourls_apply_filter('get_db', $ydb, $context);
}
