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

try {
    $sql = 'SELECT * FROM "aigc_status" WHERE "publishAt" >= :cutoff';
    $params = [':cutoff' => $cutoff];
    if ($scopeAll) {
        $sql = 'SELECT * FROM "aigc_status"';
        $params = [];
    }
    if ($pubdomain !== '') {
        $sql .= ($scopeAll ? ' WHERE ' : ' AND ') . ' ("," || lower("pubdomain") || "," LIKE :pd)';
        $params[':pd'] = '%,' . strtolower($pubdomain) . ',%';
    }
    $sql .= ' ORDER BY "publishAt" DESC';
    $out = Database::fetchAll($sql, $params);
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
}

header('Content-Type: application/json; charset=utf-8');
header('X-Result-Count: ' . count($out));
echo json_encode($out);