<?php

namespace App\Controllers;

use App\Config;
use App\Repositories\ServerRepository;
use App\Repositories\SiteRepository;
use App\Services\ExportService;
use App\Services\SiteService;
use App\Support\Logger;
use App\Support\Security;

class SiteController
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
            render('layout_head', ['page_title' => '站点录入']);
            render('header');
            $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
            if ($method === 'POST') {
                self::handlePost();
            } else {
                self::handleGet();
            }
        } catch (\Throwable $e) {
            self::renderError($e);
        }
    }

    private static function handleGet()
    {
        $config = Config::all();
        if (!empty($_GET['eid'])) {
            $eid = trim((string)$_GET['eid']);
            $row = SiteRepository::findByCtxId($eid);
            $form = SiteService::formFromRow($row !== null ? $row : []);
            $form['post_uuid'] = $eid;
        } else {
            $form = SiteService::defaultForm();
        }
        $servers = ServerRepository::all();
        render('site_form', [
            'form' => $form,
            'config' => $config,
            'servers' => $servers,
            'csrf_token' => Security::csrfToken(),
        ]);
        render('footer');
        render('layout_tail');
    }

    private static function handlePost()
    {
        if (!isset($_POST['setupNum']) || $_POST['setupNum'] !== 'ckeditorFormated') {
            render('footer');
            render('layout_tail');
            return;
        }
        if (!Security::csrfVerify(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
            http_response_code(403);
            render('error', ['message' => 'CSRF token invalid, please reload the page and try again.']);
            render('footer');
            render('layout_tail');
            return;
        }
        $post = SiteService::sanitizePost($_POST);
        $siteJson = SiteService::buildSiteJson($post);
        $content = SiteService::buildContent($post, $siteJson);
        $post['post_json'] = $content['json'];
        SiteService::saveBackup($content, Config::dataDir());
        if (!empty($content['domain'])) {
            SiteRepository::upsertByDomain($content);
        }
        ExportService::export();
        Logger::auditSubmit(
            isset($content['post_uuid']) ? $content['post_uuid'] : '',
            isset($content['git_name']) ? $content['git_name'] : '',
            isset($content['domain']) ? $content['domain'] : '',
            isset($content['status']) ? $content['status'] : ''
        );
        render('site_confirm', [
            'content' => $content,
            'post_json' => $post['post_json'],
        ]);
        render('footer');
        render('layout_tail');
    }

    private static function renderError(\Throwable $e)
    {
        error_log('[siteops] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(500);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Error</title></head>'
            . '<body><h1>Internal Server Error</h1><p>' . e($e->getMessage()) . '</p></body></html>';
    }
}