<?php

namespace App\Support;

use App\Config;

/**
 * 缓存抽象层 — 自动选择后端
 *
 *   1. Redis（当 phpredis 扩展可用且 APP_REDIS_HOST 已配置时）
 *   2. 文件缓存（降级方案，写入 var/cache/ 目录）
 *
 * 用法：
 *   $value = Cache::remember('topic:summarize', 60, function () {
 *       return TopicRepository::summarize();
 *   });
 *   Cache::forget('topic:summarize');
 */
class Cache
{
    private static $redis = null;
    private static $redisChecked = false;
    private static $fileDir = null;
    private static $config = null;

    /**
     * 从环境变量或缓存配置文件读取 Redis 连接参数
     * 环境变量优先；配置文件路径 APP_PATH/cache.config.php
     */
    private static function redisConfig()
    {
        if (self::$config !== null) {
            return self::$config;
        }
        $config = [
            'host' => '',
            'port' => 6379,
            'auth' => '',
            'db' => 0,
            'timeout' => 0.5,
        ];
        $envHost = getenv('APP_REDIS_HOST');
        if ($envHost !== false && $envHost !== '') {
            $config['host'] = $envHost;
            $envPort = getenv('APP_REDIS_PORT');
            if ($envPort !== false && $envPort !== '') {
                $config['port'] = (int)$envPort;
            }
            $envAuth = getenv('APP_REDIS_AUTH');
            if ($envAuth !== false && $envAuth !== '') {
                $config['auth'] = $envAuth;
            }
            $envDb = getenv('APP_REDIS_DB');
            if ($envDb !== false && $envDb !== '') {
                $config['db'] = (int)$envDb;
            }
            $envTimeout = getenv('APP_REDIS_TIMEOUT');
            if ($envTimeout !== false && $envTimeout !== '') {
                $config['timeout'] = (float)$envTimeout;
            }
            self::$config = $config;
            return $config;
        }
        $file = APP_PATH . '/cache.config.php';
        if (is_file($file)) {
            $loaded = @include $file;
            if (is_array($loaded)) {
                $config = array_merge($config, $loaded);
                if (isset($config['timeout'])) {
                    $config['timeout'] = (float)$config['timeout'];
                }
                if (isset($config['port'])) {
                    $config['port'] = (int)$config['port'];
                }
                if (isset($config['db'])) {
                    $config['db'] = (int)$config['db'];
                }
            }
        }
        self::$config = $config;
        return $config;
    }

    private static function redisClient()
    {
        if (self::$redisChecked) {
            return self::$redis;
        }
        self::$redisChecked = true;
        if (!extension_loaded('redis') || !class_exists('Redis')) {
            return null;
        }
        $cfg = self::redisConfig();
        if ($cfg['host'] === '') {
            return null;
        }
        try {
            $redis = new \Redis();
            if (!$redis->connect($cfg['host'], (int)$cfg['port'], (float)$cfg['timeout'])) {
                return null;
            }
            if ($cfg['auth'] !== '') {
                $redis->auth($cfg['auth']);
            }
            if ((int)$cfg['db'] > 0) {
                $redis->select((int)$cfg['db']);
            }
            $redis->setOption(\Redis::OPT_PREFIX, 'sops:');
            self::$redis = $redis;
        } catch (\Throwable $e) {
            self::$redis = null;
        }
        return self::$redis;
    }

    private static function fileDir()
    {
        if (self::$fileDir !== null) {
            return self::$fileDir;
        }
        $dir = Config::varDir() . '/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        self::$fileDir = $dir;
        return $dir;
    }

    private static function fileKey($key)
    {
        return md5($key);
    }

    public static function hasRedis()
    {
        return self::redisClient() !== null;
    }

    public static function get($key, $default = null)
    {
        $redis = self::redisClient();
        if ($redis !== null) {
            try {
                $raw = $redis->get($key);
                if ($raw === false || $raw === null) {
                    return $default;
                }
                $value = unserialize($raw);
                return $value === false && $raw !== serialize(false) ? $default : $value;
            } catch (\Throwable $e) {
                return $default;
            }
        }
        $file = self::fileDir() . '/' . self::fileKey($key) . '.cache';
        if (!is_file($file)) {
            return $default;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return $default;
        }
        $data = @unserialize($raw);
        if (!is_array($data) || !isset($data['expiry'], $data['value'])) {
            return $default;
        }
        if ($data['expiry'] > 0 && $data['expiry'] < time()) {
            @unlink($file);
            return $default;
        }
        return $data['value'];
    }

    public static function set($key, $value, $ttl = 0)
    {
        $redis = self::redisClient();
        if ($redis !== null) {
            try {
                $serialized = serialize($value);
                if ($ttl > 0) {
                    return $redis->setex($key, $ttl, $serialized);
                }
                return $redis->set($key, $serialized);
            } catch (\Throwable $e) {
                return false;
            }
        }
        $dir = self::fileDir();
        if (!is_dir($dir)) {
            return false;
        }
        $file = $dir . '/' . self::fileKey($key) . '.cache';
        $data = serialize([
            'value' => $value,
            'expiry' => $ttl > 0 ? time() + $ttl : 0,
        ]);
        return @file_put_contents($file, $data, LOCK_EX) !== false;
    }

