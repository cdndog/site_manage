<?php
require __DIR__ . '/app/bootstrap.php';

use App\Config;
use App\Repositories\SiteRepository;
use App\Services\ArticleService;
use App\Support\Security;
use App\Controllers\AuthController;

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

try {
    \App\Support\Security::requireApiToken(true);
    Security::ensureUidCookie();
    if (!Security::authValid() && !Security::isGitServerIp()) {
        AuthController::handle();
        return;
    }
    Security::requirePermission('article.manage');

    $eid = trim((string)($_GET['eid'] ?? $_POST['post_uuid'] ?? ''));
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!Security::csrfVerify(Security::requestToken())) {
            http_response_code(403);
            render('error', ['message' => 'CSRF token invalid']);
            return;
        }
        $post = ArticleService::sanitizePost($_POST);
        // 保持原 post_uuid
        if ($eid !== '') $post['post_uuid'] = $eid;
        $json = ArticleService::buildJson($post);
        // seodata 落盘
        $ctxId = $json['post_uuid'];
        $seoDir = Config::dataDir() . '/seodata/json';
        if (!is_dir($seoDir)) @mkdir($seoDir, 0755, true);
        file_put_contents($seoDir . '/' . $ctxId . '.json', json_encode([$json], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        // AIGC 索引更新
        $rep = ArticleService::datareportFormat($json);
        if (!empty($rep)) {
            \App\Database::execute(
                'INSERT OR REPLACE INTO "aigc_status" ("ctx_id","keyword","lang","pubdomain","createAt","publishAt") VALUES (:c,:k,:l,:p,:ca,:pa)',
                [':c'=>$rep['ctx_id'],':k'=>$rep['keyword'],':l'=>$rep['lang'],':p'=>$rep['pubdomain'],':ca'=>$rep['createAt'],':pa'=>$rep['publishAt']]
            );
            // 刷新缓存
            try {
                $rows = \App\Database::fetchAll('SELECT * FROM "aigc_status" ORDER BY "publishAt" DESC');
                $f = Config::dataDir() . '/seodata/aigc_status.json';
                file_put_contents($f.'.tmp', json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                @rename($f.'.tmp', $f);
            } catch (\Throwable $e) {}
        }
        // 同时更新 article 表（复用既有逻辑，存在则更新）
        $record = ArticleService::toRecord($post, $json);
        \App\Repositories\ArticleRepository::upsertByCtxId($record);
        render('layout_head', ['page_title' => '编辑文章']);
        render('header');
        render('article_confirm', ['record'=>$record,'json'=>$json,'json_text'=>json_encode($json, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'publish_messages'=>['seodata 已同步']]);
        render('footer');
        render('layout_tail');
        return;
    }

    // GET：加载 seodata/json
    if ($eid === '') {
        http_response_code(400);
        render('error', ['message' => '缺少 eid']);
        return;
    }
    $file = Config::dataDir() . '/seodata/json/' . $eid . '.json';
    if (!is_file($file)) {
        http_response_code(404);
        render('error', ['message' => 'JSON 不存在: ' . $eid]);
        return;
    }
    $data = json_decode((string)file_get_contents($file), true);
    if (is_array($data) && isset($data[0]) && is_array($data[0])) $data = $data[0];
    if (!is_array($data)) {
        http_response_code(500);
        render('error', ['message' => 'JSON 解析失败']);
        return;
    }
    // 映射到 article_form 的 $form
    $form = [
        'ctx_id' => $eid,
        'url' => $data['url'] ?? '',
        'title' => $data['title']['text'][0] ?? ($data['topic'] ?? ''),
        'static_thumbnail' => $data['static_thumbnail']['text'][0] ?? '',
        'iframesrc' => isset($data['iframesrc']['text']) ? implode(',', (array)$data['iframesrc']['text']) : '',
        'tags' => isset($data['tags']['text']) ? implode(',', (array)$data['tags']['text']) : '',
        'keyword' => isset($data['keywords']['text']) ? implode(',', (array)$data['keywords']['text']) : (isset($data['topic']) ? (string)$data['topic'] : ''),
        'description' => $data['description']['text'][0] ?? '',
        'content' => $data['content']['html'][0] ?? '',
        'lang' => $data['lang'] ?? '',
        'series' => $data['series'] ?? '',
        'pubdir' => $data['pubdir'] ?? '',
        'savename' => $data['savename']['text'][0] ?? '',
        'pubdomain' => $data['pubdomain'] ?? [],
        'globalpublish' => $data['globalpublish'] ?? 'no',
        'translate_to_langs' => $data['translate_to_langs'] ?? [],
    ];
    $config = Config::all();
    $sites = SiteRepository::all();
    render('layout_head', ['page_title' => '编辑文章', 'extra_head' => '<link rel="stylesheet" href="js/ckeditor5/ckeditor5.css">']);
    render('header');
    // 复用文章编辑 UI，提交仍到本页
    // 需覆盖表单 action 为 publish_edit.php?eid=...
    echo '<div class="alert alert-info small">正在编辑 AIGC 生成的 JSON（seodata/json/'.$eid.'.json），提交将同步更新 JSON 与 aigc_status 索引。</div>';
    // 手动渲染表单，action 指向本页
    render('article_form', ['form'=>$form,'config'=>$config,'sites'=>$sites,'csrf_token'=>Security::csrfToken()]);
    echo '<script>document.getElementById("wechatpost").action="publish_edit.php?eid='.htmlspecialchars($eid).'";</script>';
    render('footer');
    render('layout_tail');

} catch (\Throwable $e) {
    renderErrorPage($e);
}
