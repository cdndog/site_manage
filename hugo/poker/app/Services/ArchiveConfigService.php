<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;

/**
 * 【网站历史档】配置 JSON 文件管理（对齐 wbm/config 的文件结构）。
 *
 * JSON 配置保存在 Config::dataDir() . '/config'（生产为项目根 config/，测试沙箱为
 * tests/tmp/data/config/），不入库：
 *   - proxy.json         代理预设 {proxies:[{id,name,url}], api_token?}（参考 proxy.json，多余顶层键原样保留）
 *   - accounts.json      GitHub 账号 {accounts:[{id,name,username,token}]}
 *   - templates.json     README 模板 {templates:[{id,name,file,default}]}（file 指向项目根 templates/*.md）
 *   - uploads.json       上传目录 {base, upload_dirs:[{id,label,path,repo_prefix}]}（path 相对 base）
 *   - created_repos.json 已创建仓库记录 {repos:[...], updated_at}
 *
 * 模板正文：项目根 templates/*.md（与 wbm/templates 同构，随项目一起提交）。
 * 上传目录：项目根 upload/{scripts,static}（与 wbm/upload 同构），路径相对项目根解析。
 */
class ArchiveConfigService
{
    const DIR_NAME = 'config';
    const TEMPLATES_DIR_NAME = 'templates';
    const UPLOADS_DIR_NAME = 'upload';

    /** 文件名（kind => 文件名），供原始 JSON 编辑白名单使用 */
    const FILES = [
        'proxy' => 'proxy.json',
        'accounts' => 'accounts.json',
        'templates' => 'templates.json',
        'uploads' => 'uploads.json',
    ];

    /** 各 JSON 必须具备的顶层键（结构校验） */
    const REQUIRED_KEY = [
        'proxy' => 'proxies',
        'accounts' => 'accounts',
        'templates' => 'templates',
        'uploads' => 'upload_dirs',
    ];

    /** JSON 配置目录 */
    public static function dir(): string
    {
        return Config::dataDir() . '/' . self::DIR_NAME;
    }

    /** README 模板正文目录（项目根 templates/，与 wbm/templates 一致） */
    public static function templatesDir(): string
    {
        return APP_PATH . '/' . self::TEMPLATES_DIR_NAME;
    }

    /** 上传目录根（项目根 upload/，与 wbm/upload 一致） */
    public static function uploadsBaseDir(): string
    {
        return APP_PATH . '/' . self::UPLOADS_DIR_NAME;
    }

