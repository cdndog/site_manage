<?php

namespace App\Support;

use App\Config;

class Security
{
    const UID_COOKIE = 'siteops_uid';
    const AUTH_COOKIE = 'siteops_auth';
    const AUTH_TTL = 43200;

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
        return Config::authUser() !== null || Config::authPassword() !== null;
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
}