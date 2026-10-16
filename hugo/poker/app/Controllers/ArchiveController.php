<?php

namespace App\Controllers;

use App\Services\ArchiveConfigService;
use App\Services\GitHubService;
use App\Services\WaybackService;
use App\Support\Security;

/**
 * 网站历史档：archive.org 时光机历史名称查询 → 选择归档基线 → 创建 GitHub 私有仓库并生成 README。
 * 代理/Git 账号/README 模板/上传目录等配置全部以文件保存（见 ArchiveConfigService）。
 */
class ArchiveController
{
    public static function dispatch()
    {
        // archive.org 经代理/直连均可能较慢：放开 PHP 执行时间上限，由 curl 超时兜底
        set_time_limit(0);
        ini_set('max_execution_time', '0');
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        try {
            Security::requireApiToken(true);
            Security::ensureUidCookie();
            if (!Security::requireSession()) {
                return;
            }
            Security::requirePermission('site.view');
            $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
            if ($method === 'POST' && isset($_POST['action'])) {
                $action = (string)$_POST['action'];
                if ($action === 'check') {
                    self::handleCheck();
                    return;
                }
                if ($action === 'preview') {
                    self::handlePreview();
                    return;
                }
                if ($action === 'proxy_test') {
                    self::handleProxyTest();
                    return;
                }
                if ($action === 'create') {
                    // 创建仓库/上传属于写操作：仅 site.manage（编辑及以上）
                    Security::requirePermission('site.manage');
                    self::handleCreate();
                    return;
                }
            }
            self::shell();
        } catch (\Throwable $e) {
            renderErrorPage($e);
        }
    }

    /** 从 POST 解析代理地址（预设 id → 配置地址；__manual__ → 手工录入；空 → 自动） */
    private static function resolveProxyFromPost(): string
    {
        $preset = trim((string)(isset($_POST['proxy_preset']) ? $_POST['proxy_preset'] : ''));
        $manual = trim((string)(isset($_POST['proxy_addr']) ? $_POST['proxy_addr'] : ''));
        if ($preset !== '' && $preset !== '__manual__') {
            return ArchiveConfigService::proxyById($preset);
        }
        if ($preset === '__manual__') {
            return $manual;
        }
        return '';
    }

    /** 净化域名输入（仅字母数字 . - 与可选端口） */
    private static function sanitizeDomain(string $domain): string
    {
        $domain = trim($domain);
        if ($domain !== '' && !preg_match('/^[A-Za-z0-9.-]+(:\d{1,5})?$/', $domain)) {
            $domain = preg_replace('/[^A-Za-z0-9.:-]/', '', $domain);
        }
        return $domain;
    }

