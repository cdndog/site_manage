<?php

namespace App\Controllers;

use App\Config;
use App\Repositories\KeywordRepository;
use App\Repositories\SiteRepository;
use App\Services\KeywordService;
use App\Support\Logger;
use App\Support\Security;

class KeywordController
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
            Security::requirePermission('keyword.manage');
            render('layout_head', ['page_title' => '关键词配置']);
            render('header');
            $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
            if ($method === 'POST') {
                self::handlePost();
            } else {
                self::handleGet();
            }
        } catch (\Throwable $e) {
            renderErrorPage($e);
        }
    }

    private static function handleGet()
    {
        $sites = SiteRepository::all();
        $config = Config::all();
        $form = KeywordService::defaultForm();
        $eid = isset($_GET['eid']) ? trim((string)$_GET['eid']) : '';
        if ($eid !== '') {
            $record = KeywordRepository::byCtxId($eid);
            if ($record !== null) {
                $form = [
                    'ctx_id' => $record['ctx_id'],
                    'post_keyword' => (string)$record['keyword'],
                    'post_gitname' => (string)$record['git_name'],
                    'post_lang' => (string)$record['lang'],
                    'post_geo' => (string)$record['geo'],
                    'post_pubdir' => (string)$record['pubdir'],
                    'post_status' => (string)$record['status'],
                    'post_bulkkeyword' => '',
                ];
            }
        }
        render('keyword_form', [
            'form' => $form,
            'config' => $config,
            'sites' => $sites,
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
        $post = KeywordService::sanitizePost($_POST);
        $records = KeywordService::buildRecords($post);
        if (count($records) === 0) {
            http_response_code(400);
            render('error', ['message' => 'keyword is empty.']);
            render('footer');
            render('layout_tail');
            return;
        }
        $records = KeywordService::saveAll($records);
        foreach ($records as $record) {
            KeywordService::saveBackup($record);
            Logger::auditKeyword(
                isset($record['keyword']) ? $record['keyword'] : '',
                isset($record['git_name']) ? $record['git_name'] : '',
                isset($record['status']) ? $record['status'] : ''
            );
        }
        KeywordService::export();
        $gitName = isset($records[0]['git_name']) ? $records[0]['git_name'] : '';
        render('keyword_confirm', [
            'records' => $records,
            'keywords' => $gitName !== '' ? KeywordRepository::byGitName($gitName) : [],
        ]);
        render('footer');
        render('layout_tail');
    }

    private static function renderError(\Throwable $e)
    {
        error_log('[keywordops] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(500);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Error</title></head>'
            . '<body><h1>Internal Server Error</h1><p>' . e($e->getMessage()) . '</p></body></html>';
    }
}
