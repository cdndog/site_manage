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
    if (!Security::requireSession()) {
        return;
    }
    Security::requirePermission('article.manage');

    $eid = trim((string)($_GET['eid'] ?? $_POST['post_uuid'] ?? ''));
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'site_list') {
        if (!Security::csrfVerify(Security::requestToken())) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['total' => 0, 'rows' => [['ok' => false, 'message' => 'CSRF token invalid']]]);
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
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['total' => count($list), 'rows' => $list]);
        return;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!Security::csrfVerify(Security::requestToken())) {
            http_response_code(403);
            render('error', ['message' => 'CSRF token invalid']);
            return;
        }
        $post = ArticleService::sanitizePost($_POST);
        // 保持原 post_uuid
        if ($eid !== '') $post['post_uuid'] = $eid;
        // 以磁盘上的原 JSON 为底，只覆盖表单里调整过的字段，其余键保持不变
        $base = $eid !== '' ? ArticleService::loadJsonForEdit($eid) : null;
        $json = ArticleService::buildJson($post, is_array($base) ? $base : []);
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
            // 刷新缓存（流式写文件，整表不驻留内存）
            try {
                \App\Repositories\AigcStatusRepository::writeCacheFile(Config::dataDir() . '/seodata/aigc_status.json');
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

    // GET：加载现有 JSON（seodata/json 优先，其次 json/）
    if ($eid === '') {
        http_response_code(400);
        render('error', ['message' => '缺少 eid']);
        return;
    }
    $data = ArticleService::loadJsonForEdit($eid);
    if ($data === null) {
        http_response_code(404);
        render('error', ['message' => 'JSON 不存在: ' . $eid]);
        return;
    }
    // 链接名称（slug）：字段直接绑定 JSON 的 slug，不再回退 savename（savename 保留不动）
    // 兼容 text 为字符串/数组两种形态——原直接 ['text'][0] 在字符串时只取到首字符（value="c"）
    $slugValue = ArticleService::textFirst($data['slug'] ?? null);
    $title = ArticleService::textFirst($data['title'] ?? null);
    if ($title === '' && isset($data['topic'])) {
        $title = (string)$data['topic'];
    }
    // 映射到 article_form 的 $form
    $form = [
        'ctx_id' => $eid,
        'url' => $data['url'] ?? '',
        'title' => $title,
        'static_thumbnail' => ArticleService::textFirst($data['static_thumbnail'] ?? null),
        'iframesrc' => implode(',', ArticleService::textValues($data['iframesrc'] ?? null)),
        'tags' => implode(',', ArticleService::textValues($data['tags'] ?? null)),
        'keyword' => count(ArticleService::textValues($data['keywords'] ?? null)) > 0
            ? implode(',', ArticleService::textValues($data['keywords'] ?? null))
            : (isset($data['topic']) ? (string)$data['topic'] : ''),
        'description' => ArticleService::textFirst($data['description'] ?? null),
        'content' => ArticleService::htmlFirst($data['content'] ?? null),
        'lang' => $data['lang'] ?? '',
        'series' => $data['series'] ?? '',
        'pubdir' => $data['pubdir'] ?? '',
        'savename' => $slugValue,
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
    // 手动渲染表单，action 指向本页；json_edit 模式隐藏发布/自动翻译开关并保留原配置值
    render('article_form', ['form'=>$form,'config'=>$config,'sites'=>$sites,'csrf_token'=>Security::csrfToken(),'json_edit'=>true]);
    echo '<script>document.getElementById("wechatpost").action="publish_edit.php?eid='.htmlspecialchars($eid).'";</script>';
    render('footer');
    render('layout_tail');

} catch (\Throwable $e) {
    renderErrorPage($e);
}
