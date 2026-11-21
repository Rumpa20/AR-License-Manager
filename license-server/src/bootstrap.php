<?php
/**
 * AR License Manager - Application bootstrap.
 * Loads configuration, registers the class autoloader, sets safe runtime
 * defaults. Used by every entry point (API, admin, installer, webhooks).
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (!defined('ARLM_ROOT')) {
    define('ARLM_ROOT', dirname(__DIR__));
}

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'ARLM\\')) {
        $file = ARLM_ROOT . '/src/' . str_replace('\\\\', '/', substr($class, 5)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

\\ARLM\\Config::load(ARLM_ROOT . '/config/config.php');

if (!\\ARLM\\Config::get('installed')) {
    header('Location: install/');
    exit;
}

\\ARLM\\Settings::boot();
