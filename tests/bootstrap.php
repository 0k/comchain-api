<?php

// PHPUnit bootstrap for trnslist.php tests
if (PHP_VERSION_ID >= 80000) {
    error_reporting(error_reporting() & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    set_error_handler(function ($errno, $errstr, $errfile, $errline) {
        if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
            return true; // swallow deprecations from legacy test doubles
        }
        return false; // let PHPUnit handle others
    });
}

// Define test constants - lower values for faster tests
if (!defined('TXS_MAX_QUERY_LIMIT')) {
    define('TXS_MAX_QUERY_LIMIT', 5);
}
if (!defined('TXS_RECENT_MAX_BUFFER_TX_COUNT')) {
    define('TXS_RECENT_MAX_BUFFER_TX_COUNT', 10);
}
if (!defined('TXS_CASSANDRA_QUERY_PAGE_SIZE')) {
    define('TXS_CASSANDRA_QUERY_PAGE_SIZE', 2);
}
if (!defined('TXS_PENDING_CUTOFF_AGE')) {
    define('TXS_PENDING_CUTOFF_AGE', 1000);
}
if (!defined('TXS_PENDING_CLOSURE_PAST_LOOKUP_LIMIT')) {
    define('TXS_PENDING_CLOSURE_PAST_LOOKUP_LIMIT', 500);
}

require_once __DIR__ . '/Mocks.php';   // provides Cassandra\\SimpleStatement shim
require_once __DIR__ . '/../trnslist.php';
