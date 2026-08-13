<?php
declare(strict_types=1);

$file = __DIR__ . '/aigc_status.json';

$rawScope = isset($_GET['scope']) ? trim((string)$_GET['scope']) : '7';
$scopeAll = strtolower($rawScope) === 'all';
$scope = $scopeAll ? 7 : (int)$rawScope;
if ($scope < 1) {
    $scope = 7;
}
$pubdomain = isset($_GET['pubdomain']) ? strtolower(trim((string)$_GET['pubdomain'])) : '';

$cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - $scope * 86400);

$data = file_get_contents($file);
if ($data === false) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'json file not found']);
    exit;
}

$items = json_decode($data, true);
unset($data);
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
unset($items);

header('Content-Type: application/json; charset=utf-8');
header('X-Result-Count: ' . count($out));
echo json_encode($out);