<?php

namespace App;

class Config
{
    private static $cache = null;
    private static $dbFileOverride = null;

    public static function reset()
    {
        self::$cache = null;
        self::$dbDictCache = null;
    }

    public static function overrideDbFile($path)
    {
        self::$dbFileOverride = $path !== null ? (string)$path : null;
    }

    private static $dbDictCache = null;

    public static function configFile()
    {
        return APP_PATH . '/global_config.php';
    }

    public static function dictionaryKeys()
    {
        return ['languages', 'countries', 'categories', 'statuses', 'series', 'pubdir', 'themetype', 'sitetype'];
    }

    public static function dictionaryDescriptions()
    {
        return [
            'languages' => '语言字典：表单语言下拉选项（label => 语言代码）',
            'countries' => '国家字典：表单国家下拉选项（label => 国家代码）',
            'categories' => '分类字典：站点/文章分类枚举',
            'statuses' => '状态字典：记录状态枚举（enable/disable/draft/aidone/delete）',
            'series' => '系列字典：内容系列枚举（poker/story/skill/other）',
            'pubdir' => '发布目录：关键词发布目录下拉选项',
            'themetype' => '模板类型：站点模板类型下拉选项',
            'sitetype' => '站点归类：站点归类下拉选项（traffic/cta/purecta）',
        ];
    }

    private static function dbDictionary()
    {
        if (self::$dbDictCache !== null) {
            return self::$dbDictCache;
        }
        self::$dbDictCache = [];
        if (!class_exists('\App\Database')) {
            return self::$dbDictCache;
        }
        try {
            $rows = \App\Database::fetchAll('SELECT "config_key", "config_value" FROM "app_configs"');
        } catch (\Throwable $e) {
            return self::$dbDictCache;
        }
        foreach ($rows as $row) {
            $value = json_decode((string)$row['config_value'], true);
            if (is_array($value)) {
                self::$dbDictCache[$row['config_key']] = $value;
            }
        }
        return self::$dbDictCache;
    }

    public static function all()
    {
        if (self::$cache === null) {
            $configFile = self::configFile();
            if (!file_exists($configFile)) {
                die('file global_config.php not found.');
            }
            $config = include $configFile;
            self::$cache = is_array($config) ? $config : [];
        }
        $merged = self::$cache;
        foreach (self::dbDictionary() as $key => $value) {
            $merged[$key] = $value;
        }
        return $merged;
    }

    public static function get($key, $default = null)
    {
        $config = self::all();
        return isset($config[$key]) ? $config[$key] : $default;
    }

    public static function wechatConfigFile()
    {
        $env = getenv('APP_WECHAT_COOKIE_FILE');
        if ($env !== false && $env !== '') {
            return $env;
        }
        return APP_PATH . '/wechat.cookie.php';
    }

    public static function wechatConfig()
    {
        $defaults = [
            'http_headers' => [
                'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
                'Accept-Encoding: identity',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
                'Upgrade-Insecure-Requests: 1',
                'Sec-Ch-Ua: "Not=A?Brand";v="99", "Google Chrome";v="151", "Chromium";v="151"',
                'Sec-Ch-Ua-Mobile: ?0',
                'Sec-Ch-Ua-Platform: "macOS"',
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Site: none',
                'Sec-Fetch-User: ?1',
            ],
            'cookie' => '',
            'wechat_forwarded_for' => '',
            'proxy' => '',
            'connect_timeout' => 15,
            'timeout' => 30,
            'max_retries' => 2,
        ];
        $config = self::all();
        $merged = array_merge($defaults, isset($config['wechat']) && is_array($config['wechat']) ? $config['wechat'] : []);
        $cookieFile = self::wechatConfigFile();
        if (file_exists($cookieFile)) {
            $cookieConfig = include $cookieFile;
            if (is_array($cookieConfig)) {
                $merged = array_merge($merged, $cookieConfig);
                if (empty($cookieConfig['http_headers'])) {
                    unset($merged['http_headers']);
                    $merged['http_headers'] = $defaults['http_headers'];
                }
            }
        }
        return $merged;
    }