    /** 确保 JSON 配置目录存在 */
    public static function ensureDirs(): bool
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        return true;
    }

    /** 读取 JSON 文件；不存在/损坏返回 [] */
    public static function loadJson(string $name)
    {
        $path = self::dir() . '/' . $name;
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string)@file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    /** 写入 JSON 文件（原子写：临时文件 + rename）；返回是否成功 */
    public static function saveJson(string $name, array $data): bool
    {
        if (!self::ensureDirs()) {
            return false;
        }
        $path = self::dir() . '/' . $name;
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            return false;
        }
        $tmp = $path . '.' . uniqid('', true) . '.tmp';
        if (@file_put_contents($tmp, $json . "\n") === false) {
            return false;
        }
        return @rename($tmp, $path);
    }

    /** 读取某配置的整份 JSON（文件缺失时用默认结构补齐），供原始 JSON 编辑器展示 */
    public static function rawJson(string $kind): string
    {
        $file = self::fileOf($kind);
        if ($file === '') {
            return '';
        }
        $data = self::loadJson($file);
        $data = self::withDefaults($kind, $data);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        return $json === false ? '' : $json;
    }

    /** kind → 文件名（白名单外返回 ''） */
    public static function fileOf(string $kind): string
    {
        return isset(self::FILES[$kind]) ? self::FILES[$kind] : '';
    }

    /** 文件缺失/缺键时用默认结构补齐顶层键（不覆盖已有数据） */
    private static function withDefaults(string $kind, array $data): array
    {
        $key = self::REQUIRED_KEY[$kind] ?? null;
        if ($key === null) {
            return $data;
        }
        if (!isset($data[$key]) || !is_array($data[$key])) {
            $def = self::defaults($kind === 'proxy' ? 'proxies' : $kind);
            $data[$key] = $def;
        }
        return $data;
    }

    /**
     * 默认结构（缺文件时的内存态，参考 wbm/config 各 JSON）。
     * proxy 默认仅给本地预设（凭据类地址由使用者自行录入，不硬编码进代码）。
     */
    public static function defaults(string $kind): array
    {
        if ($kind === 'proxies') {
            return [
                ['id' => 'clash-local', 'name' => 'Clash 本地（HTTP）', 'url' => 'http://127.0.0.1:7890'],
                ['id' => 'socks-local', 'name' => '本地 SOCKS5(h)（DNS 走代理）', 'url' => 'socks5h://127.0.0.1:7890'],
            ];
        }
        if ($kind === 'accounts') {
            return [
                ['id' => 'wpkcdn', 'name' => '账号 (wpkcdn)', 'username' => 'wpkcdn', 'token' => ''],
                ['id' => 'cdndog', 'name' => '账号 (cdndog)', 'username' => 'cdndog', 'token' => ''],
            ];
        }
        if ($kind === 'templates') {
            return [
                ['id' => 'waybackmachine-site', 'name' => '网站数据先恢复（耗时长）', 'file' => 'waybackmachine-site.md'],
                ['id' => 'waybackmachine-lite-restore', 'name' => '先恢复网站首页数据', 'file' => 'waybackmachine-lite-restore.md', 'default' => true],
            ];
        }
        if ($kind === 'uploads') {
            return [
                'base' => 'upload',
                'upload_dirs' => [
                    ['id' => 'scripts', 'label' => '脚本目录', 'path' => 'scripts', 'repo_prefix' => 'scripts'],
                    ['id' => 'static', 'label' => '静态资源目录', 'path' => 'static', 'repo_prefix' => 'static'],
                ],
            ];
        }
        return [];
    }

    /** 代理预设列表（proxy.json） */
    public static function proxies(): array
    {
        $file = self::fileOf('proxy');
        if (!is_file(self::dir() . '/' . $file)) {
            return self::defaults('proxies');
        }
        $data = self::loadJson($file);
        return isset($data['proxies']) && is_array($data['proxies']) ? $data['proxies'] : [];
    }

    /** 按 id 取代理地址；未命中返回 '' */
    public static function proxyById(string $id): string
    {
        foreach (self::proxies() as $p) {
            if ((string)($p['id'] ?? '') === $id) {
                return (string)($p['url'] ?? '');
            }
        }
        return '';
    }

    /** 保存代理列表（保留 proxy.json 其它顶层键，如 api_token） */
    public static function saveProxies(array $list): bool
    {
        $file = self::fileOf('proxy');
        $data = self::loadJson($file);
        $data['proxies'] = array_values($list);
        return self::saveJson($file, $data);
    }

    /** GitHub 账号列表 */
    public static function accounts(): array
    {
        $file = self::fileOf('accounts');
        if (!is_file(self::dir() . '/' . $file)) {
            return self::defaults('accounts');
        }
        $data = self::loadJson($file);
        return isset($data['accounts']) && is_array($data['accounts']) ? $data['accounts'] : [];
    }

    /** 按 id 取账号；未命中返回 null */
    public static function accountById(string $id)
    {
        foreach (self::accounts() as $a) {
            if ((string)($a['id'] ?? '') === $id) {
                return $a;
            }
        }
        return null;
    }

    /** 保存账号列表（保留 accounts.json 其它顶层键） */
    public static function saveAccounts(array $list): bool
    {
        $file = self::fileOf('accounts');
        $data = self::loadJson($file);
        $data['accounts'] = array_values($list);
        return self::saveJson($file, $data);
    }

    /** README 模板列表 */
    public static function templates(): array
    {
        $file = self::fileOf('templates');
        if (!is_file(self::dir() . '/' . $file)) {
            return self::defaults('templates');
        }
        $data = self::loadJson($file);
        return isset($data['templates']) && is_array($data['templates']) ? $data['templates'] : [];
    }

    /** 默认模板（default=true）；无则 null */
    public static function defaultTemplate()
    {
        foreach (self::templates() as $t) {
            if (!empty($t['default'])) {
                return $t;
            }
        }
        return null;
    }

    /** 按 id 取模板项；未命中返回 null */
    public static function templateById(string $id)
    {
        foreach (self::templates() as $t) {
            if ((string)($t['id'] ?? '') === $id) {
                return $t;
            }
        }
        return null;
    }

    /** 保存模板列表（保留 templates.json 其它顶层键） */
    public static function saveTemplates(array $list): bool
    {
        $file = self::fileOf('templates');
        $data = self::loadJson($file);
        $data['templates'] = array_values($list);
        return self::saveJson($file, $data);
    }

    /** 读取模板正文：优先项目根 templates/<file>，缺失时返回内置默认正文 */
    public static function templateContent(string $file): string
    {
        $file = self::safeFileName($file);
        if ($file === '') {
            return self::defaultTemplateBody();
        }
        $path = self::templatesDir() . '/' . $file;
        if (is_file($path)) {
            return (string)@file_get_contents($path);
        }
        return self::defaultTemplateBody();
    }

    /** 保存模板正文（写入项目根 templates/<file>） */
    public static function saveTemplateContent(string $file, string $content): bool
    {
        $file = self::safeFileName($file);
        if ($file === '') {
            return false;
        }
        $dir = self::templatesDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        return @file_put_contents($dir . '/' . $file, $content) !== false;
    }

    /** 上传目录配置（uploads.json，缺文件时给 wbm/config 同款默认：base=upload + scripts/static） */
    public static function uploads(): array
    {
        $file = self::fileOf('uploads');
        if (!is_file(self::dir() . '/' . $file)) {
            $data = self::defaults('uploads');
        } else {
            $data = self::loadJson($file);
        }
        if (!isset($data['upload_dirs']) || !is_array($data['upload_dirs'])) {
            $data['upload_dirs'] = [];
        }
        if (!isset($data['base'])) {
            $data['base'] = '';
        }
        return $data;
    }

    /** 保存上传目录配置（保留 uploads.json 其它顶层键） */
    public static function saveUploads(array $data): bool
    {
        $file = self::fileOf('uploads');
        $existing = self::loadJson($file);
        $merged = array_merge($existing, $data);
        return self::saveJson($file, $merged);
    }

    /** 已创建仓库记录列表（新→旧） */
    public static function repos(): array
    {
        $data = self::loadJson('created_repos.json');
        $list = isset($data['repos']) && is_array($data['repos']) ? $data['repos'] : [];
        return array_reverse($list);
    }

    /** 追加一条已创建仓库记录 */
    public static function recordRepo(array $entry): bool
    {
        $data = self::loadJson('created_repos.json');
        $list = isset($data['repos']) && is_array($data['repos']) ? $data['repos'] : [];
        $list[] = $entry;
        return self::saveJson('created_repos.json', [
            'repos' => $list,
            'updated_at' => gmdate('c'),
        ]);
    }

    /** 生成安全的模板文件名（仅字母数字 . _ -，防路径穿越，去前导点） */
    public static function safeFileName(string $file): string
    {
        $file = basename(trim($file));
        $file = preg_replace('/[^A-Za-z0-9._-]/', '', $file);
        return ltrim((string)$file, '.');
    }

    /** 生成安全的配置 id（小写字母数字 . _ -） */
    public static function safeId(string $id): string
    {
        $id = strtolower(trim($id));
        $id = preg_replace('/[^a-z0-9._-]+/', '-', $id);
        return trim((string)$id, '-.');
    }

    /** 内置默认 README 模板正文（项目根模板缺失时的兜底，占位符与参考实现一致） */
    public static function defaultTemplateBody(): string
    {
        return "# {{SITE_NAME}}（归档恢复）\n\n"
            . "本仓库用于从 archive.org 时光机恢复 **{{ARCHIVE_DOMAIN}}** 的网站数据。\n\n"
            . "## 归档基线\n\n"
            . "- 网站名：{{SITE_NAME}}\n"
            . "- 归档基准日期：{{ARCHIVE_DATE}}\n"
            . "- 归档页面：{{ARCHIVE_PAGE}}\n"
            . "- 归档原始地址：{{ARCHIVE_URL}}\n"
            . "- 域名：{{ARCHIVE_DOMAIN}}\n\n"
            . "## 恢复步骤\n\n"
            . "1. 打开归档页面确认快照可用。\n"
            . "2. 按需抓取资源并回填本地目录。\n"
            . "3. 校验页面渲染与链接后部署。\n";
    }
}
