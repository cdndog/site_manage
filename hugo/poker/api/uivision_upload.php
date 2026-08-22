<?php
declare(strict_types=1);

// api/uivision_upload.php - API 版高性能文件上传（与根 uivision_upload.php 同功能，SOPS_TESTING 兼容）

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-API-Key, Authorization');
header('Access-Control-Max-Age: 86400');
header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/../app/bootstrap.php';

use App\Config;
use App\Support\Security;

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

set_time_limit(0);
ini_set('max_execution_time', '300');
ini_set('max_input_time', '-1');

$uploadDir = dirname(__DIR__) . '/uiaigcdatas/';
$maxBytes = 256 * 1024 * 1024;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Invalid request method.';
    if (!defined('SOPS_TESTING')) { exit; }
    return;
}

if (!isset($_FILES['uploadedFile']) || !is_array($_FILES['uploadedFile'])) {
    http_response_code(400);
    echo 'No file uploaded.';
    if (!defined('SOPS_TESTING')) { exit; }
    return;
}

$file = $_FILES['uploadedFile'];

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
    if (!defined('SOPS_TESTING')) { exit; }
    return;
}

if ($file['size'] > $maxBytes) {
    http_response_code(413);
    echo 'File too large. Max ' . (int)round($maxBytes / 1048576) . 'MB allowed.';
    if (!defined('SOPS_TESTING')) { exit; }
    return;
}

$extension = strtolower((string)pathinfo((string)$file['name'], PATHINFO_EXTENSION));
if (!preg_match('/^[a-z0-9]{1,10}$/', $extension)) {
    $extension = 'txt';
}

$customName = '';
if (isset($_POST['savename']) && trim((string)$_POST['savename']) !== '') {
    $customName = basename(trim((string)$_POST['savename']));
    if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/', $customName)) {
        http_response_code(400);
        echo 'Invalid save name.';
        if (!defined('SOPS_TESTING')) { exit; }
        return;
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

$moved = move_uploaded_file($file['tmp_name'], $uploadDir . $customName);
if (!$moved && defined('SOPS_TESTING')) {
    $moved = @rename($file['tmp_name'], $uploadDir . $customName);
}
if ($moved) {
    echo "File successfully uploaded as $customName";
    $savedPath = $uploadDir . $customName;
    if (is_file($savedPath)) {
        $raw = (string)file_get_contents($savedPath);
        $parts = explode('|', $raw, 3);
        $jsonStr = isset($parts[2]) ? trim($parts[2]) : trim($raw);
        if ($jsonStr !== '' && $jsonStr !== 'workflowerror') {
            $decoded = json_decode($jsonStr, true);
            if (is_array($decoded) && isset($decoded[0]) && is_array($decoded[0])) {
                $decoded = $decoded[0];
            }
            if (is_array($decoded) && !empty($decoded['post_uuid'])) {
                try {
                    \App\Services\ArticleService::saveSeoData($decoded);
                } catch (\Throwable $e) {
                    error_log('[api/uivision_upload] saveSeoData failed for ' . $customName . ': ' . $e->getMessage());
                }
            } elseif (is_array($decoded)) {
                error_log('[api/uivision_upload] JSON missing post_uuid for ' . $customName);
            } else {
                error_log('[api/uivision_upload] invalid JSON for ' . $customName . ': ' . substr($jsonStr, 0, 200));
            }
        }
    }
} else {
    http_response_code(500);
    echo 'Error moving the uploaded file.';
}
