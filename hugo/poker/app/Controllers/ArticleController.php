<?php

namespace App\Controllers;

use App\Config;
use App\Repositories\ArticleRepository;
use App\Repositories\SiteRepository;
use App\Services\ArticleService;
use App\Support\Logger;
use App\Support\Security;

class ArticleController
{
    public static function dispatch()
    {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        try {
            Security::requireApiToken(true);
            Security::ensureUidCookie();
            if (!Security::authValid() && !Security::isGitServerIp()) {
                AuthController::handle();
                return;
            }
            Security::requirePermission('article.manage');
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'site_list') {
                self::handleSiteList();
                return;
            }
            render('layout_head', ['page_title' => '新建文章', 'extra_head' => '<link rel="stylesheet" href="js/ckeditor5/ckeditor5.css">']);
            render('header');
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                self::handlePost();
            } else {
                self::handleGet();
            }
            render('footer');
            render('layout_tail');
        } catch (\Throwable $e) {
            renderErrorPage($e);
        }
    }

    private static function handleGet()
    {
        $config = Config::all();
        $sites = SiteRepository::all();
        $form = ArticleService::defaultForm();
        $eid = isset($_GET['eid']) ? trim((string)$_GET['eid']) : '';
        if ($eid !== '') {
            $record = ArticleRepository::byCtxId($eid);
            if ($record !== null) {
                $form = [
                    'ctx_id' => (string)$record['ctx_id'],
                    'url' => (string)$record['url'],
                    'title' => (string)$record['title'],
                    'static_thumbnail' => (string)$record['static_thumbnail'],
                    'iframesrc' => (string)$record['iframesrc'],
                    'tags' => (string)$record['tags'],
                    'keyword' => (string)$record['keyword'],
                    'description' => (string)$record['description'],
                    'content' => (string)$record['content'],
                    'lang' => (string)$record['lang'],
                    'series' => (string)$record['series'],
                    'pubdir' => (string)$record['pubdir'],
                    'savename' => (string)$record['savename'],
                    'pubdomain' => $record['pubdomain'] !== '' ? explode(',', (string)$record['pubdomain']) : [],
                    'globalpublish' => (string)$record['globalpublish'],
                    'translate_to_langs' => $record['translate_to_langs'] !== '' ? explode(',', (string)$record['translate_to_langs']) : [],
                ];
            }
        }
        render('article_form', [
            'form' => $form,
            'config' => $config,
            'sites' => $sites,
            'csrf_token' => Security::csrfToken(),
        ]);
    }

    private static function handlePost()
    {
        if (isset($_POST['action']) && $_POST['action'] === 'site_list') {
            self::handleSiteList();
            return;
        }
        if (!isset($_POST['setupNum']) || $_POST['setupNum'] !== ArticleService::SETUP_MARKER) {
            return;
        }
        if (!Security::csrfVerify(Security::requestToken())) {
            http_response_code(403);
            render('error', ['message' => 'CSRF token invalid, please reload the page and try again.']);
            return;
        }
        $post = ArticleService::sanitizePost($_POST);
        $json = ArticleService::buildJson($post);
        $jsonFile = ArticleService::saveJsonFile($json);
        $record = ArticleService::toRecord($post, $json);
        if ($jsonFile !== null) {
            $record['json_file'] = ArticleService::jsonFileName($record['ctx_id']);
        }
        $record = ArticleRepository::upsertByCtxId($record);
        Logger::auditTopic(
            isset($record['title']) ? $record['title'] : '',
            isset($record['pubdomain']) ? $record['pubdomain'] : '',
            isset($record['globalpublish']) ? $record['globalpublish'] : ''
        );

        $publishMessages = [];
        if (($record['globalpublish'] ?? '') === 'yes') {
            $publishMessages = ArticleService::publish($json);
        }

        render('article_confirm', [
            'record' => $record,
            'json' => $json,
            'json_text' => json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'publish_messages' => $publishMessages,
        ]);
    }

    public static function dispatchList()
    {
        Security::requireApiToken(true);
        Security::requirePermission('article.view');
        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        if ($method === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete-article') {
            self::handleArticleDelete();
            return;
        }
        if ($method === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import-log') {
            self::handleLogImport();
            return;
        }
        $data = self::listData();
        if ($data === null) {
            return;
        }
        self::shell('文章列表', 'article_list', $data);
    }

    private static function handleSiteList()
    {
        if (!Security::csrfVerify(Security::requestToken())) {
            self::emitJson(['total' => 0, 'rows' => [['ok' => false, 'message' => 'CSRF token invalid']]]);
            return;
        }
        $list = [];
        foreach (SiteRepository::all() as $site) {
            $list[] = [
                'domain' => isset($site['domain']) ? (string)$site['domain'] : '',
                'languages' => isset($site['languages']) ? (string)$site['languages'] : '',
            ];
        }
        self::emitJson(['total' => count($list), 'rows' => $list]);
    }

    private static function handleArticleDelete()
    {
        Security::requirePermission('article.manage');
        if (!Security::csrfVerify(Security::requestToken())) {
            http_response_code(403);
            render('error', ['message' => 'CSRF token invalid, please reload the page and try again.']);
            return;
        }
        $ctxId = isset($_POST['ctx_id']) ? trim((string)$_POST['ctx_id']) : '';
        if ($ctxId === '' || ArticleRepository::byCtxId($ctxId) === null) {
            self::emitJson(['total' => 0, 'rows' => [['ok' => false, 'message' => 'article not found']]]);
            return;
        }
        ArticleRepository::deleteByCtxId($ctxId);
        self::emitJson(['total' => 1, 'rows' => [['ok' => true, 'message' => 'deleted']]]);
    }

    private static function handleLogImport()
    {
        Security::requirePermission('article.manage');
        if (!Security::csrfVerify(Security::requestToken())) {
            self::emitJson(['total' => 1, 'rows' => [['ok' => false, 'message' => 'CSRF token invalid']]]);
            return;
        }
        $logFiles = Config::baseKey('log_files', null);
        if (!is_array($logFiles) || $logFiles === []) {
            $logFiles = [(string)Config::baseKey('log_file', 'editor_poker_allpost_list.txt')];
        }
        $results = [];
        foreach ($logFiles as $logFile) {
            $results[] = ArticleService::importFromLogFile(APP_PATH . '/' . ltrim((string)$logFile, '/'));
        }
        $firstError = null;
        foreach ($results as $result) {
            if (isset($result['error'])) {
                $firstError = $result['error'];
                break;
            }
        }
        if ($firstError !== null) {
            self::emitJson(['total' => 1, 'rows' => [['ok' => false, 'message' => $firstError]]]);
            return;
        }
        $imported = 0;
        $skipped = 0;
        $missing = 0;
        $failed = 0;
        $rows = [];
        foreach ($results as $result) {
            $imported += $result['imported'];
            $skipped += $result['skipped'];
            $missing += $result['missing'];
            $failed += $result['failed'];
            $rows = array_merge($rows, $result['rows']);
        }
        $summary = '导入完成：文件 ' . count($results) . ' 个，新增 ' . $imported . ' 条，已存在跳过 ' . $skipped . ' 条，JSON 缺失跳过 ' . $missing . ' 条，失败 ' . $failed . ' 条';
        self::emitJson(['total' => count($rows), 'rows' => array_merge([['ok' => true, 'message' => $summary]], $rows)]);
    }

    private static function listData()
    {
        list($page, $perPage) = self::pageParams();
        $search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
        $sort = isset($_GET['sort']) ? (string)$_GET['sort'] : 'id';
        $order = isset($_GET['order']) ? (string)$_GET['order'] : 'desc';
        $result = ArticleRepository::search($search, $page, $perPage, $sort, $order);
        if (isset($_GET['format']) && $_GET['format'] === 'json') {
            self::emitJson($result);
            return null;
        }
        return [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
            'search' => $search,
            'csrf_token' => Security::csrfToken(),
            'can_manage' => Security::can('article.manage'),
            'log_files' => self::logFileList(),
        ];
    }

    private static function logFileList()
    {
        $logFiles = Config::baseKey('log_files', null);
        if (!is_array($logFiles) || $logFiles === []) {
            $logFiles = [(string)Config::baseKey('log_file', 'editor_poker_allpost_list.txt')];
        }
        $names = [];
        foreach ($logFiles as $logFile) {
            $name = (string)$logFile;
            if ($name !== '') {
                $names[] = basename($name);
            }
        }
        return $names;
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
        try {
            Security::ensureUidCookie();
            if (!Security::authValid() && !Security::isGitServerIp()) {
                AuthController::handle();
                return;
            }
            Security::requirePermission('article.view');
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