    public static function dbFile()
    {
        if (self::$dbFileOverride !== null) {
            return self::$dbFileOverride;
        }
        $env = getenv('APP_DB_FILE');
        if ($env !== false && $env !== '') {
            return $env;
        }
        $configured = self::rawBaseValue('database');
        if (is_string($configured) && trim($configured) !== '') {
            $db = trim($configured);
            if (self::isAbsolutePath($db)) {
                return $db;
            }
            return APP_PATH . '/' . ltrim($db, '/');
        }
        return APP_PATH . '/sitedata.sqlite';
    }

    private static function isAbsolutePath($path)
    {
        if ($path === '') {
            return false;
        }
        return $path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }

    private static function rawBaseValue($key)
    {
        $file = self::configFile();
        if (!is_file($file)) {
            return null;
        }
        $config = include $file;
        return is_array($config) && isset($config['base'][$key]) ? $config['base'][$key] : null;
    }

    public static function dataDir()
    {
        $env = getenv('APP_DATA_DIR');
        if ($env !== false && $env !== '') {
            return $env;
        }
        return APP_PATH;
    }

    public static function gitServerIps()
    {
        $servers = self::get('gitserver', []);
        $ips = [];
        if (is_array($servers)) {
            foreach ($servers as $value) {
                $ip = trim((string)$value);
                if ($ip !== '') {
                    $ips[] = $ip;
                }
            }
        }
        return array_values(array_unique($ips));
    }

    public static function baseKey($key, $default = null)
    {
        $config = self::all();
        return isset($config['base'][$key]) ? $config['base'][$key] : $default;
    }

    public static function headerModules()
    {
        $configured = self::baseKey('header_modules', null);
        if (is_array($configured) && count($configured) > 0) {
            return array_values($configured);
        }
        return [
            [
                'title' => '站点管理',
                'url' => 'siteops.php',
                'icon' => 'bi-pencil-square',
                'perm' => 'site.manage',
                'children' => [
                    ['title' => '新建站点', 'url' => 'siteops.php', 'icon' => 'bi-plus-circle', 'perm' => 'site.manage'],
                    ['title' => '站点列表', 'url' => 'seo_report.php?reporttype=sitelist', 'icon' => 'bi-globe', 'perm' => 'site.view'],
                ],
            ],
            [
                'title' => '关键词管理',
                'url' => 'keywordops.php',
                'icon' => 'bi-search',
                'perm' => 'keyword.manage',
                'children' => [
                    ['title' => '新增关键词', 'url' => 'keywordops.php', 'icon' => 'bi-plus-circle', 'perm' => 'keyword.manage'],
                    ['title' => '关键词列表', 'url' => 'seo_report.php?reporttype=wordlist', 'icon' => 'bi-list-check', 'perm' => 'keyword.view'],
                    ['title' => '关联关键词', 'url' => 'seo_report.php?reporttype=relateword', 'icon' => 'bi-link-45deg', 'perm' => 'keyword.view'],
                ],
            ],
            [
                'title' => '话题管理',
                'url' => 'topicops.php',
                'icon' => 'bi-journal-text',
                'perm' => 'topic.manage',
                'children' => [
                    ['title' => '新增话题', 'url' => 'topicops.php', 'icon' => 'bi-plus-circle', 'perm' => 'topic.manage'],
                    ['title' => '话题列表', 'url' => 'topiclist.php', 'icon' => 'bi-journal-richtext', 'perm' => 'topic.view'],
                    ['title' => '话题报表', 'url' => 'topictable.php', 'icon' => 'bi-bar-chart-line', 'perm' => 'topic.view'],
                ],
            ],
            [
                'title' => '文章管理',
                'url' => 'article_new.php',
                'icon' => 'bi-file-earmark-text',
                'perm' => 'article.manage',
                'children' => [
                    ['title' => '新建文章', 'url' => 'article_new.php', 'icon' => 'bi-plus-circle', 'perm' => 'article.manage'],
                    ['title' => '文章列表', 'url' => 'article_list.php', 'icon' => 'bi-list-ul', 'perm' => 'article.view'],
                    ['title' => '微信导入', 'url' => 'wechat_import.php', 'icon' => 'bi-wechat', 'perm' => 'article.manage'],
                ],
            ],
            [
                'title' => '系统管理',
                'url' => 'users.php',
                'icon' => 'bi-shield-lock',
                'perm' => 'user.manage',
                'children' => [
                    ['title' => '用户管理', 'url' => 'users.php', 'icon' => 'bi-people', 'perm' => 'user.manage'],
                    ['title' => '角色权限', 'url' => 'roles.php', 'icon' => 'bi-shield-check', 'perm' => 'user.manage'],
                    ['title' => '配置管理', 'url' => 'config_list.php', 'icon' => 'bi-sliders', 'perm' => 'config.manage'],
                    ['title' => '微信cookie配置', 'url' => 'wechat_cookie.php', 'icon' => 'bi-cookie', 'perm' => 'config.manage'],
                    ['title' => '缓存管理', 'url' => 'cache_manage.php', 'icon' => 'bi-lightning-charge', 'perm' => 'config.manage'],
                ],
            ],
        ];
    }

