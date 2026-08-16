<?php

namespace App\Controllers;

use App\Config;
use App\Repositories\SiteRepository;
use App\Repositories\TopicRepository;
use App\Services\TopicService;
use App\Support\Logger;
use App\Support\Security;

class TopicController
{
    public static function dispatch()
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            return;
        }
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
            Security::requirePermission('topic.manage');
            render('layout_head', ['page_title' => '话题录入']);
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
        $sites = SiteRepository::all();
        $config = Config::all();
        $form = TopicService::defaultForm();
        $eid = isset($_GET['eid']) ? trim((string)$_GET['eid']) : '';
        if ($eid !== '') {
            $record = TopicRepository::byCtxId($eid);
            if ($record !== null) {
                $form = [
                    'ctx_id' => $record['ctx_id'],
                    'post_keyword' => (string)$record['keyword'],
                    'post_gitname' => (string)$record['git_name'],
                    'post_domain' => (string)$record['domain'],
                    'post_lang' => (string)$record['lang'],
                    'post_geo' => (string)$record['geo'],
                    'post_pubdir' => (string)$record['pubdir'],
                    'post_status' => (string)$record['status'],
                    'post_bulkkeyword' => '',
                ];
            }
        }
        render('topic_form', [
            'form' => $form,
            'config' => $config,
            'sites' => $sites,
            'csrf_token' => Security::csrfToken(),
        ]);
    }

    private static function handlePost()
    {
        if (!isset($_POST['setupNum']) || $_POST['setupNum'] !== 'ckeditorFormated') {
            return;
        }
        if (!Security::csrfVerify(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
            http_response_code(403);
            render('error', ['message' => 'CSRF token invalid, please reload the page and try again.']);
            return;
        }
        $post = TopicService::sanitizePost($_POST);
        $records = TopicService::buildRecords($post);
        if (count($records) === 0) {
            http_response_code(400);
            render('error', ['message' => 'keyword is empty.']);
            return;
        }
        $records = TopicService::saveAll($records);
        foreach ($records as $record) {
            TopicService::saveBackup($record);
            Logger::auditTopic(
                isset($record['keyword']) ? $record['keyword'] : '',
                isset($record['git_name']) ? $record['git_name'] : '',
                isset($record['status']) ? $record['status'] : ''
            );
        }
        TopicService::export();
        $gitName = isset($records[0]['git_name']) ? $records[0]['git_name'] : '';
        render('topic_confirm', [
            'records' => $records,
            'topics' => $gitName !== '' ? TopicRepository::byGitName($gitName) : [],
        ]);
    }

    public static function dispatchList()
    {
        Security::requireApiToken(true);
        Security::requirePermission('topic.view');
        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        if ($method === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete-topic') {
            self::handleTopicDelete();
            return;
        }
        $data = self::listData();
        if ($data === null) {
            return;
        }
        self::shell('话题列表', 'topic_list', $data);
    }

    private static function handleTopicDelete()
    {
        Security::requirePermission('topic.manage');
        if (!Security::csrfVerify(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
            http_response_code(403);
            render('error', ['message' => 'CSRF token invalid, please reload the page and try again.']);
            return;
        }
        $ctxId = isset($_POST['ctx_id']) ? trim((string)$_POST['ctx_id']) : '';
        if ($ctxId === '' || TopicRepository::byCtxId($ctxId) === null) {
            self::emitJson(['total' => 0, 'rows' => [['ok' => false, 'message' => 'topic not found']]]);
            return;
        }
        TopicRepository::deleteByCtxId($ctxId);
        TopicService::export();
        self::emitJson(['total' => 1, 'rows' => [['ok' => true, 'message' => 'deleted']]]);
    }

    public static function dispatchTable()
    {
        Security::requireApiToken(true);
        Security::requirePermission('topic.view');
        $data = self::tableData();
        if ($data === null) {
            return;
        }
        self::shell('话题报表', 'topic_table', $data);
    }

    private static function listData()
    {
        list($page, $perPage) = self::pageParams();
        $search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
        $sort = isset($_GET['sort']) ? (string)$_GET['sort'] : 'id';
        $order = isset($_GET['order']) ? (string)$_GET['order'] : 'desc';
        $result = TopicRepository::search($search, $page, $perPage, $sort, $order);
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
        ];
    }

    private static function tableData()
    {
        list($page, $perPage) = self::pageParams();
        $search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
        $sort = isset($_GET['sort']) ? (string)$_GET['sort'] : '';
        $order = isset($_GET['order']) ? (string)$_GET['order'] : 'desc';
        $view = isset($_GET['view']) ? (string)$_GET['view'] : 'detail';
        $summary = null;
        if ($view === 'date' || $view === 'domain') {
            $summary = TopicRepository::summarize();
            $rows = self::summaryRows($view, $summary, $search, $sort, $order);
            $total = count($rows);
            $rows = array_slice($rows, ($page - 1) * $perPage, $perPage);
        } else {
            $rows = TopicRepository::search($search, $page, $perPage, $sort !== '' ? $sort : 'id', $order);
            $total = $rows['total'];
            $rows = $rows['rows'];
        }
        if (isset($_GET['format']) && $_GET['format'] === 'json') {
            self::emitJson(['total' => $total, 'rows' => $rows]);
            return null;
        }
        if ($summary === null) {
            $summary = TopicRepository::summarize();
        }
        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'search' => $search,
            'view' => $view,
            'by_status' => $summary['by_status'],
            'by_domain' => $summary['by_domain'],
            'by_date' => $summary['by_date'],
        ];
    }

    private static function summaryRows($view, array $summary, $search, $sort, $order)
    {
        $keyField = $view === 'date' ? 'date' : 'domain';
        $source = $view === 'date' ? $summary['by_date'] : $summary['by_domain'];
        $rows = [];
        foreach ($source as $key => $stat) {
            $rate = $stat['total'] > 0 ? (int)round($stat['aidone'] / $stat['total'] * 100) : 0;
            $rows[] = [
                $keyField => (string)$key,
                'total' => $stat['total'],
                'aidone' => $stat['aidone'],
                'enable' => $stat['enable'],
                'other' => $stat['other'],
                'rate' => $rate,
            ];
        }
        if ($search !== '') {
            $rows = array_values(array_filter($rows, function ($row) use ($keyField, $search) {
                return stripos($row[$keyField], $search) !== false;
            }));
        }
        if (in_array($sort, [$keyField, 'total', 'aidone', 'enable', 'other', 'rate'], true)) {
            $dir = strtolower($order) === 'asc' ? 1 : -1;
            usort($rows, function ($a, $b) use ($sort, $dir) {
                $cmp = is_numeric($a[$sort]) && is_numeric($b[$sort])
                    ? $a[$sort] <=> $b[$sort]
                    : strcmp((string)$a[$sort], (string)$b[$sort]);
                return $cmp * $dir;
            });
        }
        return array_values($rows);
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
            Security::requirePermission('topic.view');
            render('layout_head', ['page_title' => $pageTitle]);
            render('header');
            render($view, $data);
            render('footer');
            render('layout_tail');
        } catch (\Throwable $e) {
            renderErrorPage($e);
        }
    }

    private static function renderError(\Throwable $e)
    {
        error_log('[topicops] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(500);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Error</title></head>'
            . '<body><h1>Internal Server Error</h1><p>' . e($e->getMessage()) . '</p></body></html>';
    }
}