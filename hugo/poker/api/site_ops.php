<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Config;
use App\Repositories\SiteRepository;
use App\Services\ExportService;
use App\Services\SiteService;
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
 * site_ops：供 API 调用方新增/更新站点（siteops）数据。
 *
 * 与 api/site_update.php 的差异：
 *   1. 返回 JSON，并区分 created / updated，调用方能确认是否真的新增；
 *   2. 同时接受 JSON body 与表单编码（api/*_update.php 仅支持表单 $_POST）；
 *   3. 字段名兼容 post_domain / domain 等两种写法；
 *   4. 补充 RBAC（site.manage）；
 *   5. 支持部分更新——未传字段保留库中原值。
 *
 * 语义沿用 SiteRepository::upsertByDomain —— 按 domain 命中则更新，否则 INSERT。
 */
if (!function_exists("siteOpsFail")) {
function siteOpsFail(int $code, string $message, $detail = null): void
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
 * 与 post_domain 等字段都能被后续逻辑正常读取（表单编码请求不受影响）。
 */
$siteOpsRawBody = file_get_contents('php://input');
$siteOpsBodyData = (is_string($siteOpsRawBody) && $siteOpsRawBody !== '')
    ? json_decode($siteOpsRawBody, true)
    : null;
if (is_array($siteOpsBodyData)) {
    foreach ($siteOpsBodyData as $key => $value) {
        if (is_scalar($value) && !isset($_POST[$key])) {
            $_POST[$key] = (string)$value;
        }
    }
}

/** 读取请求参数：已合并 JSON body 与表单参数，只保留标量值 */
if (!function_exists("siteOpsReadInput")) {
function siteOpsReadInput(): array
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
 * normalize（简写 -> post_*）与 providedColumns 判定（post_* -> 简写）共用此表。
 */
if (!function_exists("siteOpsAliases")) {
function siteOpsAliases(): array
{
    return [
        'post_uuid' => 'ctx_id',
        'post_gitname' => 'git_name',
        'post_git_name' => 'git_name',
        'post_gitaccount' => 'git_account',
        'post_domain' => 'domain',
        'post_keyword' => 'keyword',
        'post_sitetitle' => 'site_title',
        'post_description' => 'description',
        'post_sitelogo' => 'site_logo',
        'post_sitedir' => 'sitedir',
        'post_sitedeploy' => 'deploy',
        'post_sitehostip' => 'hostip',
        'post_lang' => 'languages',
        'post_sns_id' => 'sns_id',
        'post_topnavmenus' => 'topnav_menus',
        'post_themename' => 'theme_name',
        'post_themetype' => 'theme_type',
        'post_sitetype' => 'site_type',
        'post_status' => 'status',
    ];
}
}

/** 把 domain / git_name 等简写字段映射为 SiteService 期望的 post_* 字段 */
if (!function_exists("siteOpsNormalize")) {
function siteOpsNormalize(array $input): array
{
    $post = $input;
    foreach (siteOpsAliases() as $postKey => $plainKey) {
        if (!isset($post[$postKey]) && isset($post[$plainKey]) && $post[$plainKey] !== '') {
            $post[$postKey] = $post[$plainKey];
        }
    }
    return $post;
}
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    if (!defined('SOPS_TESTING')) { exit; }
    return;
}

// 鉴权优先于参数校验：与 api/site_update.php 保持一致
if (Config::apiCsrfTokens() !== [] && !Security::apiTokenValid() && !Security::hasValidSession() && !Security::isGitServerIp()) {
    siteOpsFail(403, 'forbidden: missing or invalid API token');
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    siteOpsFail(405, 'method not allowed');
    return;
}

try {
    Security::requirePermission('site.manage');
} catch (\Throwable $e) {
    siteOpsFail(403, 'forbidden: insufficient permission');
    return;
}

$rawInput = siteOpsReadInput();

// 记录调用方实际传了哪些 post_* 字段：供部分更新使用。
// 必须在 normalize 之前判定，否则补的默认值会被误认为调用方意图。
// 简写字段名（languages/site_title 等）需先归一到对应的 post_* 名。
$providedColumns = [];
foreach (siteOpsAliases() as $postKey => $plainKey) {
    if (array_key_exists($postKey, $rawInput)
        || (array_key_exists($plainKey, $rawInput) && $rawInput[$plainKey] !== '')
    ) {
        $providedColumns[] = $postKey;
    }
}
foreach ($rawInput as $key => $value) {
    $key = (string)$key;
    if ($key === 'post_json' || $key === 'setupNum') {
        continue;
    }
    if (strpos($key, 'post_') === 0 || strpos($key, 'local_') === 0) {
        $providedColumns[] = $key;
    }
}
$providedColumns = array_values(array_unique($providedColumns));

$post = siteOpsNormalize(SiteService::sanitizePost($rawInput));

$domain = isset($post['post_domain']) ? trim((string)$post['post_domain']) : '';
$gitName = isset($post['post_gitname']) ? trim((string)$post['post_gitname']) : '';

if ($domain === '' || $gitName === '') {
    siteOpsFail(400, 'post_domain and post_gitname are required');
    return;
}

$siteJson = SiteService::buildSiteJson($post);
$content = SiteService::buildContent($post, $siteJson);

// 保存前先判定是新增还是更新（upsertByDomain 本身不做区分）
$existing = SiteRepository::byDomain($domain);
$action = $existing === null ? 'created' : 'updated';

try {
    // 新增走整行写入；更新只写调用方实际传来的列
    SiteRepository::upsertByDomain($content, $action === 'updated' ? $providedColumns : null);

    $saved = SiteRepository::byDomain($domain);
    SiteService::saveBackup($content, Config::dataDir());
    ExportService::export();
    Cache::forget('site:all');
    Logger::auditSubmit(
        isset($content['ctx_id']) ? $content['ctx_id'] : '',
        $gitName,
        $domain,
        isset($content['status']) ? $content['status'] : ''
    );

    $row = [
        'action' => $action,
        'ctx_id' => isset($saved['ctx_id']) ? (string)$saved['ctx_id'] : '',
        'domain' => isset($saved['domain']) ? (string)$saved['domain'] : '',
        'git_name' => isset($saved['git_name']) ? (string)$saved['git_name'] : '',
        'git_account' => isset($saved['git_account']) ? (string)$saved['git_account'] : '',
        'site_title' => isset($saved['site_title']) ? (string)$saved['site_title'] : '',
        'site_subtitle' => isset($saved['site_subtitle']) ? (string)$saved['site_subtitle'] : '',
        'site_logo' => isset($saved['site_logo']) ? (string)$saved['site_logo'] : '',
        'languages' => isset($saved['languages']) ? (string)$saved['languages'] : '',
        'sns_id' => isset($saved['sns_id']) ? (string)$saved['sns_id'] : '',
        'keyword' => isset($saved['keyword']) ? (string)$saved['keyword'] : '',
        'theme_name' => isset($saved['theme_name']) ? (string)$saved['theme_name'] : '',
        'theme_type' => isset($saved['theme_type']) ? (string)$saved['theme_type'] : '',
        'sitedir' => isset($saved['sitedir']) ? (string)$saved['sitedir'] : '',
        'deploy' => isset($saved['deploy']) ? (string)$saved['deploy'] : '',
        'hostip' => isset($saved['hostip']) ? (string)$saved['hostip'] : '',
        'local_deploy' => isset($saved['local_deploy']) ? (string)$saved['local_deploy'] : '',
        'local_hostip' => isset($saved['local_hostip']) ? (string)$saved['local_hostip'] : '',
        'status' => isset($saved['status']) ? (string)$saved['status'] : '',
    ];

    echo json_encode([
        'ok' => true,
        'total' => 1,
        'created' => $action === 'created' ? 1 : 0,
        'updated' => $action === 'updated' ? 1 : 0,
        'rows' => [$row],
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('site_ops save error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'save failed', 'detail' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    if (!defined('SOPS_TESTING')) { exit; }
}