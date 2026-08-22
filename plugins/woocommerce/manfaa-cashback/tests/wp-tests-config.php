<?php
/** Test configuration for wp-phpunit. The test DB is wiped on every run. */

define('ABSPATH', rtrim(getenv('MANFAA_WP_DIR') ?: dirname(__DIR__, 2).'/dev-site', '/').'/');
define('WP_DEBUG', true);

define('DB_NAME', getenv('MANFAA_DB_NAME') ?: 'manfaa_wc_test');
define('DB_USER', getenv('MANFAA_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('MANFAA_DB_PASS') ?: '');
define('DB_HOST', getenv('MANFAA_DB_HOST') ?: 'localhost:'.dirname(__DIR__, 2).'/.tools/mariadb/run/mysql.sock');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = 'wptests_';

define('WP_TESTS_DOMAIN', 'shop.example.mv');
define('WP_TESTS_EMAIL', 'admin@shop.example.mv');
define('WP_TESTS_TITLE', 'Test Shop');
define('WP_PHP_BINARY', 'php');
define('WPLANG', '');

define('AUTH_KEY', str_repeat('a', 64));
define('SECURE_AUTH_KEY', str_repeat('b', 64));
define('LOGGED_IN_KEY', str_repeat('c', 64));
define('NONCE_KEY', str_repeat('d', 64));
define('AUTH_SALT', str_repeat('e', 64));
define('SECURE_AUTH_SALT', str_repeat('f', 64));
define('LOGGED_IN_SALT', str_repeat('g', 64));
define('NONCE_SALT', str_repeat('h', 64));
