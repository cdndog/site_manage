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
}