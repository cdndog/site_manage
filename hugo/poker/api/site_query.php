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

$db = Database::connection();
$db->enableExceptions(true);

$likeWhere = '';
$likeParams = [];
if ($q !== 'all') {
    $cols = [];
    $res = $db->query('PRAGMA table_info(' . $table_name . ')');
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $cols[] = $row['name'];
    }
    if (!empty($cols)) {
        $whereParts = [];
        foreach ($cols as $i => $col) {
            $whereParts[] = '"' . $col . '" LIKE :p' . $i;
            $likeParams[':p' . $i] = '%' . $q . '%';
        }
        $likeWhere = implode(' OR ', $whereParts);
    }
}

$doneCond = 'json_valid("json") AND json_extract("json", \'$.status\') = \'done\'';

if ($limit > 0) {
    $countSql = 'SELECT COUNT(*) AS n FROM "' . $table_name . '"' . ($likeWhere !== '' ? ' WHERE ' . $likeWhere : '');
    $stmt = $db->prepare($countSql);
    foreach ($likeParams as $k => $v) {
        $stmt->bindValue($k, $v, SQLITE3_TEXT);
    }
    $total = (int)$stmt->execute()->fetchArray(SQLITE3_ASSOC)['n'];
} else {
    $total = 0;
}

if ($limit == 0 || $total <= $limit) {
    $sql = 'SELECT "ctx_id", "json" FROM "' . $table_name . '"'
         . ($likeWhere !== '' ? ' WHERE (' . $likeWhere . ') AND ' : ' WHERE ') . $doneCond;
    $stmt = $db->prepare($sql);
    foreach ($likeParams as $k => $v) {
        $stmt->bindValue($k, $v, SQLITE3_TEXT);
    }
    $res = $stmt->execute();

    $output = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $siteData = json_decode($row['json'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            continue;
        }
        $output[] = array('id' => $row['ctx_id']) + $siteData;
    }
    $response = $output;
} else {
    $aliasWhere = $likeWhere !== '' ? preg_replace('/"(\w+)" LIKE/', 's."$1" LIKE', $likeWhere) : '1';
    $sql = 'SELECT s."ctx_id", s."json" FROM "' . $table_name . '" s'
         . ' LEFT JOIN (SELECT "domain", COUNT(*) AS cnt FROM "sitetopic" GROUP BY "domain") c ON c."domain" = s."domain"'
         . ' WHERE ' . $aliasWhere . ' AND ' . $doneCond
         . ' ORDER BY COALESCE(c.cnt, 0) ASC, RANDOM()'
         . ' LIMIT ' . (int)$limit;
    $stmt = $db->prepare($sql);
    foreach ($likeParams as $k => $v) {
        $stmt->bindValue($k, $v, SQLITE3_TEXT);
    }
    $res = $stmt->execute();

    $output = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $siteData = json_decode($row['json'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            continue;
        }
        $output[] = array('id' => $row['ctx_id']) + $siteData;
    }
    $response = $output;
}

if (!empty($response)) {
    echo json_encode($response);
}
