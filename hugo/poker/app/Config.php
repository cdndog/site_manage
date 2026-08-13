<?php

namespace App;

class Config
{
    private static $cache = null;

    public static function reset()
    {
        self::$cache = null;
    }

    public static function all()
    {
        if (self::$cache === null) {
            $configFile = APP_PATH . '/global_config.php';
            if (!file_exists($configFile)) {
                die('file global_config.php not found.');
            }
            $config = include $configFile;
            self::$cache = is_array($config) ? $config : [];
        }
        return self::$cache;
    }

    public static function get($key, $default = null)
    {
        $config = self::all();
        return isset($config[$key]) ? $config[$key] : $default;
    }

    public static function dbFile()
    {
        $env = getenv('APP_DB_FILE');
        if ($env !== false && $env !== '') {
            return $env;
        }
        return APP_PATH . '/sitedata.sqlite';
    }

    public static function dataDir()
    {
        $env = getenv('APP_DATA_DIR');
        if ($env !== false && $env !== '') {
            return $env;
        }
        return APP_PATH;
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
                'children' => [
                    ['title' => '新建站点', 'url' => 'siteops.php', 'icon' => 'bi-plus-circle'],
                    ['title' => '站点列表', 'url' => 'seo_report.php?reporttype=sitelist', 'icon' => 'bi-globe'],
                ],
            ],
            [
                'title' => '关键词管理',
                'url' => 'keywordops.php',
                'icon' => 'bi-search',
                'children' => [
                    ['title' => '新增关键词', 'url' => 'keywordops.php', 'icon' => 'bi-plus-circle'],
                    ['title' => '关键词列表', 'url' => 'seo_report.php?reporttype=wordlist', 'icon' => 'bi-list-check'],
                    ['title' => '关联关键词', 'url' => 'seo_report.php?reporttype=relateword', 'icon' => 'bi-link-45deg'],
                ],
            ],
            [
                'title' => '话题管理',
                'url' => 'topicops.php',
                'icon' => 'bi-journal-text',
                'children' => [
                    ['title' => '新增话题', 'url' => 'topicops.php', 'icon' => 'bi-plus-circle'],
                    ['title' => '话题列表', 'url' => 'topiclist.php', 'icon' => 'bi-journal-richtext'],
                    ['title' => '话题报表', 'url' => 'topictable.php', 'icon' => 'bi-bar-chart-line'],
                ],
            ],
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
