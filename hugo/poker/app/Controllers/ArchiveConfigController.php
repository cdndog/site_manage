<?php

namespace App\Controllers;

use App\Services\ArchiveConfigService;
use App\Support\Security;

/**
 * 【站点管理】→【历史档配置】：网站历史档的代理 / GitHub 账号 / README 模板 / 上传目录，
 * 全部以文件保存（Config::dataDir() . '/archive_config'），不入库。
 */
class ArchiveConfigController
{
    const SECTIONS = ['proxies', 'accounts', 'templates', 'uploads'];

    public static function dispatch()
    {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        try {
            Security::requireApiToken(true);
            Security::ensureUidCookie();
            if (!Security::requireSession()) {
                return;
            }
            Security::requirePermission('site.manage');
            $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
            if ($method === 'POST') {
                self::handlePost();
                return;
            }
            self::shell([
                'edit' => self::parseEdit(isset($_GET['edit']) ? (string)$_GET['edit'] : ''),
                'raw_kind' => self::parseRawKind(isset($_GET['raw']) ? (string)$_GET['raw'] : ''),
            ]);
        } catch (\Throwable $e) {
            renderErrorPage($e);
        }
    }

    private static function handlePost()
    {
        $csrf = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
        if (!Security::csrfVerify($csrf)) {
            http_response_code(403);
            render('error', ['message' => 'CSRF token invalid, please reload the page and try again.']);
            render('footer');
            render('layout_tail');
            return;
        }
        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
        $state = ['form' => $_POST];
        try {
            if ($action === 'save-item') {
                self::saveItem($state);
            } elseif ($action === 'delete-item') {
                self::deleteItem($state);
            } elseif ($action === 'save-content') {
                self::saveContent($state);
            } elseif ($action === 'save-base') {
                self::saveUploadsBase($state);
            } elseif ($action === 'save-json') {
                self::saveRawJson($state);
            } else {
                $state['error'] = '未知操作：' . $action;
            }
        } catch (\Throwable $e) {
            $state['error'] = $e->getMessage();
        }
        self::shell($state);
    }

    /** 解析 ?edit=section:id */
    private static function parseEdit(string $raw): array
    {
        if (strpos($raw, ':') === false) {
            return [];
        }
        list($section, $id) = explode(':', $raw, 2);
        if (!in_array($section, self::SECTIONS, true)) {
            return [];
        }
        return ['section' => $section, 'id' => $id];
    }

    /** 解析 ?raw=<kind>（原始 JSON 编辑对象），默认 proxy */
    private static function parseRawKind(string $raw): string
    {
        if (ArchiveConfigService::fileOf($raw) !== '') {
            return $raw;
        }
        return 'proxy';
    }

