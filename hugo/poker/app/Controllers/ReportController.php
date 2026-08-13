<?php

namespace App\Controllers;

use App\Repositories\KeywordRepository;
use App\Repositories\SiteRepository;
use App\Repositories\TopicRepository;
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
            Security::ensureUidCookie();
            if (!Security::authValid()) {
                AuthController::handle();
                return;
            }
            $data = self::handleGet();
            if ($data === null) {
                return;
            }
            render('layout_head', ['page_title' => 'SEO 报告']);
            render('header');
            render('report_table', $data);
            render('footer');
            render('layout_tail');
        } catch (\Throwable $e) {
            self::renderError($e);
        }
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
                    ['field' => '_edit', 'title' => '编辑', 'align' => 'center', 'width' => 110, 'formatter' => 'siteEditer'],
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
                    ['field' => '_edit', 'title' => '编辑', 'align' => 'center', 'width' => 110, 'formatter' => 'topicEditer'],
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
                    ['field' => '_edit', 'title' => '编辑', 'align' => 'center', 'width' => 110, 'formatter' => 'keywordEditer'],
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
        echo json_encode(['total' => $result['total'], 'rows' => array_values($result['rows'])], JSON_UNESCAPED_UNICODE);
    }

    private static function renderError(\Throwable $e)
    {
        error_log('[seo_report] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(500);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Error</title></head>'
            . '<body><h1>Internal Server Error</h1><p>' . e($e->getMessage()) . '</p></body></html>';
    }
}