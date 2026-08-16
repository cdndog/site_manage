<?php

namespace App\Support;

use App\Config;

class Security
{
    const UID_COOKIE = 'siteops_uid';
    const AUTH_COOKIE = 'siteops_auth';
    const AUTH_TTL = 43200;

    private static $currentUser = false;
    private static $currentPermissions = false;
    private static $dbUsers = null;

    public static function reset()
    {
        self::$currentUser = false;
        self::$currentPermissions = false;
        self::$dbUsers = null;
    }

    public static function csrfUid()
    {
        return isset($_COOKIE[self::UID_COOKIE]) ? (string)$_COOKIE[self::UID_COOKIE] : '';
    }

    public static function csrfToken()
    {
        $uid = self::csrfUid();
        return substr(hash_hmac('sha256', 'csrf|' . $uid, Config::csrfSecret()), 0, 32);
    }

    public static function csrfVerify($token)
    {
        if (is_string($token) && $token !== '') {
            foreach (Config::apiCsrfTokens() as $apiToken) {
                if (hash_equals($apiToken, $token)) {
                    return true;
                }
            }
        }
        $uid = self::csrfUid();
        if ($uid === '') {
            return true;
        }
        return is_string($token) && $token !== '' && hash_equals(self::csrfToken(), $token);
    }

    public static function ensureUidCookie()
    {
        if (self::csrfUid() === '' && !headers_sent()) {
            setcookie(self::UID_COOKIE, self::randomToken(), 0, '/', '', false, true);
        }
    }

    public static function authEnabled()
    {
        if (Config::authUser() !== null || Config::authPassword() !== null) {
            return true;
        }
        if (!defined('APP_PATH')) {
            return false;
        }
        if (self::$dbUsers === null) {
            try {
                self::$dbUsers = \App\Repositories\UserRepository::count() > 0;
            } catch (\Throwable $e) {
                self::$dbUsers = false;
            }
        }
        return self::$dbUsers;
    }

    public static function authValid()
    {
        if (!self::authEnabled()) {
            return true;
        }
        if (self::csrfUid() === '') {
            return true;
        }
        $cookie = isset($_COOKIE[self::AUTH_COOKIE]) ? (string)$_COOKIE[self::AUTH_COOKIE] : '';
        return $cookie !== '' && self::verifyAuthCookie($cookie);
    }

    public static function buildAuthCookie($user, $expiry)
    {
        $mac = substr(hash_hmac('sha256', 'auth|' . $user . '|' . $expiry, Config::authSecret()), 0, 32);
        return $user . '|' . $expiry . '|' . $mac;
    }

    public static function issueAuthCookie($user)
    {
        $expiry = time() + self::AUTH_TTL;
        $cookie = self::buildAuthCookie($user, $expiry);
        setcookie(self::AUTH_COOKIE, $cookie, $expiry, '/', '', false, true);
    }

    public static function verifyAuthCookie($cookie)
    {
        $parts = explode('|', (string)$cookie);
        if (count($parts) !== 3) {
            return false;
        }
        list($user, $expiry, $mac) = $parts;
        if (!ctype_digit($expiry) || (int)$expiry < time()) {
            return false;
        }
        $expected = substr(hash_hmac('sha256', 'auth|' . $user . '|' . $expiry, Config::authSecret()), 0, 32);
        return hash_equals($expected, $mac);
    }

    public static function verifyPassword($password)
    {
        $stored = Config::authPassword();
        if ($stored === null || $password === null || $password === '') {
            return false;
        }
        $stored = (string)$stored;
        if (strncmp($stored, '$2y$', 4) === 0 || strncmp($stored, '$2a$', 4) === 0 || strncmp($stored, '$argon2', 7) === 0) {
            return password_verify((string)$password, $stored);
        }
        return hash_equals($stored, (string)$password);
    }

    public static function randomToken($bytes = 16)
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function authUsername()
    {
        if (!self::authValid()) {
            return '';
        }
        $cookie = isset($_COOKIE[self::AUTH_COOKIE]) ? (string)$_COOKIE[self::AUTH_COOKIE] : '';
        if ($cookie === '') {
            return '';
        }
        $parts = explode('|', $cookie);
        return isset($parts[0]) ? $parts[0] : '';
    }