    /** 新增/编辑一条配置项 */
    private static function saveItem(array &$state)
    {
        $section = isset($_POST['section']) ? (string)$_POST['section'] : '';
        if (!in_array($section, self::SECTIONS, true)) {
            $state['error'] = '未知配置分组：' . $section;
            return;
        }
        $editingId = trim((string)(isset($_POST['item_id']) ? $_POST['item_id'] : ''));
        $name = trim((string)(isset($_POST['name']) ? $_POST['name'] : ''));

        if ($section === 'proxies') {
            $url = trim((string)(isset($_POST['url']) ? $_POST['url'] : ''));
            if ($url === '' || !preg_match('#^(https?|socks4|socks4a|socks5|socks5h)://[^\s]+$#i', $url)) {
                $state['error'] = '代理地址需形如 http://127.0.0.1:7890 或 socks5h://127.0.0.1:7890，未保存：' . $url;
                return;
            }
            if ($name === '') {
                $name = $url;
            }
            $id = self::resolveId($section, $editingId, $name);
            $list = self::replaceItem(ArchiveConfigService::proxies(), $id, ['id' => $id, 'name' => $name, 'url' => $url]);
            if (!ArchiveConfigService::saveProxies($list)) {
                $state['error'] = '写入配置文件失败（目录权限？）';
                return;
            }
            $state['notice'] = ($editingId !== '' ? '代理已更新：' : '代理已新增：') . $name;
            return;
        }

        if ($section === 'accounts') {
            $username = trim((string)(isset($_POST['username']) ? $_POST['username'] : ''));
            $token = (string)(isset($_POST['token']) ? $_POST['token'] : '');
            if ($username === '' || $token === '') {
                $state['error'] = 'GitHub 用户名与 Token 均为必填，未保存';
                return;
            }
            if ($name === '') {
                $name = $username;
            }
            $id = self::resolveId($section, $editingId, $name);
            $list = self::replaceItem(ArchiveConfigService::accounts(), $id, ['id' => $id, 'name' => $name, 'username' => $username, 'token' => $token]);
            if (!ArchiveConfigService::saveAccounts($list)) {
                $state['error'] = '写入配置文件失败（目录权限？）';
                return;
            }
            $state['notice'] = ($editingId !== '' ? '账号已更新：' : '账号已新增：') . $name;
            return;
        }

        if ($section === 'templates') {
            $file = trim((string)(isset($_POST['file']) ? $_POST['file'] : ''));
            if ($file === '') {
                $slug = ArchiveConfigService::safeId($editingId !== '' ? $editingId : $name);
                $file = ($slug !== '' ? $slug : 'template') . '.md';
            }
            $file = ArchiveConfigService::safeFileName($file);
            if ($file === '') {
                $state['error'] = '模板文件名不合法（仅字母数字 . _ -），未保存';
                return;
            }
            if ($name === '') {
                $name = $file;
            }
            $id = self::resolveId($section, $editingId, $name);
            $isDefault = !empty($_POST['is_default']);
            $list = self::replaceItem(ArchiveConfigService::templates(), $id, ['id' => $id, 'name' => $name, 'file' => $file, 'default' => $isDefault]);
            if ($isDefault) {
                foreach ($list as $i => $t) {
                    $list[$i]['default'] = ((string)($t['id'] ?? '') === $id);
                }
            }
            if (!ArchiveConfigService::saveTemplates($list)) {
                $state['error'] = '写入配置文件失败（目录权限？）';
                return;
            }
            if (!is_file(ArchiveConfigService::templatesDir() . '/' . $file)) {
                ArchiveConfigService::saveTemplateContent($file, ArchiveConfigService::defaultTemplateBody());
            }
            $state['notice'] = ($editingId !== '' ? '模板已更新：' : '模板已新增：') . $name;
            return;
        }

        // uploads：上传目录条目
        $path = trim((string)(isset($_POST['path']) ? $_POST['path'] : ''));
        if ($path === '' || strpos($path, '..') !== false) {
            $state['error'] = '目录路径不能为空且不得包含 .. ，未保存：' . $path;
            return;
        }
        if ($name === '') {
            $name = $path;
        }
        $id = self::resolveId($section, $editingId, $name);
        $data = ArchiveConfigService::uploads();
        $dirs = isset($data['upload_dirs']) && is_array($data['upload_dirs']) ? $data['upload_dirs'] : [];
        $dirs = self::replaceItem($dirs, $id, [
            'id' => $id,
            'label' => $name,
            'path' => $path,
            'repo_prefix' => trim((string)(isset($_POST['repo_prefix']) ? $_POST['repo_prefix'] : '')),
        ]);
        $data['upload_dirs'] = $dirs;
        if (!ArchiveConfigService::saveUploads($data)) {
            $state['error'] = '写入配置文件失败（目录权限？）';
            return;
        }
        $state['notice'] = ($editingId !== '' ? '上传目录已更新：' : '上传目录已新增：') . $name;
    }

    /** 删除一条配置项 */
    private static function deleteItem(array &$state)
    {
        $section = isset($_POST['section']) ? (string)$_POST['section'] : '';
        $id = trim((string)(isset($_POST['item_id']) ? $_POST['item_id'] : ''));
        if (!in_array($section, self::SECTIONS, true)) {
            $state['error'] = '未知配置分组：' . $section;
            return;
        }
        if ($id === '') {
            $state['error'] = '缺少待删除项 id';
            return;
        }
        if ($section === 'proxies') {
            $list = self::removeItem(ArchiveConfigService::proxies(), $id);
            $ok = ArchiveConfigService::saveProxies($list);
        } elseif ($section === 'accounts') {
            $list = self::removeItem(ArchiveConfigService::accounts(), $id);
            $ok = ArchiveConfigService::saveAccounts($list);
        } elseif ($section === 'templates') {
            $list = self::removeItem(ArchiveConfigService::templates(), $id);
            $ok = ArchiveConfigService::saveTemplates($list);
        } else {
            $data = ArchiveConfigService::uploads();
            $dirs = isset($data['upload_dirs']) && is_array($data['upload_dirs']) ? $data['upload_dirs'] : [];
            $data['upload_dirs'] = self::removeItem($dirs, $id);
            $ok = ArchiveConfigService::saveUploads($data);
        }
        if (!$ok) {
            $state['error'] = '写入配置文件失败（目录权限？）';
            return;
        }
        $state['notice'] = '已删除配置项：' . $id;
    }

    /** 保存 README 模板正文 */
    private static function saveContent(array &$state)
    {
        $id = trim((string)(isset($_POST['item_id']) ? $_POST['item_id'] : ''));
        $content = (string)(isset($_POST['content']) ? $_POST['content'] : '');
        $tpl = ArchiveConfigService::templateById($id);
        if ($tpl === null) {
            $state['error'] = '模板不存在：' . $id;
            return;
        }
        if (!ArchiveConfigService::saveTemplateContent((string)($tpl['file'] ?? ''), $content)) {
            $state['error'] = '写入模板文件失败（目录权限？）';
            return;
        }
        $state['notice'] = '模板正文已保存：' . ($tpl['name'] ?? $id);
        $state['edit'] = ['section' => 'templates', 'id' => $id];
    }

