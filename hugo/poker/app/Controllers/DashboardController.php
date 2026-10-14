<?php

namespace App\Controllers;

use App\Services\DashboardService;
use App\Support\Security;

class DashboardController
{
    public static function dispatch()
    {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        try {
            Security::requireApiToken(true);
            Security::ensureUidCookie();
            if (!Security::requireSession()) {
                return;
            }
            Security::requirePermission('report.view');
            if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
                && isset($_POST['action']) && $_POST['action'] === 'stats') {
                self::handleStats();
                return;
            }
            render('layout_head', ['page_title' => '数据看板']);
            render('header');
            render('dashboard', [
                'stats' => DashboardService::stats(),
                'csrf_token' => Security::csrfToken(),
            ]);
            render('footer');
            render('layout_tail');
        } catch (\Throwable $e) {
            renderErrorPage($e);
        }
    }

    private static function handleStats()
    {
        if (!Security::csrfVerify(Security::requestToken())) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'CSRF token invalid']);
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['ok' => true, 'stats' => DashboardService::stats()],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}