    public static function headerModulesFor(array $permissions)
    {
        $modules = self::headerModules();
        $has = function ($required) use ($permissions) {
            if ($required === null || $required === '') {
                return true;
            }
            $codes = is_array($required) ? $required : [$required];
            foreach ($codes as $code) {
                if (in_array($code, $permissions, true)) {
                    return true;
                }
            }
            return false;
        };
        $filtered = [];
        foreach ($modules as $module) {
            $children = isset($module['children']) && is_array($module['children']) ? $module['children'] : [];
            $keptChildren = [];
            foreach ($children as $child) {
                if ($has(isset($child['perm']) ? $child['perm'] : null)) {
                    $keptChildren[] = $child;
                }
            }
            if (!$has(isset($module['perm']) ? $module['perm'] : null)) {
                continue;
            }
            if (count($children) > 0 && count($keptChildren) === 0) {
                continue;
            }
            if (count($children) > 0) {
                $module['children'] = $keptChildren;
            }
            $filtered[] = $module;
        }
        return $filtered;
    }

    public static function permissions()
    {
        return [
            'site.view' => ['name' => '站点-查看', 'description' => '查看站点列表'],
            'site.manage' => ['name' => '站点-录入编辑', 'description' => '新建/编辑/提交站点'],
            'keyword.view' => ['name' => '关键词-查看', 'description' => '查看关键词列表与关联'],
            'keyword.manage' => ['name' => '关键词-录入编辑', 'description' => '新建/编辑/提交关键词'],
            'topic.view' => ['name' => '话题-查看', 'description' => '查看话题列表与报表'],
            'topic.manage' => ['name' => '话题-录入编辑', 'description' => '新建/编辑/提交话题'],
            'article.view' => ['name' => '文章-查看', 'description' => '查看文章列表'],
            'article.manage' => ['name' => '文章-录入编辑', 'description' => '新建/编辑/保存文章'],
            'report.view' => ['name' => '报表-查看', 'description' => '查看 SEO 报表'],
            'user.manage' => ['name' => '系统-用户角色管理', 'description' => '管理用户与角色权限'],
            'config.manage' => ['name' => '系统-配置管理', 'description' => '管理业务字典配置'],
        ];
    }

    public static function roles()
    {
        return [
            'admin' => ['description' => '超级管理员，拥有全部权限'],
            'editor' => ['description' => '录入编辑：可录入站点/关键词/话题并查看'],
            'viewer' => ['description' => '只读：仅可查看列表与报表'],
        ];
    }

    public static function rolePermissionMap()
    {
        return [
            'admin' => array_keys(self::permissions()),
            'editor' => ['site.manage', 'site.view', 'keyword.manage', 'keyword.view', 'topic.manage', 'topic.view', 'article.manage', 'article.view', 'report.view'],
            'viewer' => ['site.view', 'keyword.view', 'topic.view', 'article.view', 'report.view'],
        ];
    }

