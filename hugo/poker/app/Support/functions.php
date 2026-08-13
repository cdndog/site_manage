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

if (!function_exists('uuid')) {
    function uuid()
    {
        return str_replace('.', '', uniqid(time(), true));
    }
}
