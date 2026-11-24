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

// 搜索可能发起多次外网抓取（Google/Bing/百度/360，检测到代理时先走代理再直连兜底），
// 放宽 PHP 执行时限，防止受限网络下 file_get_contents/curl 挂满 timeout 触发 max_execution_time 致命错误。
// 实际耗时由 ImageSearchService 内部的整体预算（TOTAL_BUDGET=24s）约束。
set_time_limit(0);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
    if (!defined('SOPS_TESTING')) { exit; }
    return;
}

$keyword = '';
// 参数必须是字符串：数组形态（?q[]=x）会触发 Array to string conversion 警告，
// 警告文本会混进 JSON 响应体导致调用方解析失败。
if (isset($_GET['q']) && is_string($_GET['q']) && trim($_GET['q']) !== '') {
    $keyword = trim($_GET['q']);
} elseif (isset($_GET['keyword']) && is_string($_GET['keyword']) && trim($_GET['keyword']) !== '') {
    $keyword = trim($_GET['keyword']);
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
if (isset($_GET['validate']) && is_string($_GET['validate']) && in_array(trim($_GET['validate']), ['0', 'false', 'no'], true)) {
    $validate = false;
}

try {
    $result = ImageSearchService::searchWithMeta($keyword, $limit);
    $checked = count($result['images']);
    $images = $result['images'];
    if ($validate) {
        // 校验必须跟随搜索成功的网络路线：若代理失败已回退直连，继续用坏代理探测会把结果全部丢弃
        $validated = ImageSearchService::validate($result['images'], $limit, $result['proxy'] ?? null);
        $checked = $validated['checked'];
        $images = $validated['images'];
    }
    $payload = [
        'ok' => true,
        'keyword' => $keyword,
        'engine' => $result['engine'],
        'via' => $result['via'] ?? 'direct',
        'validated' => $validate,
        'checked' => $checked,
        'count' => count($images),
        'images' => $images,
        'source' => ImageSearchService::buildSourceUrl($result['engine'], $keyword),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        // 关键词/抓取结果含非法 UTF-8 时，不能静默输出空响应体（调用方会解析失败）
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    if ($json === false) {
        http_response_code(500);
        $json = json_encode(['ok' => false, 'error' => 'response encoding failed']);
    }
    echo $json;
} catch (\Throwable $e) {
    error_log('[api/image_search] search failed for ' . $keyword . ': ' . $e->getMessage());
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'image search failed']);
}

if (!defined('SOPS_TESTING')) { exit; }
