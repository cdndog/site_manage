<?php

namespace App\Controllers;

use App\Config;
use App\Repositories\KeywordRepository;
use App\Repositories\SiteRepository;
use App\Services\KeywordService;
use App\Support\Logger;
use App\Support\Security;

class KeywordController
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
            Security::requirePermission('keyword.manage');
            render('layout_head', ['page_title' => '关键词配置']);
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

    public static function dispatchCheck()
    {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        try {
            Security::requireApiToken(true);
            Security::requirePermission('keyword.view');
            $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
            if ($method === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete-missing-keywords') {
                self::handleMissingKeywordsDelete();
                return;
            }
            $data = self::checkData();
            if ($data === null) {
                return;
            }
            self::shellCheck('关键词排查', 'keyword_check', $data);
        } catch (\Throwable $e) {
            renderErrorPage($e);
        }
    }

    private static function handleMissingKeywordsDelete()
    {
        Security::requirePermission('keyword.manage');
        if (!Security::csrfVerify(Security::requestToken())) {
            self::emitJson(['total' => 0, 'rows' => [['ok' => false, 'message' => 'CSRF token invalid']]]);
            return;
        }
        $keyword = isset($_POST['keyword']) ? trim((string)$_POST['keyword']) : '';
        $gitName = isset($_POST['git_name']) ? trim((string)$_POST['git_name']) : '';
        $deleted = KeywordRepository::deleteMissingSite('', $keyword, $gitName);
        $filters = [];
        if ($keyword !== '') {
            $filters[] = '关键词=' . $keyword;
        }
        if ($gitName !== '') {
            $filters[] = 'GIT=' . $gitName;
        }
        $scope = count($filters) > 0 ? implode('，', $filters) : '全部异常关键词';
        if ($deleted === 0) {
            self::emitJson(['total' => 0, 'rows' => [[
                'ok' => false,
                'message' => "未删除任何数据（{$scope}）：没有符合条件的异常关键词",
                'deleted' => 0,
            ]]]);
            return;
        }
        try {
            KeywordService::export();
        } catch (\Throwable $e) {
            // 导出失败不影响已完成的删除
        }
        self::emitJson(['total' => 1, 'rows' => [[
            'ok' => true,
            'message' => "已删除 {$deleted} 条异常关键词（{$scope}）",
            'deleted' => $deleted,
        ]]]);
    }

    private static function checkData()
    {
        $search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
        $keyword = isset($_GET['keyword']) ? trim((string)$_GET['keyword']) : '';
        $gitName = isset($_GET['git_name']) ? trim((string)$_GET['git_name']) : '';
        if (isset($_GET['format']) && $_GET['format'] === 'json') {
            list($page, $perPage) = self::pageParams(1000, 20);
            $sort = isset($_GET['sort']) ? (string)$_GET['sort'] : 'id';
            $order = isset($_GET['order']) ? (string)$_GET['order'] : 'desc';
            $result = KeywordRepository::searchMissingSite($search, $page, $perPage, $sort, $order, $keyword, $gitName);
            if (!headers_sent()) {
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: no-cache');
                header('Expires: 0');
            }
            self::emitJson($result);
            return null;
        }
        if (Security::csrfUid() === '') {
            $uid = Security::randomToken();
            if (!headers_sent()) {
                setcookie(Security::UID_COOKIE, $uid, 0, '/', '', false, true);
            }
            $_COOKIE[Security::UID_COOKIE] = $uid;
        }
        return [
            'search' => $search,
            'keyword' => $keyword,
            'git_name' => $gitName,
            'can_manage' => Security::can('keyword.manage'),
            'csrf_token' => Security::csrfToken(),
        ];
    }

    private static function pageParams($maxPerPage = 200, $defaultPerPage = 20)
    {
        if (isset($_GET['offset']) || isset($_GET['limit'])) {
            $perPage = isset($_GET['limit']) ? (int)$_GET['limit'] : $defaultPerPage;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            $page = floor($offset / max(1, $perPage)) + 1;
        } else {
            $page = isset($_GET['pageNumber']) ? (int)$_GET['pageNumber'] : 1;
            $perPage = isset($_GET['pageSize']) ? (int)$_GET['pageSize'] : $defaultPerPage;
        }
        $page = max(1, $page);
        $perPage = max(1, min($maxPerPage, $perPage));
        return [$page, $perPage];
    }

    private static function emitJson(array $result)
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['total' => $result['total'], 'rows' => array_values($result['rows'])], JSON_UNESCAPED_UNICODE);
    }

    private static function shellCheck($pageTitle, $view, array $data = [])
    {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        try {
            Security::ensureUidCookie();
            if (!Security::requireSession()) {
                return;
            }
            Security::requirePermission('keyword.view');
            render('layout_head', ['page_title' => $pageTitle]);
            render('header');
            render($view, $data);
            render('footer');
            render('layout_tail');
        } catch (\Throwable $e) {
            renderErrorPage($e);
        }
    }

    private static function handleGet()
    {
        $sites = SiteRepository::all();
        $config = Config::all();
        $form = KeywordService::defaultForm();
        $eid = isset($_GET['eid']) ? trim((string)$_GET['eid']) : '';
        if ($eid !== '') {
            $record = KeywordRepository::byCtxId($eid);
            if ($record !== null) {
                $form = [
                    'ctx_id' => $record['ctx_id'],
                    'post_keyword' => (string)$record['keyword'],
                    'post_gitname' => (string)$record['git_name'],
                    'post_lang' => (string)$record['lang'],
                    'post_geo' => (string)$record['geo'],
                    'post_pubdir' => (string)$record['pubdir'],
                    'post_status' => (string)$record['status'],
                    'post_bulkkeyword' => '',
                ];
            }
        }
        render('keyword_form', [
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
        $post = KeywordService::sanitizePost($_POST);
        $records = KeywordService::buildRecords($post);
        if (count($records) === 0) {
            http_response_code(400);
            render('error', ['message' => 'keyword is empty.']);
            render('footer');
            render('layout_tail');
            return;
        }
        $records = KeywordService::saveAll($records);
        foreach ($records as $record) {
            KeywordService::saveBackup($record);
            Logger::auditKeyword(
                isset($record['keyword']) ? $record['keyword'] : '',
                isset($record['git_name']) ? $record['git_name'] : '',
                isset($record['status']) ? $record['status'] : ''
            );
        }
        KeywordService::export();
        $gitName = isset($records[0]['git_name']) ? $records[0]['git_name'] : '';
        render('keyword_confirm', [
            'records' => $records,
            'keywords' => $gitName !== '' ? KeywordRepository::byGitName($gitName) : [],
        ]);
        render('footer');
        render('layout_tail');
    }

    private static function renderError(\Throwable $e)
    {
        error_log('[keywordops] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(500);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Error</title></head>'
            . '<body><h1>Internal Server Error</h1><p>' . e($e->getMessage()) . '</p></body></html>';
    }
}
