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
require_once __DIR__ . '/Mocks.php';   // provides Cassandra\\SimpleStatement shim
require_once __DIR__ . '/../trnslist.php';
