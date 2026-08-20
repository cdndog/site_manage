<?php

namespace App\Controllers;

use App\Config;
use App\Repositories\ArticleRepository;
use App\Repositories\SiteRepository;
use App\Services\ArticleService;
use App\Services\WeChatService;
use App\Support\Logger;
use App\Support\Security;

class WeChatController
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
            $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
            if ($method === 'POST' && isset($_POST['action'])) {
                if ($_POST['action'] === 'site_list') {
                    self::handleSiteList();
                    return;
                }
                if ($_POST['action'] === 'imgbb_sync') {
                    self::handleImgbbSync();
                    return;
                }
                if ($_POST['action'] === 'imgbb_batch_sync') {
                    self::handleImgbbBatchSync();
                    return;
                }
            }
            render('layout_head', ['page_title' => '微信导入', 'extra_head' => '<link rel="stylesheet" href="js/ckeditor5/ckeditor5.css">']);
            render('header');
            if ($method === 'POST') {
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
        $wxNotice = '';
        $wxGot = false;
        $wxUrl = isset($_GET['wx_url']) ? trim((string) $_GET['wx_url']) : '';
        if ($wxUrl !== '') {
            if (!WeChatService::isWeixinArticleUrl($wxUrl)) {
                $wxNotice = '仅支持 mp.weixin.qq.com 域名下的文章链接。';
            } else {
                $wxData = WeChatService::extract($wxUrl);
                if (isset($wxData['error'])) {
                    $wxNotice = '提取失败：' . $wxData['error'];
                } else {
                    $wxGot = true;
                    $wxNotice = '提取成功！请在下方确认后提交文章。';
                    $form['ctx_id'] = isset($wxData['id']) ? trim($wxData['id']) : $form['ctx_id'];
                    $form['url'] = isset($wxData['url']) ? trim($wxData['url']) : $wxUrl;
                    $form['title'] = isset($wxData['title']) ? trim($wxData['title']) : '';
                    $form['static_thumbnail'] = isset($wxData['cover']) ? trim($wxData['cover']) : '';
                    $form['tags'] = isset($wxData['author']) ? trim($wxData['author']) : '';
                    $form['keyword'] = isset($wxData['author']) ? trim($wxData['author']) : '';
                    $form['description'] = isset($wxData['description']) ? trim($wxData['description']) : '';
                    $form['savename'] = isset($wxData['slug']) ? trim($wxData['slug']) : '';
                    $form['content'] = isset($wxData['content_html']) ? trim($wxData['content_html']) : '';
                }
            }
        }
        render('wechat_form', [
            'config' => $config,
            'sites' => $sites,
            'form' => $form,
            'csrf_token' => Security::csrfToken(),
            'wx_url' => $wxUrl,
            'wx_notice' => $wxNotice,
            'wx_got' => $wxGot,
        ]);
    }

    private static function handlePost()
    {
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

    private static function handleSiteList()
    {
        if (!Security::csrfVerify(Security::requestToken())) {
            self::emitJson(['total' => 0, 'rows' => [['ok' => false, 'message' => 'CSRF token invalid']]]);
            return;
        }
        $list = [];
        foreach (SiteRepository::all() as $site) {
            $list[] = [
                'domain' => isset($site['domain']) ? (string) $site['domain'] : '',
                'languages' => isset($site['languages']) ? (string) $site['languages'] : '',
            ];
        }
        self::emitJson(['total' => count($list), 'rows' => $list]);
    }

    private static function handleImgbbSync()
    {
        if (!Security::csrfVerify(Security::requestToken())) {
            self::emitJson(['ok' => false, 'error' => 'CSRF token invalid']);
            return;
        }
        $url = isset($_POST['url']) ? trim((string) $_POST['url']) : '';
        $r = WeChatService::syncImage($url);
        self::emitJson($r);
    }

    private static function handleImgbbBatchSync()
    {
        if (!Security::csrfVerify(Security::requestToken())) {
            self::emitJson(['ok' => false, 'error' => 'CSRF token invalid']);
            return;
        }
        $raw = isset($_POST['urls']) ? (string) $_POST['urls'] : '';
        $list = json_decode($raw, true);
        if (!is_array($list)) {
            $list = [];
            foreach (explode(',', $raw) as $item) {
                $item = trim($item);
                if ($item !== '') {
                    $list[] = $item;
                }
            }
        }
        self::emitJson(['ok' => true, 'results' => WeChatService::syncImages($list)]);
    }

    private static function emitJson(array $result)
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function dispatchCookie()
    {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        try {
            Security::requireApiToken(true);
            Security::requirePermission('config.manage');
            $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
            if ($method === 'POST') {
                self::handleCookieSave();
                return;
            }
            self::shellCookie('微信cookie配置', self::cookieData());
        } catch (\Throwable $e) {
            renderErrorPage($e);
        }
    }

    private static function cookieData(array $overrides = [], $message = '', $error = false)
    {
        $wc = Config::wechatConfig();
        $data = [
            'cookie_file' => Config::wechatConfigFile(),
            'cookie' => isset($overrides['cookie']) ? (string)$overrides['cookie'] : (string)$wc['cookie'],
            'http_headers' => isset($overrides['http_headers'])
                ? (string)$overrides['http_headers']
                : json_encode($wc['http_headers'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'wechat_forwarded_for' => isset($overrides['wechat_forwarded_for']) ? (string)$overrides['wechat_forwarded_for'] : (string)$wc['wechat_forwarded_for'],
            'proxy' => isset($overrides['proxy']) ? (string)$overrides['proxy'] : (string)$wc['proxy'],
            'connect_timeout' => isset($overrides['connect_timeout']) ? (int)$overrides['connect_timeout'] : (int)$wc['connect_timeout'],
            'timeout' => isset($overrides['timeout']) ? (int)$overrides['timeout'] : (int)$wc['timeout'],
            'max_retries' => isset($overrides['max_retries']) ? (int)$overrides['max_retries'] : (int)$wc['max_retries'],
            'csrf_token' => Security::csrfToken(),
            'message' => $message,
            'error' => $error,
        ];
        return $data;
    }

    private static function handleCookieSave()
    {
        if (!Security::csrfVerify(Security::requestToken())) {
            http_response_code(403);
            render('error', ['message' => 'CSRF token invalid, please reload the page and try again.']);
            return;
        }
        $values = self::cookieFormValues();
        $error = '';
        if (isset($values['error'])) {
            $error = $values['error'];
        }
        if ($error === '') {
            if (!self::writeCookieFile($values['config'])) {
                $error = '写入配置文件失败，请检查文件权限（' . Config::wechatConfigFile() . '）';
            }
        }
        if ($error !== '') {
            self::shellCookie('微信cookie配置', self::cookieData($values['form'], '保存失败：' . $error, true));
            return;
        }
        Config::reset();
        self::shellCookie('微信cookie配置', self::cookieData([], '已保存到 ' . Config::wechatConfigFile(), false));
    }

    private static function cookieFormValues()
    {
        $form = [
            'cookie' => isset($_POST['cookie']) ? (string)$_POST['cookie'] : '',
            'http_headers' => isset($_POST['http_headers']) ? (string)$_POST['http_headers'] : '[]',
            'wechat_forwarded_for' => isset($_POST['wechat_forwarded_for']) ? (string)$_POST['wechat_forwarded_for'] : '',
            'proxy' => isset($_POST['proxy']) ? (string)$_POST['proxy'] : '',
            'connect_timeout' => isset($_POST['connect_timeout']) ? (string)$_POST['connect_timeout'] : '',
            'timeout' => isset($_POST['timeout']) ? (string)$_POST['timeout'] : '',
            'max_retries' => isset($_POST['max_retries']) ? (string)$_POST['max_retries'] : '',
        ];
        $config = [
            'cookie' => $form['cookie'],
            'http_headers' => [],
            'wechat_forwarded_for' => $form['wechat_forwarded_for'],
            'proxy' => $form['proxy'],
            'connect_timeout' => 15,
            'timeout' => 30,
            'max_retries' => 2,
        ];
        $raw = trim($form['http_headers']);
        if ($raw !== '') {
            $headers = json_decode($raw, true);
            if (!is_array($headers)) {
                return ['error' => 'http_headers 必须是 JSON 数组，例如 ["User-Agent: CustomAgent"]', 'form' => $form];
            }
            foreach ($headers as $header) {
                if (!is_string($header)) {
                    return ['error' => 'http_headers 必须是 JSON 字符串数组', 'form' => $form];
                }
            }
            $config['http_headers'] = array_values($headers);
        }
        foreach (['connect_timeout', 'timeout'] as $key) {
            if ($form[$key] === '' || !preg_match('/^\d+$/', $form[$key]) || (int)$form[$key] < 1) {
                return ['error' => $key . ' 必须是大于 0 的整数秒数', 'form' => $form];
            }
            $config[$key] = (int)$form[$key];
        }
        if ($form['max_retries'] === '' || !preg_match('/^\d+$/', $form['max_retries']) || (int)$form['max_retries'] < 0) {
            return ['error' => 'max_retries 必须是不小于 0 的整数', 'form' => $form];
        }
        $config['max_retries'] = (int)$form['max_retries'];
        return ['config' => $config, 'form' => $form];
    }

    private static function writeCookieFile(array $config)
    {
        $path = Config::wechatConfigFile();
        $lines = [];
        foreach ($config as $key => $value) {
            $lines[] = "    '" . $key . "' => " . var_export($value, true) . ',';
        }
        $content = "<?php\n\n/* ── 微信抓取登录态配置（独立于 global_config.php） ──\n * 本文件与 global_config.php 同目录。内容合并覆盖 global_config.php 的 wechat 段：\n *   - cookie：公众号登录 Cookie（浏览器登录 mp.weixin.qq.com 后复制，可避免风控验证页）\n *   - http_headers：整组替换（留空 [] 则保留默认浏览器请求头）\n *   - wechat_forwarded_for / proxy / connect_timeout / timeout / max_retries：按需覆盖\n */\nreturn [\n" . implode("\n", $lines) . "\n];\n";
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return false;
        }
        return @file_put_contents($path, $content, LOCK_EX) !== false;
    }

    private static function shellCookie($pageTitle, array $data)
    {
        try {
            Security::ensureUidCookie();
            if (!Security::authValid()) {
                AuthController::handle();
                return;
            }
            Security::requirePermission('config.manage');
            render('layout_head', ['page_title' => $pageTitle]);
            render('header');
            render('wechat_cookie', $data);
            render('footer');
            render('layout_tail');
        } catch (\Throwable $e) {
            renderErrorPage($e);
        }
    }
}