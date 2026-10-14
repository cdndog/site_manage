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
        // 跨域 fetch（UiVision fetch Accept: */*）返回纯文本；同源表单提交（Accept: text/html）保持 HTML
        $isApiFetch = isset($_SERVER['HTTP_ORIGIN']) && strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') === false;
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isApiFetch) {
            header('Content-Type: text/plain; charset=utf-8');
        } else {
            header('Content-Type: text/html; charset=utf-8');
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        try {
            Security::requireApiToken(true);
            Security::ensureUidCookie();
            if (!Security::requireSession()) {
                return;
            }
            Security::requirePermission('topic.manage');
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'site_list') {
                self::handleSiteList();
                return;
            }
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
                'topnav_menus' => isset($site['topnav_menus']) ? (string)$site['topnav_menus'] : '',
            ];
        }
        self::emitJson(['total' => count($list), 'rows' => $list]);
    }

    public static function dispatchList()
    {
        try {
            Security::requireApiToken(true);
            Security::requirePermission('topic.view');
            $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
            if ($method === 'POST' && isset($_POST['action'])) {
                if ($_POST['action'] === 'delete-topic') {
                    self::handleTopicDelete();
                    return;
                }
                if ($_POST['action'] === 'delete-topics') {
                    self::handleTopicsDelete();
                    return;
                }
                if ($_POST['action'] === 'update-topics') {
                    self::handleTopicsUpdate();
                    return;
                }
                if ($_POST['action'] === 'import-topic') {
                    self::handleTopicImport();
                    return;
                }
            }
            $data = self::listData();
            if ($data === null) {
                return;
            }
            self::shell('话题列表', 'topic_list', $data);
        } catch (\Throwable $e) {
            renderErrorPage($e);
        }
    }

    private static function handleTopicImport()
    {
        Security::requirePermission('topic.manage');
        if (!Security::csrfVerify(Security::requestToken())) {
            self::emitJson(['total' => 1, 'rows' => [['ok' => false, 'message' => 'CSRF token invalid']]]);
            return;
        }
        $file = \App\Config::dataDir() . '/topic_monitor_list.txt';
        if (!is_file($file)) {
            self::emitJson(['total' => 1, 'rows' => [['ok' => false, 'message' => 'topic_monitor_list.txt 不存在']]]);
            return;
        }
        $lines = array_filter(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), function ($l) { return trim($l) !== ''; });
        $records = [];
        $failed = 0;
        $legacy = 0;
        foreach ($lines as $line) {
            // 兼容 8 列现行布局与 9 列旧版布局（旧版 geo 为独立列）
            $row = TopicService::parseMonitorLine($line);
            if ($row === null) { $failed++; continue; }
            if ($row['legacy']) { $legacy++; }
            $records[] = [
                'ctx_id' => $row['ctx_id'],
                'keyword' => $row['keyword'],
                'status' => $row['status'],
                'git_name' => $row['git_name'],
                'domain' => $row['domain'],
                'pubdir' => $row['pubdir'],
                'lang' => $row['lang'],
                'geo' => $row['geo'],
                'lasttask' => $row['lasttask'],
                'json' => $row['json'],
            ];
        }
        // 全量失败时不导出：否则会用当前库状态覆盖掉用户的源文件，且不可恢复
        if (count($records) === 0) {
            self::emitJson(['total' => 1, 'rows' => [['ok' => false, 'message' => "话题导入失败：{$failed} 行全部无法解析（列数或 json 非法），已跳过导出以保护源文件"]]]);
            return;
        }
        $result = TopicRepository::batchImport($records);
        $result['failed'] += $failed;
        TopicService::export();
        $legacyNote = $legacy > 0 ? "（其中 {$legacy} 行为旧版 9 列格式，geo 取自独立列）" : '';
        self::emitJson(['total' => 1, 'rows' => [['ok' => true, 'message' => "话题导入完成：新增 {$result['imported']} 条，已存在更新 {$result['skipped']} 条，失败 {$result['failed']} 条{$legacyNote}"]]]);
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
        try { TopicService::export(); } catch (\Throwable $e) { /* export fails silently */ }
        self::emitJson(['total' => 1, 'rows' => [['ok' => true, 'message' => 'deleted']]]);
    }

    /**
     * 解析批量操作的 ctx_ids：支持数组（ctx_ids[]=a&ctx_ids[]=b）或逗号分隔字符串，去空去重。
     */
    private static function parseCtxIds(): array
    {
        $raw = isset($_POST['ctx_ids']) ? $_POST['ctx_ids'] : '';
        $list = is_array($raw) ? $raw : explode(',', (string)$raw);
        $ids = [];
        foreach ($list as $item) {
            $id = trim((string)$item);
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
        return array_keys($ids);
    }

    /**
     * 批量删除：逐条校验存在后删除，最后统一导出一次（与单条删除行为一致，仅省略 N 次全量导出）。
     * 不存在的 id 不算失败，计入 missing 在 message 里说明。
     */
    private static function handleTopicsDelete()
    {
        Security::requirePermission('topic.manage');
        if (!Security::csrfVerify(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
            self::emitJson(['total' => 0, 'rows' => [['ok' => false, 'message' => 'CSRF token invalid']]]);
            return;
        }
        $ctxIds = self::parseCtxIds();
        if (count($ctxIds) === 0) {
            self::emitJson(['total' => 0, 'rows' => [['ok' => false, 'message' => '未选择话题']]]);
            return;
        }
        $deleted = 0;
        $missing = 0;
        foreach ($ctxIds as $ctxId) {
            if (TopicRepository::byCtxId($ctxId) === null) {
                $missing++;
                continue;
            }
            TopicRepository::deleteByCtxId($ctxId);
            $deleted++;
        }
        try { TopicService::export(); } catch (\Throwable $e) { /* export fails silently */ }
        $note = $missing > 0 ? "，{$missing} 条不存在已跳过" : '';
        self::emitJson(['total' => count($ctxIds), 'rows' => [[
            'ok' => true,
            'message' => "批量删除完成：已删除 {$deleted} 条{$note}",
            'deleted' => $deleted,
            'missing' => $missing,
        ]]]);
    }

    /**
     * 批量编辑：把提交的非空字段统一写到所有选中话题。
     * 字段白名单 = 页面可编辑列；空值字段跳过（不覆盖原值）；逐条独立提交，单条失败不中断其余。
     */
    private static function handleTopicsUpdate()
    {
        Security::requirePermission('topic.manage');
        if (!Security::csrfVerify(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
            self::emitJson(['total' => 0, 'rows' => [['ok' => false, 'message' => 'CSRF token invalid']]]);
            return;
        }
        $ctxIds = self::parseCtxIds();
        if (count($ctxIds) === 0) {
            self::emitJson(['total' => 0, 'rows' => [['ok' => false, 'message' => '未选择话题']]]);
            return;
        }
        $editable = ['keyword', 'git_name', 'domain', 'pubdir', 'status', 'lang', 'geo', 'lasttask'];
        $fields = [];
        foreach ($editable as $column) {
            if (!isset($_POST[$column])) {
                continue;
            }
            // 与录入页 sanitizePost 一致：值内竖线剔除，否则会破坏 topic_monitor_list.txt 的管道分隔导出
            $value = trim(str_replace('|', '', (string)$_POST[$column]));
            if ($value !== '') {
                $fields[$column] = $value;
            }
        }
        if (count($fields) === 0) {
            self::emitJson(['total' => 0, 'rows' => [['ok' => false, 'message' => '没有可修改的字段（提交值全为空）']]]);
            return;
        }
        $updated = 0;
        $missing = 0;
        $failed = 0;
        foreach ($ctxIds as $ctxId) {
            $record = TopicRepository::byCtxId($ctxId);
            if ($record === null) {
                $missing++;
                continue;
            }
            foreach ($fields as $column => $value) {
                $record[$column] = $value;
            }
            $record['ctx_id'] = $ctxId;
            try {
                // 部分更新：只写提交的字段，json 按合并结果重算（upsertByTopic 内建）
                TopicRepository::upsertByTopic($record, array_keys($fields));
                $updated++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }
        try { TopicService::export(); } catch (\Throwable $e) { /* export fails silently */ }
        $note = $missing > 0 ? "，{$missing} 条不存在已跳过" : '';
        $note .= $failed > 0 ? "，{$failed} 条写入失败（可能唯一键冲突）" : '';
        self::emitJson(['total' => count($ctxIds), 'rows' => [[
            'ok' => $updated > 0 || $missing > 0,
            'message' => "批量编辑完成：更新 {$updated} 条{$note}",
            'updated' => $updated,
            'missing' => $missing,
            'failed' => $failed,
        ]]]);
    }

    public static function dispatchTable()
    {
        try {
            Security::requireApiToken(true);
            Security::requirePermission('topic.view');
            $data = self::tableData();
            if ($data === null) {
                return;
            }
            self::shell('话题报表', 'topic_table', $data);
        } catch (\Throwable $e) {
            renderErrorPage($e);
        }
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
            // 批量编辑弹窗的值下拉（status/lang/geo/pubdir）取自字典配置
            'config' => Config::all(),
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
            if (!Security::requireSession()) {
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