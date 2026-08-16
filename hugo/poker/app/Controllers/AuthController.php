<?php

namespace App\Controllers;

use App\Config;
use App\Repositories\UserRepository;
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
        $loggedUser = self::dbLogin($user, $password);
        if ($loggedUser === null) {
            $loggedUser = self::envLogin($user, $password);
        }
        if ($loggedUser === null) {
            render('layout_head', ['page_title' => '登录', 'no_shell' => true]);
            render('login', ['error' => 'invalid credentials']);
            render('layout_tail', ['no_shell' => true]);
            return;
        }
        Security::issueAuthCookie($loggedUser);
        header('Location: ' . e(isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '/siteops.php'));
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