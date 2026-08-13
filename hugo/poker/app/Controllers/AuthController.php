<?php

namespace App\Controllers;

use App\Config;
use App\Support\Security;

class AuthController
{
    public static function handle()
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        if ($method === 'POST' && isset($_POST['login_action']) && $_POST['login_action'] === 'login') {
            self::doLogin();
        } else {
            self::showLogin();
        }
    }

    private static function showLogin()
    {
        render('layout_head', ['page_title' => '登录', 'no_shell' => true]);
        render('login');
        render('layout_tail', ['no_shell' => true]);
    }

    private static function doLogin()
    {
        $user = isset($_POST['auth_user']) ? (string)$_POST['auth_user'] : '';
        $password = isset($_POST['auth_password']) ? (string)$_POST['auth_password'] : '';
        $expectedUser = Config::authUser();
        $valid = ($expectedUser === null || $expectedUser === $user) && Security::verifyPassword($password);
        if (!$valid) {
            render('layout_head', ['page_title' => '登录', 'no_shell' => true]);
            render('login', ['error' => 'invalid credentials']);
            render('layout_tail', ['no_shell' => true]);
            return;
        }
        Security::issueAuthCookie($user !== '' ? $user : 'admin');
        header('Location: ' . e(isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '/siteops.php'));
    }
}