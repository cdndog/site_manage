<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Config;
use App\Database;
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
    echo json_encode(['error' => 'forbidden: missing or invalid API token']);
    if (!defined('SOPS_TESTING')) { exit; }
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    if (!defined('SOPS_TESTING')) { exit; }
    return;
}

$q = isset($_GET['t']) ? trim((string)$_GET['t']) : '';
if ($q === '') {
    http_response_code(400);
    echo json_encode(['error' => 't parameter required']);
    if (!defined('SOPS_TESTING')) { exit; }
    return;
}

if ($q === 'all') {
    $rows = Database::fetchAll('SELECT "ctx_id", "json" FROM "sitetopic" ORDER BY "id"');
} else {
    // t 可为任意列的值：ctx_id / keyword / git_name / domain / pubdir / status / lang / geo / lasttask / json
    // 精确匹配所有列，兼容类似 site_query 的全列模糊能力
    // PG：json 列为 JSONB，需 ::text 转型才能 ILIKE
    $jsonLike = Database::isPg() ? '"json"::text ILIKE :like' : '"json" LIKE :like';
    $rows = Database::fetchAll(
        'SELECT "ctx_id", "json" FROM "sitetopic" WHERE "ctx_id" = :q OR "keyword" = :q OR "git_name" = :q OR "domain" = :q OR "pubdir" = :q OR "status" = :q OR "lang" = :q OR "geo" = :q OR "lasttask" = :q OR ' . $jsonLike . ' ORDER BY "id"',
        ['q' => $q, 'like' => '%' . $q . '%']
    );
}

$response = [];
foreach ($rows as $row) {
    $jsondata = json_decode((string)$row['json'], true);
    if (!is_array($jsondata)) {
        $jsondata = [];
    }
    $response[] = array('id' => (string)$row['ctx_id']) + $jsondata;
}

// 无匹配时返回 200 + []，而非空白 body（调用方恒定拿到数组）
echo json_encode($response);
