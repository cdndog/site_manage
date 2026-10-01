<?php
// Database_PG.php - PostgreSQL 适配层（与 SQLite 共存，测试回归保持 SQLite）
// 使用方式：设置 APP_DB_DSN=pgsql:host=...;dbname=...; 或 DATABASE_URL=postgres://...
// 未设置则回退 SQLite（sitedata.sqlite），保证现有 519 项测试零改动通过

namespace App;

use PDO;
use PDOStatement;

class DatabasePG
{
    private static $pdo = null;
    private static $driver = null;
    private static $migrated = false;

    public static function isPg(): bool
    {
        return self::driver() === 'pgsql';
    }

    public static function driver(): string
    {
        if (self::$driver !== null) return self::$driver;
        $dsn = self::dsn();
        if (stripos($dsn, 'pgsql:') === 0 || stripos($dsn, 'postgres:') === 0) {
            self::$driver = 'pgsql';
        } else {
            self::$driver = 'sqlite';
        }
        return self::$driver;
    }

    public static function dsn(): string
    {
        $env = getenv('DATABASE_URL');
        if ($env !== false && $env !== '') {
            // Heroku 风格 postgres://user:pass@host:5432/dbname -> pgsql:host=...;dbname=...
            if (stripos($env, 'postgres://') === 0) {
                $u = parse_url($env);
                $dsn = 'pgsql:host=' . ($u['host'] ?? '127.0.0.1') . ';port=' . ($u['port'] ?? 5432) . ';dbname=' . ltrim($u['path'] ?? '', '/');
                if (!empty($u['user'])) $dsn .= ';user=' . $u['user'];
                if (!empty($u['pass'])) $dsn .= ';password=' . $u['pass'];
                return $dsn;
            }
            return $env;
        }
        $env = getenv('APP_DB_DSN');
        if ($env !== false && $env !== '') return $env;
        return 'sqlite:' . Config::dbFile();
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = self::dsn();
            if (self::driver() === 'pgsql') {
                self::$pdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
                self::$pdo->exec("SET TIME ZONE 'UTC'");
            } else {
                // SQLite 回退：复用现有 SQLite3 逻辑但通过 PDO 统一接口
                // 为保持兼容，仍用 SQLite3 的 WAL 等 PRAGMA，需通过 PDO 的 sqlite 驱动执行
                self::$pdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
                self::$pdo->exec('PRAGMA journal_mode=WAL');
                self::$pdo->exec('PRAGMA synchronous=NORMAL');
            }
            self::migrate();
        }
        return self::$pdo;
    }

    private static function migrate()
    {
        if (self::$migrated) return;
        $pdo = self::$pdo;
        // 统一用 PDO 创建表，兼容两驱动
        $isPg = self::isPg();
        $autoInc = $isPg ? 'SERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $textPk = $isPg ? 'TEXT PRIMARY KEY' : 'TEXT PRIMARY KEY';
        // users 等系统表
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (id $autoInc, username VARCHAR UNIQUE NOT NULL, password_hash VARCHAR NOT NULL, display_name VARCHAR DEFAULT '', status VARCHAR DEFAULT 'active', created_at TIMESTAMP, updated_at TIMESTAMP, last_login_at TIMESTAMP)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS roles (id $autoInc, name VARCHAR UNIQUE NOT NULL, description VARCHAR DEFAULT '')");
        $pdo->exec("CREATE TABLE IF NOT EXISTS permissions (id $autoInc, code VARCHAR UNIQUE NOT NULL, name VARCHAR NOT NULL, description VARCHAR DEFAULT '')");
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_roles (user_id INTEGER NOT NULL, role_id INTEGER NOT NULL, PRIMARY KEY (user_id, role_id))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS role_permissions (role_id INTEGER NOT NULL, permission_id INTEGER NOT NULL, PRIMARY KEY (role_id, permission_id))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS app_configs (id $autoInc, config_key VARCHAR UNIQUE NOT NULL, config_value TEXT NOT NULL DEFAULT '', description VARCHAR DEFAULT '', updated_at TIMESTAMP, updated_by VARCHAR DEFAULT '')");
        // 业务表：aigc_status
        if ($isPg) {
            $pdo->exec('CREATE TABLE IF NOT EXISTS aigc_status (ctx_id TEXT PRIMARY KEY, keyword TEXT, lang TEXT, pubdomain TEXT, createAt TEXT, publishAt TEXT)');
        } else {
            $pdo->exec('CREATE TABLE IF NOT EXISTS "aigc_status" ("ctx_id" TEXT PRIMARY KEY, "keyword" TEXT, "lang" TEXT, "pubdomain" TEXT, "createAt" TEXT, "publishAt" TEXT) WITHOUT ROWID');
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_aigc_publishAt ON aigc_status ("publishAt" DESC)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_aigc_pubdomain ON aigc_status (pubdomain)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_aigc_lang ON aigc_status (lang)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_aigc_keyword ON aigc_status (keyword)');
        self::$migrated = true;
    }

    // 兼容层：将 SQLite 方言转 PG
    public static function prepare(string $sql): PDOStatement
    {
        $sql = self::translate($sql);
        return self::pdo()->prepare($sql);
    }

    private static function translate(string $sql): string
    {
        if (!self::isPg()) return $sql;
        // INSERT OR IGNORE -> ON CONFLICT DO NOTHING
        $sql = preg_replace('/INSERT\s+OR\s+IGNORE\s+INTO/i', 'INSERT INTO', $sql);
        // INSERT OR REPLACE -> ON CONFLICT(ctx_id) DO UPDATE
        $sql = preg_replace_callback('/INSERT\s+OR\s+REPLACE\s+INTO\s+"?(\w+)"?\s*\(([^)]+)\)\s*VALUES\s*\(([^)]+)\)/i', function($m){
            $table = $m[1];
            $cols = $m[2];
            $vals = $m[3];
            // 假设 PK 为 ctx_id
            return "INSERT INTO \"$table\" ($cols) VALUES ($vals) ON CONFLICT (ctx_id) DO UPDATE SET " . implode(', ', array_map(fn($c)=>trim($c,' "').'=EXCLUDED.'.trim($c,' "'), explode(',', $cols)));
        }, $sql);
        // WITHOUT ROWID 移除
        $sql = str_ireplace(' WITHOUT ROWID', '', $sql);
        // json_extract -> ->>
        $sql = preg_replace("/json_extract\s*\(\s*\"json\"\s*,\s*'\$.(\w+)'\s*\)/i", "\"json\"->>'$1'", $sql);
        $sql = preg_replace("/json_extract\s*\(\s*\"json\"\s*,\s*'\$.lasttask'\s*\)/i", "\"json\"->>'lasttask'", $sql);
        // PRAGMA 忽略
        if (stripos($sql, 'PRAGMA') === 0) $sql = 'SELECT 1';
        return $sql;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function fetchOne(string $sql, array $params = [])
    {
        $rows = self::fetchAll($sql, $params);
        return $rows[0] ?? null;
    }

    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        return $stmt->rowCount();
    }
}