    public static function currentUser()
    {
        if (self::$currentUser !== false) {
            return self::$currentUser;
        }
        $username = self::authUsername();
        if ($username === '') {
            self::$currentUser = null;
            return null;
        }
        $user = \App\Repositories\UserRepository::findByUsername($username);
        self::$currentUser = $user !== null ? $user : null;
        return self::$currentUser;
    }

    public static function permissions()
    {
        if (self::$currentPermissions !== false) {
            return self::$currentPermissions;
        }
        if (!self::authEnabled()) {
            self::$currentPermissions = array_keys(\App\Config::permissions());
            return self::$currentPermissions;
        }
        $user = self::currentUser();
        if ($user === null) {
            self::$currentPermissions = self::legacyAdminPermissions();
            return self::$currentPermissions;
        }
        if ((string)$user['status'] !== 'active') {
            self::$currentPermissions = [];
            return [];
        }
        self::$currentPermissions = \App\Repositories\UserRepository::permissionsOf((int)$user['id']);
        return self::$currentPermissions;
    }

    private static function legacyAdminPermissions()
    {
        if (!self::authEnabled() || self::authUsername() === '') {
            return [];
        }
        return array_keys(\App\Config::permissions());
    }

    public static function can($permission)
    {
        if (!self::authEnabled()) {
            return true;
        }
        $user = self::currentUser();
        if ($user === null) {
            return self::authUsername() !== '';
        }
        if ((string)$user['status'] !== 'active') {
            return false;
        }
        return in_array($permission, self::permissions(), true);
    }

    public static function requirePermission($permission)
    {
        if (self::isGitServerIp() && !self::isSystemPermission($permission)) {
            return true;
        }
        if (self::csrfUid() === '') {
            return true;
        }
        if (self::can($permission)) {
            return true;
        }
        throw new PermissionDenied('forbidden: insufficient permission');
    }

    public static function isSystemPermission($permission)
    {
        return in_array($permission, ['user.manage', 'config.manage'], true);
    }

    public static function isGitServerIp()
    {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? trim((string)$_SERVER['REMOTE_ADDR']) : '';
        if ($remote === '') {
            return false;
        }
        $remote = self::normalizeIp($remote);
        foreach (Config::gitServerIps() as $ip) {
            if (self::normalizeIp($ip) === $remote) {
                return true;
            }
        }
        return false;
    }

    private static function normalizeIp($ip)
    {
        if (stripos($ip, '::ffff:') === 0) {
            $ip = substr($ip, 7);
        }
        return strtolower(trim($ip));
    }

    public static function requestToken()
    {
        if (isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) && $_POST['csrf_token'] !== '') {
            return $_POST['csrf_token'];
        }
        if (isset($_GET['csrf_token']) && is_string($_GET['csrf_token']) && $_GET['csrf_token'] !== '') {
            return $_GET['csrf_token'];
        }
        if (isset($_SERVER['HTTP_X_CSRF_TOKEN']) && is_string($_SERVER['HTTP_X_CSRF_TOKEN']) && $_SERVER['HTTP_X_CSRF_TOKEN'] !== '') {
            return $_SERVER['HTTP_X_CSRF_TOKEN'];
        }
        return '';
    }

    public static function apiTokenValid()
    {
        $token = self::requestToken();
        if ($token === '') {
            return false;
        }
        foreach (Config::apiCsrfTokens() as $apiToken) {
            if (hash_equals($apiToken, $token)) {
                return true;
            }
        }
        return false;
    }

    public static function requireApiToken($renderLogin = false)
    {
        if (Config::apiCsrfTokens() === []) {
            return;
        }
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            return;
        }
        if (isset($_POST['login_action'])) {
            return;
        }
        if (self::isGitServerIp()) {
            return;
        }
        if (self::hasValidSession()) {
            return;
        }
        if (self::apiTokenValid()) {
            return;
        }
        if ($renderLogin && self::authEnabled()) {
            \App\Controllers\AuthController::handle();
            if (!defined('SOPS_TESTING')) {
                exit;
            }
            return;
        }
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'forbidden: missing or invalid API token';
        if (!defined('SOPS_TESTING')) {
            exit;
        }
    }

    public static function hasValidSession()
    {
        if (self::csrfUid() === '') {
            return false;
        }
        return self::authValid();
    }
}