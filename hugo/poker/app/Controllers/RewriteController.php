<?php

namespace App\Controllers;

use App\Config;
use App\Repositories\SiteRepository;
use App\Services\RewriteService;
use App\Support\Logger;
use App\Support\Security;

class RewriteController
{
    public static function dispatch()
    {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        try {
            Security::requireApiToken(true);
            Security::ensureUidCookie();
            if (!Security::requireSession()) {
                return;
            }
            Security::requirePermission('rewrite.view');
            render('layout_head', [
                'page_title' => '改写管理',
                'extra_head' => '<link rel="stylesheet" href="js/ckeditor5/ckeditor5.css">',
            ]);
            render('header');
            $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
            if ($method === 'POST') {
                self::handlePost();
            } else {
                self::handleGet();
            }
        } catch (\Throwable $e) {
            renderErrorPage($e);
        }
    }

    private static function handleGet()
    {
        $config = Config::all();
        $sites = SiteRepository::all();
        $form = RewriteService::defaultForm();
        $eid = isset($_GET['eid']) ? trim((string)$_GET['eid']) : '';
        if ($eid !== '') {
            $record = RewriteService::byCtxId($eid);
            if ($record !== null) {
                $form = RewriteService::formFromRecord($record);
            }
        }
        render('rewrite_form', [
            'form' => $form,
            'config' => $config,
            'sites' => $sites,
            'csrf_token' => Security::csrfToken(),
        ]);
        render('footer');
        render('layout_tail');
    }

