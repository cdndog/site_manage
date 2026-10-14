<?php

namespace App\Controllers;

use App\Config;
use App\Repositories\UserRepository;
use App\Support\Security;

class AuthController
{
    public static function handle($notice = '')
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        if ($method === 'POST' && isset($_POST['login_action']) && $_POST['login_action'] === 'login') {
            self::doLogin($notice);
        } else {
            self::showLogin($notice);
        }
    }

    private static function showLogin($notice = '', $error = '')
    {
        render('layout_head', ['page_title' => '登录', 'no_shell' => true]);
        render('login', ['notice' => $notice, 'error' => $error]);
        render('layout_tail', ['no_shell' => true]);
    }

    private static function returnUrl()
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        if ($uri !== '' && strpos($uri, "\r") === false && strpos($uri, "\n") === false) {
            return $uri;
        }
        return isset($_SERVER['PHP_SELF']) ? (string)$_SERVER['PHP_SELF'] : '/siteops.php';
    }

    public static function handleLogout()
    {
        if (!Security::csrfVerify(Security::requestToken())) {
            http_response_code(403);
            render('error', ['message' => 'CSRF token invalid']);
            return;
        }
        Security::clearAuthCookie();
        header('Location: ' . self::logoutReturnUrl());
    }

    private static function logoutReturnUrl()
    {
        // 仅接受站内相对路径，避免开放重定向
        $referer = isset($_SERVER['HTTP_REFERER']) ? (string)$_SERVER['HTTP_REFERER'] : '';
        if ($referer !== '') {
            $path = (string)parse_url($referer, PHP_URL_PATH);
            if ($path !== '' && strpos($path, "\r") === false && strpos($path, "\n") === false
                && strpos($path, '//') !== 0 && $path[0] === '/') {
                $query = (string)parse_url($referer, PHP_URL_QUERY);
                return $query !== '' ? $path . '?' . $query : $path;
            }
        }
        return '/siteops.php';
    }

    private static function doLogin($notice = '')
    {
        $user = isset($_POST['auth_user']) ? (string)$_POST['auth_user'] : '';
        $password = isset($_POST['auth_password']) ? (string)$_POST['auth_password'] : '';
        $loggedUser = self::dbLogin($user, $password);
        if ($loggedUser === null) {
            $loggedUser = self::envLogin($user, $password);
        }
        if ($loggedUser === null) {
            self::showLogin($notice, 'invalid credentials');
            return;
        }
        Security::issueAuthCookie($loggedUser);
        // 登录成功后回跳到用户原本访问的页面（含查询串）
        header('Location: ' . self::returnUrl());
    }

    private static function dbLogin($username, $password)
    {
        if (UserRepository::count() === 0) {
            return null;
        }
        $record = UserRepository::findByUsername($username);
        if ($record === null || (string)$record['status'] !== 'active') {
            return null;
        }
        if (!password_verify((string)$password, (string)$record['password_hash'])) {
            return null;
        }
        UserRepository::touchLastLogin((int)$record['id']);
        return (string)$record['username'];
    }

    private static function envLogin($user, $password)
    {
        $expectedUser = Config::authUser();
        $valid = ($expectedUser === null || $expectedUser === $user) && Security::verifyPassword($password);
        return $valid ? ($user !== '' ? $user : 'admin') : null;
    }
}