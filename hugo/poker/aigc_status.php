<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Database;

$rawScope = isset($_GET['scope']) ? trim((string)$_GET['scope']) : '7';
$scopeAll = strtolower($rawScope) === 'all';
$scope = $scopeAll ? 7 : (int)$rawScope;
if ($scope < 1) {
    $scope = 7;
}
$pubdomain = isset($_GET['pubdomain']) ? strtolower(trim((string)$_GET['pubdomain'])) : '';

$cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - $scope * 86400);

// scope=all 是全表导出：旧行为 fetchAll 整表驻留 + json_encode 一次性编码，
// 行数大时同样会打爆 memory_limit（与 api/topic_query.php 同类问题）。
// 改为：COUNT 先行（DB 故障仍可走下方文件回退，此时未输出任何字节），
// 之后逐行流式写出，峰值内存≈单行。
$from = ' FROM "aigc_status"';
$where = '';
$params = [];
if (!$scopeAll) {
    $where = ' WHERE "publishAt" >= :cutoff';
    $params[':cutoff'] = $cutoff;
}
if ($pubdomain !== '') {
    $where .= ($where === '' ? ' WHERE ' : ' AND ') . ' ("," || lower("pubdomain") || "," LIKE :pd)';
    $params[':pd'] = '%,' . strtolower($pubdomain) . ',%';
}

$out = null;
$count = 0;
try {
    $countRow = Database::fetchOne('SELECT COUNT(*) AS "n"' . $from . $where, $params);
    $count = (int)($countRow['n'] ?? 0);
} catch (\Throwable $e) {
    $file = __DIR__ . '/seodata/aigc_status.json';
    $data = @file_get_contents($file);
    $items = $data !== false ? json_decode($data, true) : null;
    if (!is_array($items)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'invalid json']);
        exit;
    }
    $out = [];
    if ($pubdomain !== '') {
        $needle = ',' . $pubdomain . ',';
        foreach ($items as $item) {
            if (($scopeAll || $item['publishAt'] >= $cutoff)
                && str_contains(',' . strtolower($item['pubdomain']) . ',', $needle)) {
                $out[] = $item;
            }
        }
    } else {
        foreach ($items as $item) {
            if ($scopeAll || $item['publishAt'] >= $cutoff) {
                $out[] = $item;
            }
        }
    }
    $count = count($out);
}

header('Content-Type: application/json; charset=utf-8');
header('X-Result-Count: ' . $count);
if ($out !== null) {
    echo json_encode($out);
    if (!defined('SOPS_TESTING')) { exit; }
    return;
}
$started = false;
foreach (Database::each('SELECT *' . $from . $where . ' ORDER BY "publishAt" DESC', $params) as $row) {
    echo ($started ? ',' : '[') . json_encode($row);
    $started = true;
    unset($row);
}
echo $started ? ']' : '[]';