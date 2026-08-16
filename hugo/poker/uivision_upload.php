<?php
// uivision_upload.php - 高性能文件上传端点
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
\App\Support\Security::requireApiToken();

// CORS：允许跨域 fetch 上传（UiVision ExecuteScript 从其他站点发起）
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(0);
ini_set('max_execution_time', '300');
ini_set('max_input_time', '-1');

$uploadDir = __DIR__ . '/uiaigcdatas/';
$maxBytes = 256 * 1024 * 1024; // 256MB

// 快速失败：CORS 预检请求直接放行
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// 快速失败：仅接受 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Invalid request method.';
    exit;
}

// 快速失败：必须带文件字段
if (!isset($_FILES['uploadedFile']) || !is_array($_FILES['uploadedFile'])) {
    http_response_code(400);
    echo 'No file uploaded.';
    exit;
}

$file = $_FILES['uploadedFile'];

// 快速失败：上传错误（含超出 php.ini 限制）
if ($file['error'] !== UPLOAD_ERR_OK) {
    $message = match ($file['error']) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File too large (php.ini upload_max_filesize/post_max_size limit).',
        UPLOAD_ERR_PARTIAL => 'File only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'No file uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        default => 'Unknown upload error (' . $file['error'] . ').',
    };
    http_response_code(400);
    echo $message;
    exit;
}

// 快速失败：超过业务大小上限
if ($file['size'] > $maxBytes) {
    http_response_code(413);
    echo 'File too large. Max ' . (int)round($maxBytes / 1048576) . 'MB allowed.';
    exit;
}

// 扩展名白名单（防恶意文件名），默认兜底 dat
$extension = strtolower((string)pathinfo((string)$file['name'], PATHINFO_EXTENSION));
if (!preg_match('/^[a-z0-9]{1,10}$/', $extension)) {
    $extension = 'txt';
}

// 自定义保存名：仅允许安全字符，防目录穿越，可覆盖同名文件
$customName = '';
if (isset($_POST['savename']) && trim((string)$_POST['savename']) !== '') {
    $customName = basename(trim((string)$_POST['savename']));
    if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/', $customName)) {
        http_response_code(400);
        echo 'Invalid save name.';
        exit;
    }
    if (stripos($customName, '.') === false) {
        $customName .= '.' . $extension;
    }
} else {
    $customName = str_replace('.', '', uniqid(time(), true)) . '.' . $extension;
}

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// rename 级移动（同文件系统近瞬时），完成后立即响应
if (move_uploaded_file($file['tmp_name'], $uploadDir . $customName)) {
    echo "File successfully uploaded as $customName";
} else {
    http_response_code(500);
    echo 'Error moving the uploaded file.';
}

// curl -F "uploadedFile=@175756461898001eb77c8f400f8a35a5.csv" "localhost:8888/uivision_upload.php"
