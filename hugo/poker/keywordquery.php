<?php

// 错误写入日志但不输出，避免污染 JSON 响应（原先 error_reporting(0) 会让故障静默成空白 body）
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

defined('LocalPATH') || define("LocalPATH", dirname(__FILE__));

require __DIR__ . '/app/bootstrap.php';

\App\Support\Security::requireApiToken();

use App\Database;

$savedir = "keywordmonitor";
$logFile = 'keyword_monitor_list.txt';

if ($_SERVER["REQUEST_METHOD"] == "GET") {
    // 参数校验放在外层守卫之前，避免缺失 t 时静默无输出
    $q = trim((string)($_GET['t'] ?? ''));
    if ($q === '') {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 't parameter required']);
        if (!defined('SOPS_TESTING')) { exit; }
        return;
    }
    try {
        if ($q === "all") {
            $rows = Database::fetchAll(
                'SELECT "ctx_id", "json" FROM "keywordmonitorlist" ORDER BY "id"'
            );
        } else {
            $rows = Database::fetchAll(
                'SELECT "ctx_id", "json" FROM "keywordmonitorlist" WHERE "ctx_id" = :q OR "keyword" = :q',
                ['q' => $q]
            );
        }
    } catch (\Throwable $e) {
        error_log('keywordquery DB error: ' . $e->getMessage());
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['error' => 'query failed']);
        if (!defined('SOPS_TESTING')) { exit; }
        return;
    }

    $response = array();
    foreach ($rows as $row) {
        $decoded = json_decode((string)$row['json'], true);
        if (!is_array($decoded)) {
            $decoded = array();
        }
        $response[] = array('id' => (string)$row['ctx_id']) + $decoded;
    }

    // 无匹配时返回 200 + []，而非空白 body（调用方恒定拿到数组）
    header('Content-Type: application/json');
    echo json_encode($response);
}