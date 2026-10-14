<?php

namespace App\Controllers;

use App\Repositories\KeywordRepository;
use App\Repositories\SiteRepository;
use App\Repositories\TopicRepository;
use App\Services\ExportService;
use App\Services\ReportService;
use App\Support\Security;

class ReportController
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
            Security::requirePermission('report.view');
            $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
            if ($method === 'POST' && isset($_POST['action'])) {
                if ($_POST['action'] === 'delete-site') {
                    self::handleSiteDelete();
                    return;
                }
                if ($_POST['action'] === 'delete-keyword') {
                    self::handleKeywordDelete();
                    return;
                }
                if ($_POST['action'] === 'import-site') {
                    self::handleSiteImport();
                    return;
                }
                if ($_POST['action'] === 'import-keyword') {
                    self::handleKeywordImport();
                    return;
                }
            }
            $data = self::handleGet();
            if ($data === null) {
                return;
            }
            $data['csrf_token'] = Security::csrfToken();
            render('layout_head', ['page_title' => 'SEO 报告']);
            render('header');
            render('report_table', $data);
            render('footer');
            render('layout_tail');
        } catch (\Throwable $e) {
            renderErrorPage($e);
        }
    }

    private static function handleKeywordDelete()
    {
        Security::requirePermission('keyword.manage');
        if (!Security::csrfVerify(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
            http_response_code(403);
            render('error', ['message' => 'CSRF token invalid, please reload the page and try again.']);
            return;
        }
        $ctxId = isset($_POST['ctx_id']) ? trim((string)$_POST['ctx_id']) : '';
        if ($ctxId === '' || KeywordRepository::byCtxId($ctxId) === null) {
            self::emitJson(['ok' => false, 'message' => 'keyword not found']);
            return;
        }
        KeywordRepository::deleteByCtxId($ctxId);
        \App\Services\KeywordService::export();
        self::emitJson(['ok' => true, 'message' => 'deleted']);
    }

    private static function handleSiteDelete()
    {
        Security::requirePermission('site.manage');
        if (!Security::csrfVerify(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
            http_response_code(403);
            render('error', ['message' => 'CSRF token invalid, please reload the page and try again.']);
            return;
        }
        $ctxId = isset($_POST['ctx_id']) ? trim((string)$_POST['ctx_id']) : '';
        if ($ctxId === '' || SiteRepository::findByCtxId($ctxId) === null) {
            self::emitJson(['ok' => false, 'message' => 'site not found']);
            return;
        }
        SiteRepository::deleteByCtxId($ctxId);
        ExportService::export();
        self::emitJson(['ok' => true, 'message' => 'deleted']);
    }

    private static function handleSiteImport()
    {
        Security::requirePermission('site.manage');
        if (!Security::csrfVerify(Security::requestToken())) {
            self::emitJson(['ok' => false, 'message' => 'CSRF token invalid']);
            return;
        }
        $file = \App\Config::dataDir() . '/siteops_setting.txt';
        if (!is_file($file)) {
            self::emitJson(['ok' => false, 'message' => 'siteops_setting.txt 不存在']);
            return;
        }
        $lines = array_filter(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), function ($l) { return trim($l) !== ''; });
        $records = [];
        $failed = 0;
        $legacy = 0;
        foreach ($lines as $line) {
            // 兼容 12 列现行布局与 11 列旧版布局（缺 git_account，从 json 补回）
            $row = \App\Services\SiteService::parseSettingLine($line);
            if ($row === null) { $failed++; continue; }
            if ($row['legacy']) { $legacy++; }
            $json = $row['decoded'];
            $records[] = [
                'ctx_id' => $row['ctx_id'],
                'git_name' => $row['git_name'],
                'git_account' => $row['git_account'],
                'status' => $row['status'],
                'theme_type' => $row['theme_type'],
                'languages' => $row['languages'],
                'domain' => $row['domain'],
                'sns_id' => $row['sns_id'],
                'topnav_menus' => $row['topnav_menus'],
                'site_title' => $row['site_title'],
                'site_subtitle' => $row['site_subtitle'],
                'keyword' => $json['keyword'] ?? '',
                'theme_name' => $json['theme_name'] ?? $row['git_name'],
                'sitedir' => $json['sitedir'] ?? '',
                'deploy' => $json['deploy'] ?? '',
                'hostip' => $json['hostip'] ?? '',
                'local_deploy' => $json['local_deploy'] ?? '',
                'local_hostip' => $json['local_hostip'] ?? '',
                'site_logo' => $json['site_logo'] ?? '',
                'json' => $row['json'],
            ];
        }
        // 全量失败时不导出：否则会用当前库状态覆盖掉用户的源文件，且不可恢复
        if (count($records) === 0) {
            self::emitJson(['ok' => false, 'message' => "站点导入失败：{$failed} 行全部无法解析（列数或 json 非法），已跳过导出以保护源文件"]);
            return;
        }
        $result = SiteRepository::batchImport($records);
        $result['failed'] += $failed;
        ExportService::export();
        $legacyNote = $legacy > 0 ? "（其中 {$legacy} 行为旧版 11 列格式，git_account 取自 json）" : '';
        self::emitJson(['ok' => true, 'message' => "站点导入完成：新增 {$result['imported']} 条，已存在更新 {$result['skipped']} 条，失败 {$result['failed']} 条{$legacyNote}"]);
    }

    private static function handleKeywordImport()
    {
        Security::requirePermission('keyword.manage');
        if (!Security::csrfVerify(Security::requestToken())) {
            self::emitJson(['ok' => false, 'message' => 'CSRF token invalid']);
            return;
        }
        $file = \App\Config::dataDir() . '/keyword_monitor_list.txt';
        if (!is_file($file)) {
            self::emitJson(['ok' => false, 'message' => 'keyword_monitor_list.txt 不存在']);
            return;
        }
        $lines = array_filter(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), function ($l) { return trim($l) !== ''; });
        $records = [];
        $failed = 0;
        foreach ($lines as $line) {
            // 按列数严格分派布局，列数或 json 非法直接判失败
            $row = \App\Services\KeywordService::parseMonitorLine($line);
            if ($row === null) { $failed++; continue; }
            $json = $row['decoded'];
            $records[] = [
                'ctx_id' => $row['ctx_id'],
                'keyword' => $row['keyword'],
                'status' => $row['status'],
                'git_name' => $row['git_name'],
                'pubdir' => $row['pubdir'],
                'lang' => $row['lang'],
                'geo' => $json['geo'] ?? '',
                'lasttask' => $json['lasttask'] ?? '',
                'json' => $row['json'],
            ];
        }
        // 全量失败时不导出：否则会用当前库状态覆盖掉用户的源文件，且不可恢复
        if (count($records) === 0) {
            self::emitJson(['ok' => false, 'message' => "关键词导入失败：{$failed} 行全部无法解析（列数或 json 非法），已跳过导出以保护源文件"]);
            return;
        }
        $result = KeywordRepository::batchImport($records);
        $result['failed'] += $failed;
        \App\Services\KeywordService::export();
        self::emitJson(['ok' => true, 'message' => "关键词导入完成：新增 {$result['imported']} 条，已存在更新 {$result['skipped']} 条，失败 {$result['failed']} 条"]);
    }

    private static function handleGet()
    {
        list($page, $perPage) = self::pageParams();
        $search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
        $sort = isset($_GET['sort']) ? (string)$_GET['sort'] : '';
        $order = isset($_GET['order']) ? (string)$_GET['order'] : 'desc';
        $type = isset($_GET['reporttype']) ? (string)$_GET['reporttype'] : 'wordlist';

        list($title, $columns) = self::columnsFor($type);
        $isJson = isset($_GET['format']) && $_GET['format'] === 'json';

        switch ($type) {
            case 'relateword':
                $rows = ReportService::relateword();
                $rows = self::filterSortSlice($rows, ['createtime', 'subword', 'status', 'domain', 'pubdir', 'lang', 'mainword'], $search, $sort, $order, $page, $perPage);
                break;

            case 'sitelist':
                $rows = SiteRepository::search($search, $page, $perPage, $sort !== '' ? $sort : 'id', $order);
                $total = $rows['total'];
                $rows = $rows['rows'];
                if ($isJson) {
                    self::emitJson(['total' => $total, 'rows' => $rows]);
                    return null;
                }
                return [
                    'type' => $type,
                    'title' => $title,
                    'columns' => $columns,
                    'total' => $total,
                    'csrf_token' => Security::csrfToken(),
                ];

            case 'topiclist':
                $rows = TopicRepository::search($search, $page, $perPage, $sort !== '' ? $sort : 'id', $order);
                $total = $rows['total'];
                $rows = $rows['rows'];
                if ($isJson) {
                    self::emitJson(['total' => $total, 'rows' => $rows]);
                    return null;
                }
                return [
                    'type' => $type,
                    'title' => $title,
                    'columns' => $columns,
                    'total' => $total,
                ];

            case 'wordlist':
            default:
                $rows = KeywordRepository::search($search, $page, $perPage, $sort !== '' ? $sort : 'id', $order);
                $total = $rows['total'];
                $rows = $rows['rows'];
                if ($isJson) {
                    self::emitJson(['total' => $total, 'rows' => $rows]);
                    return null;
                }
                return [
                    'type' => $type,
                    'title' => $title,
                    'columns' => $columns,
                    'total' => $total,
                    'csrf_token' => Security::csrfToken(),
                ];
        }

        if ($isJson) {
            self::emitJson($rows);
            return null;
        }
        return [
            'type' => $type,
            'title' => $title,
            'columns' => $columns,
            'total' => $rows['total'],
        ];
    }

    private static function columnsFor($type)
    {
        switch ($type) {
            case 'relateword':
                return ['关联关键词', [
                    ['field' => 'createtime', 'title' => '创建时间', 'sortable' => true],
                    ['field' => 'lang', 'title' => '语言', 'sortable' => true],
                    ['field' => 'mainword', 'title' => '关键词', 'sortable' => true],
                    ['field' => 'subword', 'title' => '关联词', 'sortable' => true],
                ]];

            case 'sitelist':
                return ['站点数据', [
                    ['field' => 'git_name', 'title' => '代码库名', 'sortable' => true],
                    ['field' => 'status', 'title' => '状态', 'sortable' => true],
                    ['field' => 'theme_type', 'title' => '建站模板', 'sortable' => true],
                    ['field' => 'languages', 'title' => '语言', 'sortable' => true],
                    ['field' => 'domain', 'title' => '域名', 'sortable' => true],
                    ['field' => 'site_title', 'title' => '站点名', 'sortable' => true],
                    ['field' => 'site_subtitle', 'title' => '站点描述', 'sortable' => true],
                    ['field' => '_edit', 'title' => '操作', 'align' => 'center', 'width' => 150, 'formatter' => 'siteEditer', 'class' => 'op-col'],
                ]];

            case 'topiclist':
                return ['话题列表', [
                    ['field' => 'keyword', 'title' => '话题', 'sortable' => true],
                    ['field' => 'status', 'title' => '状态', 'sortable' => true],
                    ['field' => 'git_name', 'title' => '代码库名', 'sortable' => true],
                    ['field' => 'domain', 'title' => '域名', 'sortable' => true],
                    ['field' => 'pubdir', 'title' => '发布目录', 'sortable' => true],
                    ['field' => 'lang', 'title' => '语言', 'sortable' => true],
                    ['field' => 'lasttask', 'title' => '最近采集', 'sortable' => true],
                    ['field' => '_edit', 'title' => '编辑', 'align' => 'center', 'width' => 110, 'formatter' => 'topicEditer', 'class' => 'op-col'],
                ]];

            case 'wordlist':
            default:
                return ['全部关键词', [
                    ['field' => 'keyword', 'title' => '关键词', 'sortable' => true],
                    ['field' => 'status', 'title' => '状态', 'sortable' => true],
                    ['field' => 'git_name', 'title' => '代码库名', 'sortable' => true],
                    ['field' => 'pubdir', 'title' => '发布目录', 'sortable' => true],
                    ['field' => 'lang', 'title' => '语言', 'sortable' => true],
                    ['field' => 'lasttask', 'title' => '最近采集', 'sortable' => true],
                    ['field' => '_edit', 'title' => '操作', 'align' => 'center', 'width' => 150, 'formatter' => 'keywordEditer', 'class' => 'op-col'],
                ]];
        }
    }

    private static function filterSortSlice(array $rows, array $sortable, $search, $sort, $order, $page, $perPage)
    {
        $rows = array_values($rows);
        if ($search !== '') {
            $rows = array_values(array_filter($rows, function ($row) use ($search) {
                foreach ($row as $value) {
                    if (stripos((string)$value, $search) !== false) {
                        return true;
                    }
                }
                return false;
            }));
        }
        if (in_array($sort, $sortable, true)) {
            $dir = strtolower($order) === 'asc' ? 1 : -1;
            usort($rows, function ($a, $b) use ($sort, $dir) {
                $cmp = strcmp((string)$a[$sort], (string)$b[$sort]);
                return $cmp * $dir;
            });
        }
        return [
            'rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'total' => count($rows),
        ];
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
        $payload = [];
        if (array_key_exists('total', $result) && array_key_exists('rows', $result)) {
            $payload = ['total' => $result['total'], 'rows' => array_values($result['rows'])];
        } else {
            $payload = $result;
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    private static function renderError(\Throwable $e)
    {
        error_log('[seo_report] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(500);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Error</title></head>'
            . '<body><h1>Internal Server Error</h1><p>' . e($e->getMessage()) . '</p></body></html>';
    }
}