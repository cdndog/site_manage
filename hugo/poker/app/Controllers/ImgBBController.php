<?php

namespace App\Controllers;

use App\Services\ImgBBService;
use App\Support\Security;

class ImgBBController
{
    public static function dispatch()
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        if ($method !== 'POST') {
            self::respond(405, ['success' => false, 'error' => ['message' => 'method not allowed']]);
            return;
        }
        if (!Security::csrfVerify(isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '')) {
            self::respond(403, ['success' => false, 'error' => ['message' => 'csrf token invalid']]);
            return;
        }
        if (empty($_FILES) && (int)(isset($_SERVER['CONTENT_LENGTH']) ? $_SERVER['CONTENT_LENGTH'] : 0) > 0) {
            self::respond(400, ['success' => false, 'error' => ['message' => 'no file received, server post_max_size may be exceeded (post_max_size=' . ini_get('post_max_size') . ')']]);
            return;
        }
        try {
            $result = ImgBBService::upload(isset($_FILES['image']) ? $_FILES['image'] : []);
        } catch (\Throwable $e) {
            error_log('[imgbb_proxy] upload exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            self::respond(500, ['success' => false, 'error' => ['message' => 'server error: ' . $e->getMessage()]]);
            return;
        }
        self::respond($result['success'] === true ? 200 : 400, $result);
    }

    private static function respond($status, array $payload)
    {
        http_response_code($status);
        echo json_encode($payload);
    }
}