    private static function handlePost()
    {
        Security::requirePermission('rewrite.manage');
        if (!isset($_POST['setupNum']) || $_POST['setupNum'] !== 'ckeditorFormated') {
            render('footer');
            render('layout_tail');
            return;
        }
        if (!Security::csrfVerify(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
            http_response_code(403);
            render('error', ['message' => 'CSRF token invalid, please reload the page and try again.']);
            render('footer');
            render('layout_tail');
            return;
        }
        $post = RewriteService::sanitizePost($_POST);
        $ctxId = isset($post['post_uuid']) ? trim((string)$post['post_uuid']) : '';
        $existing = $ctxId !== '' ? RewriteService::byCtxId($ctxId) : null;
        $record = RewriteService::buildRecord($post, $existing);
        if ($record === null) {
            http_response_code(400);
            render('error', ['message' => 'keyword and git_name are required.']);
            render('footer');
            render('layout_tail');
            return;
        }
        RewriteService::saveRecord($record);
        Logger::auditTopic($record['keyword'], $record['git_name'], $record['status']);
        render('rewrite_confirm', [
            'record' => $record,
            'file_path' => 'sitetopic (status=rewrite)',
        ]);
        render('footer');
        render('layout_tail');
    }

    public static function dispatchList()
    {
        try {
            Security::requireApiToken(true);
            Security::requirePermission('rewrite.view');
            $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
            if ($method === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete-rewrite') {
                self::handleDelete();
                return;
            }
            if ($method === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import-articles') {
                self::handleImportArticles();
                return;
            }
            $data = self::listData();
            if ($data === null) {
                return;
            }
            self::shell('改写列表', 'rewrite_list', $data);
        } catch (\Throwable $e) {
            renderErrorPage($e);
        }
    }

    private static function handleDelete()
    {
        Security::requirePermission('rewrite.manage');
        if (!Security::csrfVerify(Security::requestToken())) {
            self::emitJson(['total' => 0, 'rows' => [['ok' => false, 'message' => 'CSRF token invalid']]]);
            return;
        }
        $ctxId = isset($_POST['ctx_id']) ? trim((string)$_POST['ctx_id']) : '';
        if ($ctxId === '' || RewriteService::byCtxId($ctxId) === null) {
            self::emitJson(['total' => 0, 'rows' => [['ok' => false, 'message' => 'rewrite not found']]]);
            return;
        }
        if (!RewriteService::deleteByCtxId($ctxId)) {
            self::emitJson(['total' => 0, 'rows' => [['ok' => false, 'message' => 'delete failed']]]);
            return;
        }
        self::emitJson(['total' => 1, 'rows' => [['ok' => true, 'message' => 'deleted']]]);
    }

    private static function listData()
    {
        list($page, $perPage) = self::pageParams();
        $search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
        $sort = isset($_GET['sort']) ? (string)$_GET['sort'] : 'ctx_id';
        $order = isset($_GET['order']) ? (string)$_GET['order'] : 'desc';
        $result = RewriteService::search($search, $page, $perPage, $sort, $order);
        $importFiles = self::importFileList();
        $sites = SiteRepository::all();
        foreach ($sites as &$site) {
            list($site['rewrite_lang'], $site['rewrite_geo']) = RewriteService::siteLanguageGeo($site);
        }
        unset($site);
        $payload = [
            'total' => $result['total'],
            'rows' => $result['rows'],
            'page' => $page,
            'per_page' => $perPage,
            'search' => $search,
            'csrf_token' => Security::csrfToken(),
            'can_manage' => Security::can('rewrite.manage'),
            'import_files' => $importFiles,
            'import_default_path' => count($importFiles) > 0 ? $importFiles[0] : (string)Config::baseKey('rewrite_article_list', ''),
            'sites' => $sites,
            'import_languages' => Config::get('languages', []),
            'import_countries' => Config::get('countries', []),
        ];
        if (isset($_GET['format']) && $_GET['format'] === 'json') {
            self::emitJson($payload);
            return null;
        }
        return $payload;
    }

    private static function importFileList()
    {
        $files = Config::baseKey('rewrite_files', null);
        if (!is_array($files)) {
            return [];
        }
        $names = [];
        foreach ($files as $file) {
            $name = trim((string)$file);
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        return $names;
    }

    private static function handleImportArticles()
    {
        Security::requirePermission('rewrite.manage');
        if (!Security::csrfVerify(Security::requestToken())) {
            self::emitJson(['total' => 1, 'rows' => [['ok' => false, 'message' => 'CSRF token invalid']]]);
            return;
        }
        $path = isset($_POST['import_path']) ? trim((string)$_POST['import_path']) : '';
        if ($path === '') {
            self::emitJson(['total' => 1, 'rows' => [['ok' => false, 'message' => '请填写文章列表文件路径']]]);
            return;
        }
        $publishDomain = isset($_POST['publish_domain']) ? trim((string)$_POST['publish_domain']) : '';
        $importLang = isset($_POST['import_lang']) ? trim((string)$_POST['import_lang']) : '';
        $importGeo = isset($_POST['import_geo']) ? trim((string)$_POST['import_geo']) : '';
        $result = RewriteService::importFromArticleList($path, SiteRepository::all(), $publishDomain, $importLang, $importGeo);
        if (isset($result['error'])) {
            self::emitJson(['total' => 1, 'rows' => [['ok' => false, 'message' => $result['error']]]]);
            return;
        }
        $message = '导入完成：共 ' . $result['total'] . ' 行，新增 ' . $result['imported'] . ' 条，更新 ' . $result['updated'] . ' 条，跳过 ' . $result['skipped'] . ' 条，失败 ' . $result['failed'] . ' 条';
        if (!empty($result['publish_domain'])) {
            $message .= '；发布到 ' . $result['publish_domain'] . '(' . (isset($result['publish_git_name']) ? $result['publish_git_name'] : '') . ')';
        }
        if (!empty($result['import_lang']) || !empty($result['import_geo'])) {
            $message .= '；优化语言 ' . (!empty($result['import_lang']) ? $result['import_lang'] : '-') . '，地区 ' . (!empty($result['import_geo']) ? $result['import_geo'] : '-');
        }
        if (count($result['errors']) > 0) {
            $message .= '；' . implode('；', array_slice($result['errors'], 0, 5));
            if (count($result['errors']) > 5) {
                $message .= '；等 ' . count($result['errors']) . ' 个错误';
            }
        }
        self::emitJson(['total' => 1, 'rows' => [[
            'ok' => $result['failed'] === 0,
            'message' => $message,
            'imported' => $result['imported'],
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
            'failed' => $result['failed'],
        ]]]);
    }

    private static function pageParams()
    {
        if (isset($_GET['offset']) || isset($_GET['limit'])) {
            $perPage = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            $page = floor($offset / max(1, $perPage)) + 1;
        } else {
            $page = isset($_GET['pageNumber']) ? (int)$_GET['pageNumber'] : 1;
            $perPage = isset($_GET['pageSize']) ? (int)$_GET['pageSize'] : 20;
        }
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        return [$page, $perPage];
    }

    private static function emitJson(array $result)
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['total' => $result['total'], 'rows' => array_values($result['rows'])], JSON_UNESCAPED_UNICODE);
    }

    private static function shell($pageTitle, $view, array $data = [])
    {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        Security::ensureUidCookie();
        if (!Security::requireSession()) {
            return;
        }
        Security::requirePermission('rewrite.view');
        render('layout_head', ['page_title' => $pageTitle]);
        render('header');
        render($view, $data);
        render('footer');
        render('layout_tail');
    }
}