    /** 保存上传根目录 base */
    private static function saveUploadsBase(array &$state)
    {
        $base = trim((string)(isset($_POST['base']) ? $_POST['base'] : ''));
        $data = ArchiveConfigService::uploads();
        $data['base'] = $base;
        if (!ArchiveConfigService::saveUploads($data)) {
            $state['error'] = '写入配置文件失败（目录权限？）';
            return;
        }
        $state['notice'] = '上传根目录已保存：' . ($base !== '' ? $base : '（空 = 相对项目根）');
    }

    /**
     * 原始 JSON 保存：按白名单选定文件，整份校验后写入
     * （可直接粘贴 wbm/config 中的 JSON 结构；缺顶层键/非法 JSON 拒绝）。
     */
    private static function saveRawJson(array &$state)
    {
        $kind = isset($_POST['kind']) ? (string)$_POST['kind'] : '';
        $file = ArchiveConfigService::fileOf($kind);
        if ($file === '') {
            $state['error'] = '未知配置文件：' . $kind;
            return;
        }
        $raw = (string)(isset($_POST['json']) ? $_POST['json'] : '');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $state['error'] = 'JSON 格式错误，未保存（' . json_last_error_msg() . '）';
            return;
        }
        $key = ArchiveConfigService::REQUIRED_KEY[$kind] ?? '';
        if ($key !== '' && (!isset($decoded[$key]) || !is_array($decoded[$key]))) {
            $state['error'] = '结构不符：顶层需包含数组键 "' . $key . '"（与 wbm/config 一致），未保存';
            return;
        }
        if ($kind === 'uploads' && isset($decoded['upload_dirs'])) {
            foreach ((array)$decoded['upload_dirs'] as $d) {
                $p = is_array($d) ? (string)($d['path'] ?? '') : '';
                if ($p !== '' && strpos($p, '..') !== false) {
                    $state['error'] = '上传目录 path 不得包含 ..，未保存：' . $p;
                    return;
                }
            }
        }
        if (!ArchiveConfigService::saveJson($file, $decoded)) {
            $state['error'] = '写入配置文件失败（目录权限？）';
            return;
        }
        $state['notice'] = '已保存原始 JSON：' . $file;
        $state['raw_kind'] = $kind;
    }

    /** 解析/生成 id：编辑沿用原 id；新增按名称生成，冲突时追加序号 */
    private static function resolveId(string $section, string $editingId, string $name): string
    {
        if ($editingId !== '') {
            return $editingId;
        }
        $base = ArchiveConfigService::safeId($name);
        if ($base === '') {
            $base = $section . '-' . substr(uniqid('', true), 0, 6);
        }
        $existing = [];
        if ($section === 'proxies') {
            $existing = ArchiveConfigService::proxies();
        } elseif ($section === 'accounts') {
            $existing = ArchiveConfigService::accounts();
        } elseif ($section === 'templates') {
            $existing = ArchiveConfigService::templates();
        } else {
            $data = ArchiveConfigService::uploads();
            $existing = isset($data['upload_dirs']) && is_array($data['upload_dirs']) ? $data['upload_dirs'] : [];
        }
        $id = $base;
        $n = 2;
        $taken = [];
        foreach ($existing as $it) {
            $taken[(string)($it['id'] ?? '')] = true;
        }
        while (isset($taken[$id])) {
            $id = $base . '-' . $n;
            $n++;
        }
        return $id;
    }

    private static function replaceItem(array $list, string $id, array $item): array
    {
        foreach ($list as $i => $it) {
            if ((string)($it['id'] ?? '') === $id) {
                $list[$i] = $item;
                return $list;
            }
        }
        $list[] = $item;
        return $list;
    }

    private static function removeItem(array $list, string $id): array
    {
        $out = [];
        foreach ($list as $it) {
            if ((string)($it['id'] ?? '') !== $id) {
                $out[] = $it;
            }
        }
        return $out;
    }

    private static function shell(array $state = [])
    {
        $rawKind = isset($state['raw_kind']) && ArchiveConfigService::fileOf((string)$state['raw_kind']) !== ''
            ? (string)$state['raw_kind']
            : 'proxy';
        $data = [
            'proxies' => ArchiveConfigService::proxies(),
            'accounts' => ArchiveConfigService::accounts(),
            'templates' => ArchiveConfigService::templates(),
            'uploads' => ArchiveConfigService::uploads(),
            'csrf_token' => Security::csrfToken(),
            'error' => isset($state['error']) ? (string)$state['error'] : '',
            'notice' => isset($state['notice']) ? (string)$state['notice'] : '',
            'form' => isset($state['form']) && is_array($state['form']) ? $state['form'] : [],
            'edit' => isset($state['edit']) && is_array($state['edit']) ? $state['edit'] : [],
            'config_dir' => ArchiveConfigService::dir(),
            'templates_dir' => ArchiveConfigService::templatesDir(),
            'uploads_base_dir' => ArchiveConfigService::uploadsBaseDir(),
            // 原始 JSON 编辑器
            'raw_kinds' => ArchiveConfigService::FILES,
            'raw_kind' => $rawKind,
            'raw_json' => ArchiveConfigService::rawJson($rawKind),
        ];
        render('layout_head', ['page_title' => '历史档配置']);
        render('header');
        render('archive_config', $data);
        render('footer');
        render('layout_tail');
    }
}
