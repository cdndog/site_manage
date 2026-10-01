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
        // 批量收集所有待写入记录，一次性查询已有 ctx_id
        $allRecords = [];
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
            $allRecords[] = ['ctx_id'=>$rep['ctx_id'],'keyword'=>$rep['keyword'],'lang'=>$rep['lang'],'pubdomain'=>$rep['pubdomain'],'createAt'=>$rep['createAt'],'publishAt'=>$rep['publishAt']];
        }
        // 额外扫描 json 目录
        if (is_dir($jsonDir)) {
            $handledIds = array_flip(array_map(function ($it) { return trim((string)($it['ctx_id'] ?? '')); }, $items));
            foreach (glob($jsonDir.'/*.json') as $jf) {
                $id = basename($jf, '.json');
                if (isset($handledIds[$id])) continue;
                $j = json_decode((string)file_get_contents($jf), true);
                if (is_array($j) && isset($j[0]) && is_array($j[0])) $j = $j[0];
                if (!is_array($j) || empty($j['post_uuid'])) continue;
                $rep = \App\Services\ArticleService::datareportFormat($j);
                if (empty($rep)) continue;
                $allRecords[] = ['ctx_id'=>$rep['ctx_id'],'keyword'=>$rep['keyword'],'lang'=>$rep['lang'],'pubdomain'=>$rep['pubdomain'],'createAt'=>$rep['createAt'],'publishAt'=>$rep['publishAt']];
            }
        }
        // 批量查询已有 ctx_id，一次性判断 insert/update
        $existingIds = [];
        if (count($allRecords) > 0) {
            $allCtxIds = array_unique(array_map(function ($r) { return $r['ctx_id']; }, $allRecords));
            $chunks = array_chunk($allCtxIds, 5000);
            foreach ($chunks as $chunk) {
                $params = [];
                $placeholders = [];
                foreach ($chunk as $i => $val) {
                    $key = ':c' . $i;
                    $placeholders[] = $key;
                    $params[$key] = $val;
                }
                $rows = \App\Database::fetchAll('SELECT "ctx_id" FROM "aigc_status" WHERE "ctx_id" IN (' . implode(',', $placeholders) . ')', $params);
                foreach ($rows as $row) { $existingIds[$row['ctx_id']] = true; }
            }
        }
        // 批量写入（单事务）
        $db = \App\Database::connection();
        $isPg = \App\Database::isPg();
        if ($isPg) { $db->beginTransaction(); } else { $db->exec('BEGIN'); }
        try {
            foreach ($allRecords as $rep) {
                $exists = isset($existingIds[$rep['ctx_id']]);
                \App\Database::execute(
                    'INSERT INTO "aigc_status" ("ctx_id","keyword","lang","pubdomain","createAt","publishAt") VALUES (:c,:k,:l,:p,:ca,:pa) ON CONFLICT ("ctx_id") DO UPDATE SET "keyword"=EXCLUDED."keyword","lang"=EXCLUDED."lang","pubdomain"=EXCLUDED."pubdomain","createAt"=EXCLUDED."createAt","publishAt"=EXCLUDED."publishAt"',
                    [':c'=>$rep['ctx_id'],':k'=>$rep['keyword'],':l'=>$rep['lang'],':p'=>$rep['pubdomain'],':ca'=>$rep['createAt'],':pa'=>$rep['publishAt']]
                );
                if ($exists) $updated++; else $imported++;
            }
            if ($isPg) { $db->commit(); } else { $db->exec('COMMIT'); }
        } catch (\Throwable $e) {
            if ($isPg) { $db->rollBack(); } else { $db->exec('ROLLBACK'); }
        }
        // 刷新缓存（分块写文件，避免内存溢出）
        try {
            $fp = @fopen($dataDir . '/seodata/aigc_status.json.tmp', 'w');
            if ($fp !== false) {
                fwrite($fp, '[');
                $offset = 0; $chunkSize = 5000; $first = true;
                do {
                    $rows = \App\Database::fetchAll('SELECT * FROM "aigc_status" ORDER BY "publishAt" DESC LIMIT ' . $chunkSize . ' OFFSET ' . $offset);
                    foreach ($rows as $row) {
                        if (!$first) fwrite($fp, ',');
                        fwrite($fp, json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                        $first = false;
                    }
                    $offset += $chunkSize;
                } while (count($rows) === $chunkSize);
                fwrite($fp, ']');
                fclose($fp);
                @rename($dataDir . '/seodata/aigc_status.json.tmp', $dataDir . '/seodata/aigc_status.json');
            }
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
