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
header('Content-Type: text/plain; charset=utf-8');

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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo 'FAIL: method not allowed';
    if (!defined('SOPS_TESTING')) { exit; }
    return;
}

$q = isset($_GET['t']) ? trim((string)$_GET['t']) : '';
if ($q === '') {
    http_response_code(400);
    echo 'FAIL: t parameter required';
    if (!defined('SOPS_TESTING')) { exit; }
    return;
}

$time = date('Ymd');

if ($q === 'all') {
    $rows = Database::fetchAll(
        'SELECT * FROM "sitetopic" WHERE "status" = :status AND "keyword" != \'\' ORDER BY RANDOM() LIMIT 1',
        ['status' => 'enable']
    );
} else {
    // t 可为任意列的值：ctx_id/keyword/git_name/domain/pubdir/status/lang/geo/lasttask/json
    $rows = Database::fetchAll(
        'SELECT * FROM "sitetopic" WHERE "status" = :status'
        . ' AND ("ctx_id" = :q OR "keyword" = :q OR "git_name" = :q OR "domain" = :q OR "pubdir" = :q OR "status" = :q2 OR "lang" = :q OR "geo" = :q OR "lasttask" = :q OR "json" LIKE :like)'
        . ' AND (json_extract("json", \'$.lasttask\') IS NULL OR json_extract("json", \'$.lasttask\') != :today)'
        . ' ORDER BY RANDOM() LIMIT 1',
        ['status' => 'enable', 'q' => $q, 'q2' => $q, 'like' => '%' . $q . '%', 'today' => $time]
    );
}

if (empty($rows)) {
    if (!defined('SOPS_TESTING')) { exit; }
    return;
}

foreach ($rows as $row) {
    $line = implode('|', [
        (string)$row['ctx_id'],
        (string)$row['keyword'],
        (string)$row['status'],
        (string)$row['git_name'],
        (string)$row['domain'],
        (string)$row['pubdir'],
        (string)$row['lang'],
        (string)$row['json'],
    ]);
    echo $line . PHP_EOL;

    $responseArray = explode('|', $line);
    $ctx_id = $responseArray[0];
    $json = end($responseArray);

    $keywordData = json_decode($json, true);
    if (!is_array($keywordData)) {
        $keywordData = [];
    }

    if (empty($keywordData['keyword'])) {
        continue;
    }

    if (empty($keywordData['geo']) && !empty($keywordData['lang'])) {
        $geoMap = [
            'en' => 'US', 'ja' => 'JP', 'zh' => 'CN', 'tw' => 'TW', 'hk' => 'HK',
            'es' => 'MX', 'ko' => 'KR', 'ar' => 'AR', 'ru' => 'RU', 'fr' => 'FR',
            'pt' => 'BR', 'bn' => 'BD', 'ur' => 'PK', 'de' => 'DE', 'sv' => 'SE',
            'vi' => 'VN', 'tr' => 'TR',
        ];
        $keywordData['geo'] = $geoMap[$keywordData['lang']] ?? strtoupper($keywordData['lang']);
    }

    if (empty($keywordData['lasttask'])) {
        $keywordData['lasttask'] = date('Ymd');
    }

    $keyword = $keywordData['keyword'] ?? '';
    $git_name = $keywordData['git_name'] ?? '';
    $domain = $keywordData['domain'] ?? '';
    $lang = $keywordData['lang'] ?? '';
    $geo = $keywordData['geo'] ?? '';
    $lasttask = $keywordData['lasttask'] ?? date('Ymd');
    $status = $keywordData['status'] ?? '';
    $pubdir = $keywordData['pubdir'] ?? '';

    if (!empty($keyword)) {
        if (empty($status)) {
            $existingRow = Database::fetchOne('SELECT "status" FROM "sitetopic" WHERE "ctx_id" = :ctx_id', ['ctx_id' => $ctx_id]);
            $status = (!empty($existingRow['status'])) ? (string)$existingRow['status'] : 'running';
        }

        $writeJson = $keywordData;
        unset($writeJson['post_uuid']);
        $writeJson['keyword'] = $keyword;
        $writeJson['git_name'] = $git_name;
        $writeJson['domain'] = $domain;
        $writeJson['pubdir'] = $pubdir;
        $writeJson['status'] = $status;
        $writeJson['lang'] = $lang;
        $writeJson['geo'] = $geo;
        $writeJson['lasttask'] = $lasttask;

        Database::execute(
            'UPDATE "sitetopic" SET "status" = :status, "lasttask" = :lasttask, "json" = :json, "time" = datetime(\'now\', \'localtime\') WHERE "ctx_id" = :ctx_id',
            ['status' => $status, 'lasttask' => $lasttask, 'json' => json_encode($writeJson), 'ctx_id' => $ctx_id]
        );

        \App\Repositories\TopicRepository::export();
    }
}
