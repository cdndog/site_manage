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
header('Content-Type: text/plain; charset=utf-8');

set_time_limit(0);
ini_set('max_execution_time', '300');
ini_set('max_input_time', '-1');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    if (!defined('SOPS_TESTING')) { exit; }
    return;
}

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

$post = SiteService::sanitizePost($_POST);

$domain = isset($post['post_domain']) ? trim((string)$post['post_domain']) : '';
$gitName = isset($post['post_gitname']) ? trim((string)$post['post_gitname']) : '';

if ($domain === '' || $gitName === '') {
    http_response_code(400);
    echo 'FAIL: post_domain and post_gitname are required';
    if (!defined('SOPS_TESTING')) { exit; }
    return;
}

$siteJson = SiteService::buildSiteJson($post);
$content = SiteService::buildContent($post, $siteJson);

try {
    SiteRepository::upsertByDomain($content);
    SiteService::saveBackup($content, Config::dataDir());
    ExportService::export();
    Cache::forget('site:all');
    Logger::auditSubmit(
        isset($content['ctx_id']) ? $content['ctx_id'] : '',
        $gitName,
        $domain,
        isset($content['status']) ? $content['status'] : ''
    );
    echo 'OK: 1 record updated';
} catch (\Throwable $e) {
    http_response_code(500);
    echo 'FAIL: ' . $e->getMessage();
}
