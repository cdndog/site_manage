<?php

if (!defined('APP_PATH')) {
    define('APP_PATH', dirname(__DIR__));
}

spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $path = APP_PATH . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

require_once APP_PATH . '/app/Support/functions.php';
