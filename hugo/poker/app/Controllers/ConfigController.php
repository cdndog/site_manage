<?php

namespace App\Controllers;

use App\Config;
use App\Database;
use App\Support\Security;

class ConfigController
{
    public static function dispatchList()
    {
        try {
            Security::requireApiToken(true);
            Security::requirePermission('config.manage');
            $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
            if ($method === 'POST') {
                self::handleImport();
                return;
            }
            self::shell('配置管理', 'config_list', self::listData());
        } catch (\Throwable $e) {
            renderErrorPage($e);
        }
    }

    private static function handleImport()
    {
        if (!Security::csrfVerify(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
            http_response_code(403);
            render('error', ['message' => 'CSRF token invalid, please reload the page and try again.']);
            return;
        }
        $file = Config::configFile();
        $fileConfig = is_file($file) ? include $file : [];
        $fileConfig = is_array($fileConfig) ? $fileConfig : [];
        $now = date('Y-m-d H:i:s');
        $currentUser = Security::currentUser();
        $updatedBy = $currentUser !== null && isset($currentUser['username']) ? $currentUser['username'] : '';
        $imported = 0;
        foreach (Config::dictionaryKeys() as $key) {
            if (!isset($fileConfig[$key]) || !is_array($fileConfig[$key])) {
                continue;
            }
            self::saveDictionary($key, $fileConfig[$key], $now, $updatedBy);
            $imported++;
        }
        Config::reset();
        self::shell('配置管理', 'config_list', array_merge(self::listData(), ['message' => 'imported ' . $imported . ' items from global_config.php', 'error' => false]));
    }

    private static function saveDictionary($key, array $value, $now, $updatedBy)
    {
        $existing = Database::fetchOne('SELECT "id" FROM "app_configs" WHERE "config_key" = :key', ['key' => $key]);
        if ($existing !== null) {
            Database::execute(
                'UPDATE "app_configs" SET "config_value" = :value, "updated_at" = :updated_at, "updated_by" = :updated_by WHERE "config_key" = :key',
                ['value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'updated_at' => $now, 'updated_by' => $updatedBy, 'key' => $key]
            );
        } else {
            Database::execute(
                'INSERT INTO "app_configs" ("config_key", "config_value", "description", "updated_at", "updated_by") VALUES (:key, :value, :description, :updated_at, :updated_by)',
                [
                    'key' => $key,
                    'value' => json_encode($value, JSON_UNESCAPED_UNICODE),
                    'description' => Config::dictionaryDescriptions()[$key],
                    'updated_at' => $now,
                    'updated_by' => $updatedBy,
                ]
            );
        }
    }

    public static function dispatchEdit()
    {
        try {
            Security::requireApiToken(true);
            Security::requirePermission('config.manage');
            $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
            if ($method === 'POST') {
                self::handleSave();
                return;
            }
            $data = self::editData();
            if ($data === null) {
                return;
            }
            self::shell('配置管理', 'config_edit', $data);
        } catch (\Throwable $e) {
            renderErrorPage($e);
        }
    }

    private static function listData()
    {
        $keys = Config::dictionaryKeys();
        $descriptions = Config::dictionaryDescriptions();
        $file = Config::configFile();
        $fileConfig = is_file($file) ? include $file : [];
        $fileConfig = is_array($fileConfig) ? $fileConfig : [];
        $dbRows = [];
        foreach (Database::fetchAll('SELECT "config_key", "config_value", "description", "updated_at", "updated_by" FROM "app_configs" ORDER BY "config_key"') as $row) {
            $dbRows[$row['config_key']] = $row;
        }
        $rows = [];
        foreach ($keys as $key) {
            $dbRow = isset($dbRows[$key]) ? $dbRows[$key] : null;
            $value = $dbRow !== null
                ? json_decode((string)$dbRow['config_value'], true)
                : (isset($fileConfig[$key]) && is_array($fileConfig[$key]) ? $fileConfig[$key] : []);
            $count = is_array($value) ? count($value) : 0;
            $rows[] = [
                'key' => $key,
                'count' => $count,
                'description' => $dbRow !== null ? (string)$dbRow['description'] : (isset($descriptions[$key]) ? $descriptions[$key] : ''),
                'source' => $dbRow !== null ? 'database' : 'file',
                'updated_at' => $dbRow !== null ? (string)$dbRow['updated_at'] : '',
                'updated_by' => $dbRow !== null ? (string)$dbRow['updated_by'] : '',
            ];
        }
        return ['rows' => $rows, 'csrf_token' => Security::csrfToken()];
    }

    private static function editData()
    {
        $key = isset($_GET['key']) ? trim((string)$_GET['key']) : '';
        if (!in_array($key, Config::dictionaryKeys(), true)) {
            http_response_code(404);
            render('error', ['message' => 'unknown config key']);
            return null;
        }
        $row = Database::fetchOne('SELECT "config_key", "config_value", "description", "updated_at", "updated_by" FROM "app_configs" WHERE "config_key" = :key', ['key' => $key]);
        $current = null;
        if ($row !== null) {
            $current = json_decode((string)$row['config_value'], true);
        }
        if (!is_array($current)) {
            $file = Config::configFile();
            $fileConfig = is_file($file) ? include $file : [];
            $current = is_array($fileConfig) && isset($fileConfig[$key]) && is_array($fileConfig[$key]) ? $fileConfig[$key] : [];
        }
        return [
            'key' => $key,
            'description' => $row !== null ? (string)$row['description'] : Config::dictionaryDescriptions()[$key],
            'json' => json_encode($current, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'count' => count($current),
            'updated_at' => $row !== null ? (string)$row['updated_at'] : '',
            'updated_by' => $row !== null ? (string)$row['updated_by'] : '',
            'csrf_token' => Security::csrfToken(),
        ];
    }

    private static function handleSave()
    {
        if (!Security::csrfVerify(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
            http_response_code(403);
            render('error', ['message' => 'CSRF token invalid, please reload the page and try again.']);
            return;
        }
        $key = isset($_POST['key']) ? trim((string)$_POST['key']) : '';
        if (!in_array($key, Config::dictionaryKeys(), true)) {
            self::shell('配置管理', 'config_list', array_merge(self::listData(), ['message' => 'unknown config key', 'error' => true]));
            return;
        }
        $raw = isset($_POST['config_value']) ? (string)$_POST['config_value'] : '';
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            self::shell('配置管理', 'config_edit', [
                'key' => $key,
                'description' => Config::dictionaryDescriptions()[$key],
                'json' => $raw,
                'count' => 0,
                'updated_at' => '',
                'updated_by' => '',
                'csrf_token' => Security::csrfToken(),
                'message' => 'invalid JSON, not saved',
                'error' => true,
            ]);
            return;
        }
        $now = date('Y-m-d H:i:s');
        $currentUser = Security::currentUser();
        $updatedBy = $currentUser !== null && isset($currentUser['username']) ? $currentUser['username'] : '';
        self::saveDictionary($key, $decoded, $now, $updatedBy);
        Config::reset();
        self::shell('配置管理', 'config_list', array_merge(self::listData(), ['message' => 'saved', 'error' => false]));
    }

    private static function shell($pageTitle, $view, array $data = [])
    {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        try {
            Security::ensureUidCookie();
            if (!Security::authValid()) {
                AuthController::handle();
                return;
            }
            Security::requirePermission('config.manage');
            render('layout_head', ['page_title' => $pageTitle]);
            render('header');
            render($view, $data);
            render('footer');
            render('layout_tail');
        } catch (\Throwable $e) {
            renderErrorPage($e);
        }
    }
}
