<?php
// Loaded first by every page: config, session, database, then all helpers.
require_once __DIR__ . '/config.php';

session_start();
date_default_timezone_set(APP_TIMEZONE);

// URL of the /public folder, e.g. "/acw/public/". Same value from public/ and public/admin/.
$__dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
define('BASE_URL', preg_replace('~/admin$~', '', $__dir) . '/');
unset($__dir);

require_once SRC_PATH . 'database.php';
require_once SRC_PATH . 'cache.php';
require_once SRC_PATH . 'helpers.php';
require_once SRC_PATH . 'auth.php';
require_once SRC_PATH . 'reputation.php';
require_once SRC_PATH . 'order_lib.php';
require_once SRC_PATH . 'chat_lib.php';
