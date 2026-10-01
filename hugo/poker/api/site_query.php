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

$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 0;
$table_name = 'siteops';
$isPg = Database::isPg();

// 获取列名：PG 用 information_schema，SQLite 用 PRAGMA
$cols = [];
if ($isPg) {
    $rows = Database::fetchAll(
        'SELECT "column_name" FROM "information_schema"."columns" WHERE "table_name" = :t ORDER BY "ordinal_position"',
        [':t' => $table_name]
    );
    foreach ($rows as $r) { $cols[] = $r['column_name']; }
} else {
    $db = Database::connection();
    $res = $db->query('PRAGMA table_info(' . $table_name . ')');
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) { $cols[] = $row['name']; }
}

// 构建 LIKE / ILIKE WHERE 条件
$likeOp = $isPg ? 'ILIKE' : 'LIKE';
$likeWhere = '';
$likeParams = [];
if ($q !== 'all' && !empty($cols)) {
    $whereParts = [];
    foreach ($cols as $i => $col) {
        // PG 必须统一 ::text：json 列是 JSONB（无 LIKE 操作符），
        // id 是 BIGINT、time 是 TIMESTAMPTZ（同样无 ILIKE 操作符），
        // 不加 ::text 会抛 42883 operator does not exist
        $colExpr = $isPg ? '"' . $col . '"::text' : '"' . $col . '"';
        $whereParts[] = $colExpr . ' ' . $likeOp . ' :p' . $i;
        $likeParams[':p' . $i] = '%' . $q . '%';
    }
    $likeWhere = implode(' OR ', $whereParts);
}

// PG 没有 json_valid()，用 jsonb_typeof 代替
$doneCond = $isPg
    ? 'jsonb_typeof("json") = \'object\' AND ("json"->>\'status\') = \'done\''
    : 'json_valid("json") AND json_extract("json", \'$.status\') = \'done\'';

if ($limit > 0) {
    // 原始逻辑：count 不带 done 条件
    $countSql = 'SELECT COUNT(*) AS "n" FROM "' . $table_name . '"' . ($likeWhere !== '' ? ' WHERE ' . $likeWhere : '');
    $total = (int)Database::fetchOne($countSql, $likeParams)['n'];
} else {
    $total = 0;
}

if ($limit == 0 || $total <= $limit) {
    $sql = 'SELECT "ctx_id", "json" FROM "' . $table_name . '"'
         . ($likeWhere !== '' ? ' WHERE (' . $likeWhere . ') AND ' : ' WHERE ') . $doneCond;
    $rows = Database::fetchAll($sql, $likeParams);

    $output = [];
    foreach ($rows as $row) {
        $siteData = json_decode((string)$row['json'], true);
        if (!is_array($siteData)) { continue; }
        $output[] = ['id' => $row['ctx_id']] + $siteData;
    }
    $response = $output;
} else {
    // 原始逻辑：优先话题数少的站点（负载均衡），同数量内随机
    $aliasWhere = $likeWhere !== '' ? preg_replace('/"(\w+)"(::text)? ' . preg_quote($likeOp) . '/', 's."$1"$2 ' . $likeOp, $likeWhere) : ($isPg ? 'TRUE' : '1');
    $sql = 'SELECT s."ctx_id", s."json" FROM "' . $table_name . '" s'
         . ' LEFT JOIN (SELECT "domain", COUNT(*) AS "cnt" FROM "sitetopic" GROUP BY "domain") c ON c."domain" = s."domain"'
         . ' WHERE ' . $aliasWhere . ' AND ' . $doneCond
         . ' ORDER BY COALESCE(c."cnt", 0) ASC, RANDOM()'
         . ' LIMIT ' . (int)$limit;
    $rows = Database::fetchAll($sql, $likeParams);

    $output = [];
    foreach ($rows as $row) {
        $siteData = json_decode((string)$row['json'], true);
        if (!is_array($siteData)) { continue; }
        $output[] = ['id' => $row['ctx_id']] + $siteData;
    }
    $response = $output;
}

if (!empty($response)) {
    echo json_encode($response);
}
