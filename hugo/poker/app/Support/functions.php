<?php

if (!function_exists('render')) {
    function render($view, array $data = [])
    {
        extract($data, EXTR_SKIP);
        require APP_PATH . '/views/' . $view . '.php';
    }
}

if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('renderErrorPage')) {
    function renderErrorPage(\Throwable $e)
    {
        if ($e instanceof \App\Support\PermissionDenied) {
            http_response_code(403);
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=utf-8');
            }
            render('error', ['message' => $e->getMessage()]);
            return;
        }
        http_response_code(500);
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Error</title></head>'
            . '<body><h1>Internal Server Error</h1><p>' . e($e->getMessage()) . '</p></body></html>';
    }
}

if (!function_exists('uuid')) {
    function uuid()
    {
        return str_replace('.', '', uniqid(time(), true));
    }
}
