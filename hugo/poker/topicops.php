<?php

// CORS：允许跨域 fetch 上传（UiVision ExecuteScript 从其他站点发起）
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
// 跨域 fetch（UiVision）返回纯文本；同源浏览器表单提交（Accept: text/html）保持 HTML 渲染
$isApiFetch = isset($_SERVER['HTTP_ORIGIN']) && strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') === false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isApiFetch) {
    header('Content-Type: text/plain; charset=utf-8');
}
set_time_limit(0);
ini_set('max_execution_time', '300');
ini_set('max_input_time', '-1');

require __DIR__ . '/app/bootstrap.php';

App\Controllers\TopicController::dispatch();