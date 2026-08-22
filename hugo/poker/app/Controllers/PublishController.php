<?php

namespace App\Controllers;

use App\Repositories\AigcStatusRepository;
use App\Support\Security;

class PublishController
{
    public static function dispatchList()
    {
        Security::requireApiToken(true);
        Security::requirePermission('article.view');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'POST' && isset($_POST['action'])) {
            if ($_POST['action'] === 'delete') { self::handleDelete(); return; }
            if ($_POST['action'] === 'edit') { self::handleEdit(); return; }
            if ($_POST['action'] === 'import') { self::handleImport(); return; }
        }
        $data = self::listData();
        if ($data === null) {
            return;
        }
        self::shell('发布列表', 'publish_list', $data);
    }

    private static function handleDelete()
    {
        Security::requirePermission('article.manage');
        if (!Security::csrfVerify(Security::requestToken())) {
            self::emitJson(['total'=>0,'rows'=>[['ok'=>false,'message'=>'CSRF token invalid']]]);
            return;
        }
        $ctxId = trim((string)($_POST['ctx_id'] ?? ''));
        if ($ctxId === '') {
            self::emitJson(['total'=>0,'rows'=>[['ok'=>false,'message'=>'ctx_id required']]]);
            return;
        }
        $row = \App\Database::fetchOne('SELECT * FROM "aigc_status" WHERE "ctx_id"=:c', [':c'=>$ctxId]);
        if (!$row) {
            self::emitJson(['total'=>0,'rows'=>[['ok'=>false,'message'=>'not found']]]);
            return;
        }
        \App\Database::execute('DELETE FROM "aigc_status" WHERE "ctx_id"=:c', [':c'=>$ctxId]);
        $jsonFile = \App\Config::dataDir() . '/seodata/json/' . $ctxId . '.json';
        if (is_file($jsonFile)) @unlink($jsonFile);
        // 刷新缓存
        try {
            $rows = \App\Database::fetchAll('SELECT * FROM "aigc_status" ORDER BY "publishAt" DESC');
            $f = \App\Config::dataDir() . '/seodata/aigc_status.json';
            file_put_contents($f.'.tmp', json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            @rename($f.'.tmp', $f);
        } catch (\Throwable $e) {}
        self::emitJson(['total'=>1,'rows'=>[['ok'=>true,'message'=>'deleted']]]);
    }

    private static function handleEdit()
    {
        Security::requirePermission('article.manage');
        if (!Security::csrfVerify(Security::requestToken())) {
            self::emitJson(['total'=>0,'rows'=>[['ok'=>false,'message'=>'CSRF token invalid']]]);
            return;
        }
        $ctxId = trim((string)($_POST['ctx_id'] ?? ''));
        if ($ctxId === '') {
            self::emitJson(['total'=>0,'rows'=>[['ok'=>false,'message'=>'ctx_id required']]]);
            return;
        }
        $row = \App\Database::fetchOne('SELECT * FROM "aigc_status" WHERE "ctx_id"=:c', [':c'=>$ctxId]);
        if (!$row) {
            self::emitJson(['total'=>0,'rows'=>[['ok'=>false,'message'=>'not found']]]);
            return;
        }
        $keyword = trim((string)($_POST['keyword'] ?? $row['keyword']));
        $lang = trim((string)($_POST['lang'] ?? $row['lang']));
        $pubdomain = trim((string)($_POST['pubdomain'] ?? $row['pubdomain']));
        \App\Database::execute('UPDATE "aigc_status" SET "keyword"=:k,"lang"=:l,"pubdomain"=:p WHERE "ctx_id"=:c',
            [':k'=>$keyword,':l'=>$lang,':p'=>$pubdomain,':c'=>$ctxId]);
        // 同步 seodata/json
        $jsonFile = \App\Config::dataDir() . '/seodata/json/' . $ctxId . '.json';
        if (is_file($jsonFile)) {
            $j = json_decode((string)file_get_contents($jsonFile), true);
            if (is_array($j) && isset($j[0]) && is_array($j[0])) $j=$j[0];
            if (is_array($j)) {
                if (isset($j['topic'])) $j['topic']=$keyword;
                if (isset($j['title']['text'][0])) $j['title']['text'][0]=$keyword;
                $j['lang']=$lang;
                if (isset($j['pubdomain'])) $j['pubdomain']=array_values(array_filter(array_map('trim', explode(',', $pubdomain))));
                file_put_contents($jsonFile, json_encode([$j], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            }
        }
        try {
            $rows = \App\Database::fetchAll('SELECT * FROM "aigc_status" ORDER BY "publishAt" DESC');
            $f = \App\Config::dataDir() . '/seodata/aigc_status.json';
            file_put_contents($f.'.tmp', json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            @rename($f.'.tmp', $f);
        } catch (\Throwable $e) {}
        self::emitJson(['total'=>1,'rows'=>[['ok'=>true,'message'=>'updated']]]);
    }

    private static function handleImport()
    {
        Security::requirePermission('article.manage');
        if (!Security::csrfVerify(Security::requestToken())) {
            self::emitJson(['total'=>0,'rows'=>[['ok'=>false,'message'=>'CSRF token invalid']]]);
            return;
        }
        $dataDir = \App\Config::dataDir();
        $aigcFile = $dataDir . '/seodata/aigc_status.json';
        $jsonDir = $dataDir . '/seodata/json';
        if (!is_file($aigcFile)) {
            self::emitJson(['total'=>0,'rows'=>[['ok'=>false,'message'=>'seodata/aigc_status.json 不存在']]]);
            return;
        }
        $raw = @file_get_contents($aigcFile);
        $items = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($items)) {
            self::emitJson(['total'=>0,'rows'=>[['ok'=>false,'message'=>'aigc_status.json 解析失败']]]);
            return;
        }
        $imported = 0; $updated = 0; $missing = 0;
        foreach ($items as $it) {
            $ctxId = trim((string)($it['ctx_id'] ?? ''));
            if ($ctxId === '') continue;
            $jsonFile = $jsonDir . '/' . $ctxId . '.json';
            if (!is_file($jsonFile)) { $missing++; continue; }
            $j = json_decode((string)file_get_contents($jsonFile), true);
            if (is_array($j) && isset($j[0]) && is_array($j[0])) $j = $j[0];
            if (!is_array($j) || empty($j['post_uuid'])) continue;
            $rep = \App\Services\ArticleService::datareportFormat($j);
            if (empty($rep)) { $missing++; continue; }
            $exists = \App\Database::fetchOne('SELECT 1 FROM "aigc_status" WHERE "ctx_id"=:c', [':c'=>$ctxId]);
            \App\Database::execute(
                'INSERT OR REPLACE INTO "aigc_status" ("ctx_id","keyword","lang","pubdomain","createAt","publishAt") VALUES (:c,:k,:l,:p,:ca,:pa)',
                [':c'=>$rep['ctx_id'],':k'=>$rep['keyword'],':l'=>$rep['lang'],':p'=>$rep['pubdomain'],':ca'=>$rep['createAt'],':pa'=>$rep['publishAt']]
            );
            if ($exists) $updated++; else $imported++;
        }
        // 额外扫描 json 目录中不在 aigc_status 或已覆盖的文件（基于 ctx_id 覆盖）
        if (is_dir($jsonDir)) {
            foreach (glob($jsonDir.'/*.json') as $jf) {
                $id = basename($jf, '.json');
                // 若已在 aigc_status.json 中处理过则跳过，避免重复计数
                $handled = false;
                foreach ($items as $it2) if (trim((string)($it2['ctx_id'] ?? '')) === $id) { $handled = true; break; }
                if ($handled) continue;
                $j = json_decode((string)file_get_contents($jf), true);
                if (is_array($j) && isset($j[0]) && is_array($j[0])) $j = $j[0];
                if (!is_array($j) || empty($j['post_uuid'])) continue;
                $rep = \App\Services\ArticleService::datareportFormat($j);
                if (empty($rep)) continue;
                $exists = \App\Database::fetchOne('SELECT 1 FROM "aigc_status" WHERE "ctx_id"=:c', [':c'=>$id]);
                \App\Database::execute(
                    'INSERT OR REPLACE INTO "aigc_status" ("ctx_id","keyword","lang","pubdomain","createAt","publishAt") VALUES (:c,:k,:l,:p,:ca,:pa)',
                    [':c'=>$rep['ctx_id'],':k'=>$rep['keyword'],':l'=>$rep['lang'],':p'=>$rep['pubdomain'],':ca'=>$rep['createAt'],':pa'=>$rep['publishAt']]
                );
                if ($exists) $updated++; else $imported++;
            }
        }
        // 刷新缓存
        try {
            $rows = \App\Database::fetchAll('SELECT * FROM "aigc_status" ORDER BY "publishAt" DESC');
            $f = $dataDir . '/seodata/aigc_status.json';
            file_put_contents($f.'.tmp', json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            @rename($f.'.tmp', $f);
        } catch (\Throwable $e) {}
        $msg = "导入完成：新增 $imported 条，覆盖更新 $updated 条，JSON 缺失 $missing 条";
        self::emitJson(['total'=>1,'rows'=>[['ok'=>true,'message'=>$msg]]]);
    }

    private static function listData()
    {
        list($page, $perPage) = self::pageParams();
        $search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
        $sort = isset($_GET['sort']) ? (string)$_GET['sort'] : 'publishAt';
        $order = isset($_GET['order']) ? (string)$_GET['order'] : 'desc';
        $result = AigcStatusRepository::search($search, $page, $perPage, $sort, $order);
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