    /** 步骤1：查询历史名称（AJAX → JSON，返回结果表 HTML） */
    private static function handleCheck()
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!Security::csrfVerify(Security::requestToken())) {
            echo json_encode(['ok' => false, 'error' => 'CSRF token 无效，请刷新页面重试'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $domain = self::sanitizeDomain((string)(isset($_POST['domain']) ? $_POST['domain'] : ''));
        if ($domain === '') {
            echo json_encode(['ok' => false, 'error' => '请输入域名'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $proxyAddr = self::resolveProxyFromPost();
        $homepageOnly = !empty($_POST['homepage_only']);
        $t0 = microtime(true);
        $r = WaybackService::cdxSnapshots($domain, $proxyAddr, false);
        if (!$r['ok']) {
            echo json_encode(['ok' => false, 'error' => $r['error']], JSON_UNESCAPED_UNICODE);
            return;
        }
        $homepages = WaybackService::selectHomepages($r['rows'], !$homepageOnly);
        $items = [];
        foreach ($homepages as $hp) {
            $items[] = [(string)($hp[0] ?? ''), (string)($hp[1] ?? '')];
        }
        $titles = WaybackService::fetchTitlesMulti($items, 5, 70, $proxyAddr, false);
        $out = [];
        foreach ($homepages as $i => $hp) {
            $out[] = [
                'ts' => (string)($hp[0] ?? ''),
                'url' => (string)($hp[1] ?? ''),
                'status' => (string)($hp[2] ?? ''),
                'title' => (string)($titles[$i] ?? '(empty response)'),
            ];
        }
        $elapsed = round(microtime(true) - $t0, 2);
        ob_start();
        render('archive_results', ['items' => $out, 'domain' => $domain]);
        $html = ob_get_clean();
        echo json_encode(['ok' => true, 'html' => $html, 'count' => count($out), 'elapsed' => $elapsed, 'domain' => $domain], JSON_UNESCAPED_UNICODE);
    }

    /** 步骤3：生成预览（AJAX → JSON，不调用 GitHub API） */
    private static function handlePreview()
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!Security::csrfVerify(Security::requestToken())) {
            echo json_encode(['ok' => false, 'error' => 'CSRF token 无效，请刷新页面重试'], JSON_UNESCAPED_UNICODE);
            return;
        }
        try {
            $repoName = trim((string)(isset($_POST['repo_name']) ? $_POST['repo_name'] : ''));
            if ($repoName === '') {
                throw new \RuntimeException('请填写仓库名');
            }
            $archTs = (string)(isset($_POST['archive_ts']) ? $_POST['archive_ts'] : '');
            $archUrl = (string)(isset($_POST['archive_url']) ? $_POST['archive_url'] : '');
            if ($archTs === '' || $archUrl === '') {
                throw new \RuntimeException('请先选择归档基线');
            }
            $username = trim((string)(isset($_POST['username']) ? $_POST['username'] : ''));
            $readme = self::buildReadme($_POST);
            $githubUrl = $username !== '' ? 'https://github.com/' . $username . '/' . $repoName : '';
            echo json_encode(['ok' => true, 'github_url' => $githubUrl, 'readme' => $readme, 'repo' => $repoName], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /** 步骤1：测试代理连通性（整页渲染） */
    private static function handleProxyTest()
    {
        if (!Security::csrfVerify(Security::requestToken())) {
            self::shell(['error' => 'CSRF token 无效，请刷新页面重试', 'form' => $_POST]);
            return;
        }
        $proxyAddr = self::resolveProxyFromPost();
        $result = WaybackService::proxyTest($proxyAddr);
        self::shell(['proxy_test' => $result, 'form' => $_POST]);
    }

    /** 步骤3：创建私有仓库 + README + 上传 + 记录（整页渲染） */
    private static function handleCreate()
    {
        if (!Security::csrfVerify(Security::requestToken())) {
            self::shell(['error' => 'CSRF token 无效，请刷新页面重试', 'form' => $_POST]);
            return;
        }
        $token = (string)(isset($_POST['token']) ? $_POST['token'] : '');
        $username = trim((string)(isset($_POST['username']) ? $_POST['username'] : ''));
        $repoName = trim((string)(isset($_POST['repo_name']) ? $_POST['repo_name'] : ''));
        $siteName = trim((string)(isset($_POST['site_name']) ? $_POST['site_name'] : ''));
        $archTs = (string)(isset($_POST['archive_ts']) ? $_POST['archive_ts'] : '');
        $archUrl = (string)(isset($_POST['archive_url']) ? $_POST['archive_url'] : '');
        $archDomain = (string)(isset($_POST['archive_domain']) ? $_POST['archive_domain'] : '');
        $templateId = (string)(isset($_POST['template_id']) ? $_POST['template_id'] : '');

        $form = $_POST;
        $baseline = $archTs !== '' && $archUrl !== ''
            ? ['ts' => $archTs, 'url' => $archUrl, 'title' => $siteName !== '' ? $siteName : $archDomain, 'domain' => $archDomain]
            : null;
        $state = ['form' => $form, 'baseline' => $baseline];

        try {
            if ($repoName === '') {
                throw new \RuntimeException('请填写仓库名');
            }
            if ($archTs === '' || $archUrl === '') {
                throw new \RuntimeException('请先选择归档基线');
            }
            if ($username === '') {
                throw new \RuntimeException('请填写 GitHub 用户名');
            }
            if ($token === '') {
                throw new \RuntimeException('请填写 GitHub Token');
            }
            if (!GitHubService::isValidToken($token)) {
                throw new \RuntimeException('GitHub Token 格式不正确（应以 ghp_ / gho_ / github_pat_ 等开头，长度至少 15 位）');
            }

            $readme = self::buildReadme($form);
            $desc = "从 archive 恢复 {$archDomain} 网站数据（{$archTs}）";

            [$ok, $info] = GitHubService::createRepo($token, $repoName, $desc);
            if (!$ok) {
                throw new \RuntimeException((string)$info);
            }
            [$ok2, $msg2] = GitHubService::createReadme($token, $info['owner'], $info['repo'], $readme);
            if (!$ok2) {
                throw new \RuntimeException($msg2 . '（仓库已创建）');
            }
            $repoCreated = [
                'html' => $info['html'],
                'readme' => $msg2,
                'repo' => $info['owner'] . '/' . $info['repo'],
            ];
            [$upOk, $upMsg] = GitHubService::uploadRepoDirs($token, $info['owner'], $info['repo']);
            $repoCreated['upload'] = $upMsg;
            $notice = '';
            if (!$upOk) {
                $notice = '仓库已创建，但部分文件上传失败：' . str_replace("\n", '；', $upMsg);
            }
            $recordOk = ArchiveConfigService::recordRepo([
                'owner' => $info['owner'],
                'repo' => $info['repo'],
                'html_url' => $info['html'],
                'readme_url' => $msg2,
                'site_name' => $siteName !== '' ? $siteName : $archDomain,
                'domain' => $archDomain,
                'archive_ts' => $archTs,
                'archive_url' => $archUrl,
                'template_id' => $templateId,
                'created_by' => (string)(Security::currentUser() !== null ? Security::currentUser()['username'] : ''),
                'created_at' => gmdate('c'),
            ]);
            if (!$recordOk && $notice === '') {
                $notice = '仓库已创建，但记录保存失败（目录权限不足？）';
            }
            $state['repo_created'] = $repoCreated;
            $state['notice'] = $notice;
        } catch (\Throwable $e) {
            $state['error'] = $e->getMessage();
        }
        self::shell($state);
    }

    /** 按模板与占位符生成 README 内容 */
    private static function buildReadme(array $post): string
    {
        $templateId = (string)(isset($post['template_id']) ? $post['template_id'] : '');
        $tpl = ArchiveConfigService::templateById($templateId);
        if ($tpl === null) {
            $tpl = ArchiveConfigService::defaultTemplate();
        }
        if ($tpl === null) {
            $list = ArchiveConfigService::templates();
            $tpl = isset($list[0]) ? $list[0] : null;
        }
        $file = $tpl !== null ? (string)($tpl['file'] ?? '') : '';
        $template = ArchiveConfigService::templateContent($file);

        $archTs = (string)(isset($post['archive_ts']) ? $post['archive_ts'] : '');
        $archUrl = (string)(isset($post['archive_url']) ? $post['archive_url'] : '');
        $archDomain = (string)(isset($post['archive_domain']) ? $post['archive_domain'] : '');
        $siteName = trim((string)(isset($post['site_name']) ? $post['site_name'] : ''));
        $vars = [
            '{{SITE_NAME}}' => $siteName !== '' ? $siteName : $archDomain,
            '{{ARCHIVE_PAGE}}' => WaybackService::ARCHIVE . '/' . $archTs . '*/' . $archUrl,
            '{{ARCHIVE_DATE}}' => $archTs,
            '{{ARCHIVE_DOMAIN}}' => $archDomain,
            '{{ARCHIVE_URL}}' => $archUrl,
        ];
        return strtr($template, $vars);
    }

    private static function shell(array $state = [])
    {
        $currentUser = Security::currentUser();
        $data = [
            'proxies' => ArchiveConfigService::proxies(),
            'accounts' => ArchiveConfigService::accounts(),
            'templates' => ArchiveConfigService::templates(),
            'repos' => ArchiveConfigService::repos(),
            'csrf_token' => Security::csrfToken(),
            'error' => isset($state['error']) ? (string)$state['error'] : '',
            'notice' => isset($state['notice']) ? (string)$state['notice'] : '',
            'repo_created' => isset($state['repo_created']) ? $state['repo_created'] : null,
            'proxy_test' => isset($state['proxy_test']) ? $state['proxy_test'] : null,
            'form' => isset($state['form']) && is_array($state['form']) ? $state['form'] : [],
            'baseline' => isset($state['baseline']) ? $state['baseline'] : null,
            'can_manage' => Security::can('site.manage'),
            'current_user' => $currentUser !== null ? (string)$currentUser['username'] : '',
        ];
        render('layout_head', ['page_title' => '网站历史档']);
        render('header');
        render('archive', $data);
        render('footer');
        render('layout_tail');
    }
}
