<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Config;
use App\Repositories\KeywordRepository;
use App\Services\KeywordService;
use App\Support\Cache;
use App\Support\Logger;
use App\Support\Security;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-API-Key, Authorization');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');

set_time_limit(0);
ini_set('max_execution_time', '300');
ini_set('max_input_time', '-1');

/**
 * keyword_ops：供 API 调用方新增/更新关键词（keywordmonitorlist）数据。
 *
 * 与 api/keyword_update.php 的差异：
 *   1. 返回 JSON，并逐条区分 created / updated，调用方能确认是否真的新增；
 *   2. 同时接受 JSON body 与表单编码（api/*_update.php 仅支持表单 $_POST）；
 *   3. 字段名兼容 post_keyword / keyword 等两种写法；
 *   4. 补充 RBAC（keyword.manage）；
 *   5. 支持部分更新——未传字段保留库中原值。
 *
 * 语义沿用 KeywordRepository::upsertByKeyword —— 按 ctx_id 命中则更新，
 * 否则按 keyword 命中则更新，都没有才 INSERT。
 */
if (!function_exists("keywordOpsFail")) {
function keywordOpsFail(int $code, string $message, $detail = null): void
{
    http_response_code($code);
    $payload = ['ok' => false, 'error' => $message];
    if ($detail !== null && $detail !== '') {
        $payload['detail'] = $detail;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (!defined('SOPS_TESTING')) { exit; }
}
}

/**
 * PHP 不会把 application/json 请求体解析进 $_POST。
 * 这里在鉴权之前手动合并，使 JSON body 里的 csrf_token / api_key
 * 与 post_keyword 等字段都能被后续逻辑正常读取（表单编码请求不受影响）。
 */
$keywordOpsRawBody = file_get_contents('php://input');
$keywordOpsBodyData = (is_string($keywordOpsRawBody) && $keywordOpsRawBody !== '')
    ? json_decode($keywordOpsRawBody, true)
    : null;
if (is_array($keywordOpsBodyData)) {
    foreach ($keywordOpsBodyData as $key => $value) {
        if (is_scalar($value) && !isset($_POST[$key])) {
            $_POST[$key] = (string)$value;
        }
    }
}

/** 读取请求参数：已合并 JSON body 与表单参数，只保留标量值，
 *  避免 sanitizePost 的 str_replace 遇到数组报错。 */
if (!function_exists("keywordOpsReadInput")) {
function keywordOpsReadInput(): array
{
    $flat = [];
    foreach ($_POST as $key => $value) {
        if (is_scalar($value)) {
            $flat[$key] = (string)$value;
        }
    }
    return $flat;
}
}

/**
 * post_* -> 简写字段名映射。
 * normalize（简写 -> post_*）与 providedColumns 判定（post_* -> 列名）共用此表。
 */
if (!function_exists("keywordOpsAliases")) {
function keywordOpsAliases(): array
{
    return [
        'post_keyword' => 'keyword',
        'post_gitname' => 'git_name',
        'post_git_name' => 'git_name',
        'post_pubdir' => 'pubdir',
        'post_lang' => 'lang',
        'post_geo' => 'geo',
        'post_status' => 'status',
        'post_lasttask' => 'lasttask',
        'post_ctxid' => 'ctx_id',
        'post_uuid' => 'ctx_id',
    ];
}
}

/** 把 keyword / git_name 等简写字段映射为 KeywordService 期望的 post_* 字段 */
if (!function_exists("keywordOpsNormalize")) {
function keywordOpsNormalize(array $input): array
{
    $post = $input;
    foreach (keywordOpsAliases() as $postKey => $plainKey) {
        if (!isset($post[$postKey]) && isset($post[$plainKey]) && $post[$plainKey] !== '') {
            $post[$postKey] = $post[$plainKey];
        }
    }
    // ctx_id 可用 ctx_id / uuid / post_ctxid 任一写法
    if (!isset($post['post_ctxid']) || $post['post_ctxid'] === '') {
        foreach (['post_uuid', 'ctx_id', 'uuid'] as $key) {
            if (isset($post[$key]) && $post[$key] !== '') {
                $post['post_ctxid'] = $post[$key];
                break;
            }
        }
    }
    // bulk 支持 post_bulkkeyword=enable 或 bulk=1/true
    $bulk = $post['post_bulkkeyword'] ?? ($post['bulk'] ?? '');
    $isBulk = in_array(strtolower(trim((string)$bulk)), ['enable', '1', 'true', 'yes'], true);
    $post['post_bulkkeyword'] = $isBulk ? 'enable' : '';
    // status / pubdir 等缺省值沿用表单默认值
    if (!isset($post['post_status']) || trim((string)$post['post_status']) === '') {
        $post['post_status'] = 'enable';
    }
    if (!isset($post['post_pubdir']) || trim((string)$post['post_pubdir']) === '') {
        $post['post_pubdir'] = 'article';
    }
    return $post;
}
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    if (!defined('SOPS_TESTING')) { exit; }
    return;
}

// 鉴权优先于参数校验：与 api/keyword_update.php 保持一致
if (Config::apiCsrfTokens() !== [] && !Security::apiTokenValid() && !Security::hasValidSession() && !Security::isGitServerIp()) {
    keywordOpsFail(403, 'forbidden: missing or invalid API token');
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    keywordOpsFail(405, 'method not allowed');
    return;
}

try {
    Security::requirePermission('keyword.manage');
} catch (\Throwable $e) {
    keywordOpsFail(403, 'forbidden: insufficient permission');
    return;
}

$rawInput = keywordOpsReadInput();

// 记录调用方实际传了哪些列：供部分更新使用，避免把未传字段写成空串。
// 必须在补默认值之前判定，否则默认值会被误认为调用方意图而覆盖原值。
$providedColumns = [];
foreach (keywordOpsAliases() as $postKey => $plainKey) {
    if (array_key_exists($postKey, $rawInput)
        || (array_key_exists($plainKey, $rawInput) && $rawInput[$plainKey] !== '')
    ) {
        $providedColumns[] = $postKey === 'post_uuid' ? 'ctx_id' : $plainKey;
    }
}
foreach ($rawInput as $key => $value) {
    $key = (string)$key;
    if (in_array($key, ['post_bulkkeyword', 'bulk'], true)) {
        continue;
    }
    if (in_array($key, KeywordService::RENEW_COLUMNS, true)) {
        $providedColumns[] = $key;
    }
}
$providedColumns = array_values(array_unique($providedColumns));

$post = keywordOpsNormalize(KeywordService::sanitizePost($rawInput));

$keyword = isset($post['post_keyword']) ? trim((string)$post['post_keyword']) : '';
$gitName = isset($post['post_gitname']) ? trim((string)$post['post_gitname']) : '';

if ($keyword === '' || $gitName === '') {
    keywordOpsFail(400, 'post_keyword and post_gitname are required');
    return;
}

$records = KeywordService::buildRecords($post);
if (count($records) === 0) {
    keywordOpsFail(400, 'no valid records to save');
    return;
}

// 保存前先判定每条是新增还是更新（upsertByKeyword 本身不做区分）
$pending = [];
foreach ($records as $record) {
    $existing = null;
    if (!empty($record['ctx_id'])) {
        $existing = KeywordRepository::byCtxId($record['ctx_id']);
    }
    if ($existing === null) {
        $existing = KeywordRepository::byKeyword(
            isset($record['keyword']) ? $record['keyword'] : ''
        );
    }
    $pending[] = ['record' => $record, 'action' => $existing === null ? 'created' : 'updated'];
}

try {
    $rows = [];
    $created = 0;
    $updated = 0;
    foreach ($pending as $item) {
        $record = $item['record'];
        // 新增走整行写入；更新只写调用方实际传来的列
        $saved = KeywordService::saveAll([$record], $item['action'] === 'updated' ? $providedColumns : null);
        if (count($saved) === 0) {
            continue;
        }
        $savedRecord = $saved[0];
        KeywordService::saveBackup($savedRecord);
        Logger::auditKeyword(
            isset($savedRecord['keyword']) ? $savedRecord['keyword'] : '',
            isset($savedRecord['git_name']) ? $savedRecord['git_name'] : '',
            isset($savedRecord['status']) ? $savedRecord['status'] : ''
        );
        if ($item['action'] === 'created') { $created++; } else { $updated++; }
        $rows[] = [
            'action' => $item['action'],
            'ctx_id' => isset($savedRecord['ctx_id']) ? (string)$savedRecord['ctx_id'] : '',
            'keyword' => isset($savedRecord['keyword']) ? (string)$savedRecord['keyword'] : '',
            'git_name' => isset($savedRecord['git_name']) ? (string)$savedRecord['git_name'] : '',
            'pubdir' => isset($savedRecord['pubdir']) ? (string)$savedRecord['pubdir'] : '',
            'status' => isset($savedRecord['status']) ? (string)$savedRecord['status'] : '',
            'lang' => isset($savedRecord['lang']) ? (string)$savedRecord['lang'] : '',
            'geo' => isset($savedRecord['geo']) ? (string)$savedRecord['geo'] : '',
            'lasttask' => isset($savedRecord['lasttask']) ? (string)$savedRecord['lasttask'] : '',
        ];
    }
    KeywordService::export();
    Cache::forget('keyword:all');

    echo json_encode([
        'ok' => true,
        'total' => count($rows),
        'created' => $created,
        'updated' => $updated,
        'rows' => $rows,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('keyword_ops save error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'save failed', 'detail' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    if (!defined('SOPS_TESTING')) { exit; }
}