    public static function imgbbKeys()
    {
        $env = getenv('IMGBB_API_KEY');
        if ($env !== false && $env !== '') {
            $keys = array_map('trim', explode(',', $env));
            return array_values(array_filter($keys, function ($key) {
                return $key !== '';
            }));
        }
        $configured = self::baseKey('imgbb_api_key', null);
        if (is_array($configured)) {
            $keys = array_map('trim', $configured);
            return array_values(array_filter($keys, function ($key) {
                return $key !== '';
            }));
        }
        if (is_string($configured) && $configured !== '') {
            return [$configured];
        }
        return [];
    }

    public static function apiCsrfTokens()
    {
        $env = getenv('APP_API_CSRF_TOKENS');
        if ($env !== false && $env !== '') {
            $keys = array_map('trim', explode(',', $env));
            return array_values(array_filter($keys, function ($key) {
                return $key !== '';
            }));
        }
        $configured = self::baseKey('api_csrf_tokens', null);
        if (is_array($configured)) {
            $keys = array_map('trim', $configured);
            return array_values(array_filter($keys, function ($key) {
                return $key !== '';
            }));
        }
        if (is_string($configured) && $configured !== '') {
            return [$configured];
        }
        return [];
    }

    public static function imgbbRotationKeys()
    {
        $keys = self::imgbbKeys();
        $count = count($keys);
        if ($count < 2) {
            return $keys;
        }
        $file = self::varDir() . '/imgbb_key_index';
        if (!is_dir(self::varDir())) {
            @mkdir(self::varDir(), 0755, true);
        }
        $index = 0;
        $handle = @fopen($file, 'c+');
        if ($handle !== false) {
            if (flock($handle, LOCK_EX)) {
                $content = trim((string)stream_get_contents($handle));
                rewind($handle);
                ftruncate($handle, 0);
                $index = ctype_digit($content) ? ((int)$content + 1) % $count : 0;
                fwrite($handle, (string)$index);
                fflush($handle);
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        } else {
            static $memIndex = 0;
            $index = $memIndex++ % $count;
        }
        $rotated = [];
        for ($i = 0; $i < $count; $i++) {
            $rotated[] = $keys[($index + $i) % $count];
        }
        return $rotated;
    }

    public static function varDir()
    {
        $env = getenv('APP_VAR_DIR');
        if ($env !== false && $env !== '') {
            return $env;
        }
        return APP_PATH . '/var';
    }

    public static function imgbbUploadUrl()
    {
        $env = getenv('IMGBB_API_URL');
        if ($env !== false && $env !== '') {
            return $env;
        }
        return 'https://api.imgbb.com/1/upload';
    }

    public static function csrfSecret()
    {
        $env = getenv('APP_CSRF_SECRET');
        if ($env !== false && $env !== '') {
            return $env;
        }
        $varDir = self::varDir();
        $file = $varDir . '/csrf_secret';
        if (is_file($file)) {
            $secret = trim((string)file_get_contents($file));
            if ($secret !== '') {
                return $secret;
            }
        }
        if (!is_dir($varDir)) {
            @mkdir($varDir, 0755, true);
        }
        if (is_dir($varDir) && is_writable($varDir)) {
            $secret = bin2hex(random_bytes(32));
            @file_put_contents($file, $secret);
            return $secret;
        }
        return hash('sha256', 'siteops|' . APP_PATH);
    }

    public static function authSecret()
    {
        $env = getenv('APP_AUTH_SECRET');
        if ($env !== false && $env !== '') {
            return $env;
        }
        return self::csrfSecret();
    }

    public static function authUser()
    {
        $env = getenv('APP_AUTH_USER');
        if ($env !== false && $env !== '') {
            return $env;
        }
        return null;
    }

    public static function authPassword()
    {
        $env = getenv('APP_AUTH_PASSWORD');
        if ($env !== false && $env !== '') {
            return $env;
        }
        return null;
    }
}
