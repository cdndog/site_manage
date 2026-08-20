<?php

namespace App\Controllers;

use App\Config;
use App\Support\Cache;
use App\Support\Security;

class CacheController
{
    public static function dispatch()
    {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        try {
            Security::requireApiToken(true);
            Security::ensureUidCookie();
            if (!Security::authValid() && !Security::isGitServerIp()) {
                AuthController::handle();
                return;
            }
            Security::requirePermission('config.manage');
            $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
            if ($method === 'POST' && isset($_POST['action'])) {
                $action = (string)$_POST['action'];
                if ($action === 'flush') {
                    self::handleFlush();
                    return;
                }
                if ($action === 'save_config') {
                    self::handleSaveConfig();
                    return;
                }
                if ($action === 'test_connection') {
                    self::handleTestConnection();
                    return;
                }
            }
            self::shell();
        } catch (\Throwable $e) {
            renderErrorPage($e);
        }
    }

    private static function handleFlush()
    {
        if (!Security::csrfVerify(Security::requestToken())) {
            self::shell('CSRF token 无效，请刷新页面重试', true);
            return;
        }
        $result = Cache::flushAll();
        $msg = '缓存已清空';
        if ($result['redis_cleared']) {
            $msg .= '（Redis 已 flushdb';
            if ($result['file_cleared'] > 0) {
                $msg .= '，文件缓存 ' . $result['file_cleared'] . ' 个';
            }
            $msg .= '）';
        } else {
            $msg .= '（文件缓存 ' . $result['file_cleared'] . ' 个）';
        }
        self::shell($msg);
    }

    private static function handleSaveConfig()
    {
        if (!Security::csrfVerify(Security::requestToken())) {
            self::shell('CSRF token 无效，请刷新页面重试', true);
            return;
        }
        $host = isset($_POST['redis_host']) ? trim((string)$_POST['redis_host']) : '';
        $port = isset($_POST['redis_port']) ? trim((string)$_POST['redis_port']) : '6379';
        $auth = isset($_POST['redis_auth']) ? (string)$_POST['redis_auth'] : '';
        $db = isset($_POST['redis_db']) ? trim((string)$_POST['redis_db']) : '0';
        $timeout = isset($_POST['redis_timeout']) ? trim((string)$_POST['redis_timeout']) : '0.5';
        if ($host !== '' && (!preg_match('/^\d+$/', $port) || (int)$port < 1 || (int)$port > 65535)) {
            self::shell('端口必须为 1-65535 的整数', true);
            return;
        }
        if ($host !== '' && (!preg_match('/^\d+$/', $db) || (int)$db < 0 || (int)$db > 15)) {
            self::shell('Redis DB 必须为 0-15 的整数', true);
            return;
        }
        if ($host !== '' && (!preg_match('/^\d*\.?\d+$/', $timeout) || (float)$timeout <= 0 || (float)$timeout > 10)) {
            self::shell('超时必须为 0-10 之间的数值', true);
            return;
        }
        if (!Cache::saveConfig($host, $port, $auth, $db, $timeout)) {
            self::shell('写入 cache.config.php 失败，请检查目录权限', true);
            return;
        }
        Cache::reset();
        if ($host === '') {
            self::shell('Redis 配置已清除（回退到文件缓存）');
        } else {
            self::shell('Redis 配置已保存。如需立即生效请执行"清空缓存"刷新连接');
        }
    }

    private static function handleTestConnection()
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!Security::csrfVerify(Security::requestToken())) {
            echo json_encode(['ok' => false, 'error' => 'CSRF token 无效']);
            return;
        }
        $host = isset($_POST['redis_host']) ? trim((string)$_POST['redis_host']) : '';
        $port = isset($_POST['redis_port']) ? trim((string)$_POST['redis_port']) : '6379';
        $auth = isset($_POST['redis_auth']) ? (string)$_POST['redis_auth'] : '';
        $db = isset($_POST['redis_db']) ? trim((string)$_POST['redis_db']) : '0';
        $timeout = isset($_POST['redis_timeout']) ? trim((string)$_POST['redis_timeout']) : '0.5';
        if ($host === '') {
            echo json_encode(['ok' => false, 'error' => '请填写 Redis Host']);
            return;
        }
        $result = Cache::testConnection($host, $port, $auth, $db, $timeout);
        echo json_encode($result);
    }

    private static function shell($message = '', $error = false)
    {
        $status = Cache::status();
        $data = [
            'status' => $status,
            'csrf_token' => Security::csrfToken(),
            'message' => $message,
            'error' => $error,
            'can_manage' => Security::can('config.manage'),
        ];
        render('layout_head', ['page_title' => '缓存管理']);
        render('header');
        render('cache_manage', $data);
        render('footer');
        render('layout_tail');
    }
}
