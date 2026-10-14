<?php

if (!defined('APP_PATH')) {
    define('APP_PATH', dirname(__DIR__));
}

// 存储时区口径固定为 UTC：date('Y-m-d H:i:s') 写入的 time 列与 date('Y-m-d\TH:i:s\Z')
// 写入的 publishAt/createAt 必须同源，否则换服务器（php.ini date.timezone 不同）就会时区错乱。
// 显示端统一由 js/sops-time.js 的 sopsFormatTime() 换算到浏览器所在时区（layout_head 提前加载）。
date_default_timezone_set('UTC');

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
