<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Config;
use App\Services\TopicService;
use App\Support\Cache;
use App\Support\Logger;
use App\Support\Security;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-API-Key, Authorization');
header('Access-Control-Max-Age: 86400');
header('Content-Type: text/plain; charset=utf-8');

set_time_limit(0);
ini_set('max_execution_time', '300');
ini_set('max_input_time', '-1');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    if (!defined('SOPS_TESTING')) { exit; }
    return;
}

// 鉴权优先于参数校验：与原 topictask/sitequery 的 requireApiToken 语义一致
// 未配置 api_csrf_tokens 时放行（保持原 requireApiToken 的空配置放行行为）
if (Config::apiCsrfTokens() !== [] && !Security::apiTokenValid() && !Security::hasValidSession() && !Security::isGitServerIp()) {
    http_response_code(403);
    echo 'forbidden: missing or invalid API token';
    if (!defined('SOPS_TESTING')) { exit; }
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'FAIL: method not allowed';
    if (!defined('SOPS_TESTING')) { exit; }
    return;
}

$post = TopicService::sanitizePost($_POST);

$keyword = isset($post['post_keyword']) ? trim((string)$post['post_keyword']) : '';
$gitName = isset($post['post_gitname']) ? trim((string)$post['post_gitname']) : '';

if ($keyword === '' || $gitName === '') {
    http_response_code(400);
    echo 'FAIL: post_keyword and post_gitname are required';
    if (!defined('SOPS_TESTING')) { exit; }
    return;
}

$records = TopicService::buildRecords($post);
if (count($records) === 0) {
    http_response_code(400);
    echo 'FAIL: no valid records to save';
    if (!defined('SOPS_TESTING')) { exit; }
    return;
}

try {
    $saved = TopicService::saveAll($records);
    foreach ($saved as $record) {
        TopicService::saveBackup($record);
        Logger::auditTopic(
            isset($record['keyword']) ? $record['keyword'] : '',
            isset($record['git_name']) ? $record['git_name'] : '',
            isset($record['status']) ? $record['status'] : ''
        );
    }
    TopicService::export();
    Cache::forget('topic:summarize');
    echo 'OK: ' . count($saved) . ' record(s) updated';
} catch (\Throwable $e) {
    http_response_code(500);
    echo 'FAIL: ' . $e->getMessage();
}
