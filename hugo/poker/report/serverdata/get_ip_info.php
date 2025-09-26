<?php
if (!isset($_GET['ip'])) {
    echo json_encode(['status' => 'fail', 'message' => 'No IP specified']);
    exit;
}

$ip = $_GET['ip'];
$url = "http://ip-api.com/json/$ip";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

// 输出api响应数据，直接返回给前端
header('Content-Type: application/json');
echo $response;
