<?php
declare(strict_types=1);

// api/image_search.php - 关键词搜索 Google 图片，返回图片地址列表

require __DIR__ . '/../app/bootstrap.php';

use App\Config;
use App\Services\ImageSearchService;
use App\Support\Security;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-API-Key, Authorization');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    if (!defined('SOPS_TESTING')) { exit; }
    return;
}

if (Config::apiCsrfTokens() !== [] && !Security::apiTokenValid() && !Security::hasValidSession() && !Security::isGitServerIp()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden: missing or invalid API token']);
    if (!defined('SOPS_TESTING')) { exit; }
    return;
}

// 搜索需发起最多两次外网抓取（Google + Bing 兜底），放宽 PHP 执行时限，
// 防止受限网络下 file_get_contents/curl 挂满 timeout 触发 max_execution_time 致命错误。
// 实际耗时由 ImageSearchService 内部的紧凑 curl 超时（TIMEOUT=10s/次）约束。
set_time_limit(0);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
    if (!defined('SOPS_TESTING')) { exit; }
    return;
}

$keyword = '';
if (isset($_GET['q']) && trim((string)$_GET['q']) !== '') {
    $keyword = trim((string)$_GET['q']);
} elseif (isset($_GET['keyword']) && trim((string)$_GET['keyword']) !== '') {
    $keyword = trim((string)$_GET['keyword']);
}
if ($keyword === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'q (or keyword) parameter required']);
    if (!defined('SOPS_TESTING')) { exit; }
    return;
}

$limit = ImageSearchService::MAX_RESULTS;
if (isset($_GET['limit']) && is_numeric($_GET['limit'])) {
    $requested = (int)$_GET['limit'];
    if ($requested > 0) {
        $limit = min($requested, ImageSearchService::MAX_RESULTS);
    }
}

// 可访问性/图片类型/版权校验：默认开启，?validate=0 可跳过（只返回解析到的原始地址）
$validate = true;
if (isset($_GET['validate']) && in_array(trim((string)$_GET['validate']), ['0', 'false', 'no'], true)) {
    $validate = false;
}

try {
    $result = ImageSearchService::searchWithMeta($keyword, $limit);
    $checked = count($result['images']);
    $images = $result['images'];
    if ($validate) {
        $validated = ImageSearchService::validate($result['images'], $limit);
        $checked = $validated['checked'];
        $images = $validated['images'];
    }
    echo json_encode([
        'ok' => true,
        'keyword' => $keyword,
        'engine' => $result['engine'],
        'validated' => $validate,
        'checked' => $checked,
        'count' => count($images),
        'images' => $images,
        'source' => $result['engine'] === 'bing'
            ? ImageSearchService::buildBingUrl($keyword)
            : ImageSearchService::buildUrl($keyword),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[api/image_search] search failed for ' . $keyword . ': ' . $e->getMessage());
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'image search failed']);
}

if (!defined('SOPS_TESTING')) { exit; }