    public static function forget($key)
    {
        $redis = self::redisClient();
        if ($redis !== null) {
            try {
                $redis->del($key);
            } catch (\Throwable $e) {
            }
        }
        $file = self::fileDir() . '/' . self::fileKey($key) . '.cache';
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public static function flushPrefix($prefix)
    {
        $redis = self::redisClient();
        if ($redis !== null) {
            try {
                $it = null;
                while ($keys = $redis->scan($it, 'sops:' . $prefix . '*')) {
                    foreach ($keys as $k) {
                        $redis->del(substr($k, 5));
                    }
                }
            } catch (\Throwable $e) {
            }
        }
        $dir = self::fileDir();
        if (!is_dir($dir)) {
            return;
        }
        $prefixHash = md5($prefix);
        foreach (glob($dir . '/*.cache') as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $data = @unserialize($raw);
            if (is_array($data) && isset($data['value'])) {
                @unlink($file);
            }
        }
    }

    public static function remember($key, $ttl, callable $callback)
    {
        $value = self::get($key);
        if ($value !== null) {
            return $value;
        }
        $value = $callback();
        if ($value !== null) {
            self::set($key, $value, $ttl);
        }
        return $value;
    }

    public static function reset()
    {
        self::$redis = null;
        self::$redisChecked = false;
        self::$fileDir = null;
        self::$config = null;
    }

    /**
     * 获取缓存运行状态
     */
    public static function status()
    {
        $cfg = self::redisConfig();
        $extensionLoaded = extension_loaded('redis') && class_exists('Redis');
        $redisConnected = self::redisClient() !== null;
        $redisInfo = null;
        if ($redisConnected) {
            try {
                $info = self::$redis->info();
                $redisInfo = [
                    'version' => isset($info['redis_version']) ? $info['redis_version'] : '',
                    'connected_clients' => isset($info['connected_clients']) ? $info['connected_clients'] : '',
                    'used_memory_human' => isset($info['used_memory_human']) ? $info['used_memory_human'] : '',
                    'uptime_in_days' => isset($info['uptime_in_days']) ? $info['uptime_in_days'] : '',
                    'db_size' => (int)self::$redis->dbsize(),
                ];
            } catch (\Throwable $e) {
                $redisInfo = ['error' => $e->getMessage()];
            }
        }
        $fileCount = 0;
        $fileSize = 0;
        $dir = self::fileDir();
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.cache') as $file) {
                $fileCount++;
                $fileSize += filesize($file);
            }
        }
        return [
            'backend' => $redisConnected ? 'redis' : 'file',
            'extension_loaded' => $extensionLoaded,
            'redis_connected' => $redisConnected,
            'redis_config' => $cfg,
            'redis_info' => $redisInfo,
            'file_dir' => $dir,
            'file_count' => $fileCount,
            'file_size' => $fileSize,
        ];
    }

    /**
     * 清空全部缓存（Redis + 文件）
     */
    public static function flushAll()
    {
        $redis = self::redisClient();
        $redisCleared = false;
        if ($redis !== null) {
            try {
                $redis->flushdb();
                $redisCleared = true;
            } catch (\Throwable $e) {
            }
        }
        $fileCleared = 0;
        $dir = self::fileDir();
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.cache') as $file) {
                if (@unlink($file)) {
                    $fileCleared++;
                }
            }
        }
        return [
            'redis_cleared' => $redisCleared,
            'file_cleared' => $fileCleared,
        ];
    }

    /**
     * 测试 Redis 连接（不使用缓存的单例，独立连接）
     */
    public static function testConnection($host, $port, $auth, $db, $timeout)
    {
        if (!extension_loaded('redis') || !class_exists('Redis')) {
            return ['ok' => false, 'error' => 'phpredis 扩展未安装'];
        }
        try {
            $redis = new \Redis();
            if (!$redis->connect($host, (int)$port, (float)$timeout)) {
                return ['ok' => false, 'error' => '连接失败'];
            }
            if ($auth !== '') {
                $redis->auth($auth);
            }
            if ((int)$db > 0) {
                $redis->select((int)$db);
            }
            $info = $redis->info();
            return [
                'ok' => true,
                'version' => isset($info['redis_version']) ? $info['redis_version'] : '',
                'uptime' => isset($info['uptime_in_days']) ? $info['uptime_in_days'] . ' 天' : '',
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 保存 Redis 配置到 cache.config.php
     */
    public static function saveConfig($host, $port, $auth, $db, $timeout)
    {
        $config = [
            'host' => (string)$host,
            'port' => (int)$port,
            'auth' => (string)$auth,
            'db' => (int)$db,
            'timeout' => (float)$timeout,
        ];
        $lines = [];
        $lines[] = '<?php';
        $lines[] = '/* 缓存配置 — 由【系统管理】→【缓存管理】页面维护 */';
        $lines[] = 'return [';
        foreach ($config as $key => $value) {
            $lines[] = "    '" . $key . "' => " . var_export($value, true) . ',';
        }
        $lines[] = '];';
        $file = APP_PATH . '/cache.config.php';
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return false;
        }
        return @file_put_contents($file, implode("\n", $lines) . "\n", LOCK_EX) !== false;
    }
}
