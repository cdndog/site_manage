<?php

namespace App;

use SQLite3;

class Database
{
    private static $connection = null;
    private static $pdo = null;
    private static $isPg = false;

    public static function reset()
    {
        if (self::$connection !== null) {
            try { self::$connection->close(); } catch (\Throwable $e) {}
            self::$connection = null;
        }
        if (self::$pdo !== null) {
            self::$pdo = null;
        }
        self::$isPg = false;
        self::$migrated = false;
        \App\Support\Cache::reset();
    }

    public static function isPg(): bool
    {
        if (self::$isPg) return true;
        if (self::$pdo !== null) return true;
        $dsn = Config::dbDsn();
        return $dsn !== '' && (stripos($dsn, 'pgsql:') === 0 || stripos($dsn, 'postgres:') === 0);
    }

    public static function connection()
    {
        if (self::$connection !== null || self::$pdo !== null) {
            return self::$isPg ? self::$pdo : self::$connection;
        }
        $dsn = Config::dbDsn();
        if ($dsn !== '' && (stripos($dsn, 'pgsql:') === 0 || stripos($dsn, 'postgres:') === 0)) {
            self::$isPg = true;
            if (stripos($dsn, 'postgres://') === 0) {
                $u = parse_url($dsn);
                $dsn = 'pgsql:host=' . ($u['host'] ?? '127.0.0.1') . ';port=' . ($u['port'] ?? 5432) . ';dbname=' . ltrim($u['path'] ?? '', '/');
                if (!empty($u['user'])) $dsn .= ';user=' . $u['user'];
                if (!empty($u['pass'])) $dsn .= ';password=' . $u['pass'];
            }
            self::$pdo = new \PDO($dsn, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
            self::$pdo->exec("SET TIME ZONE 'UTC'");
            self::migratePg(self::$pdo);
            return self::$pdo;
        }
        self::$isPg = false;
        $db = new \SQLite3(Config::dbFile(), \SQLITE3_OPEN_CREATE | \SQLITE3_OPEN_READWRITE);
        $db->enableExceptions(true);
        $db->busyTimeout(5000);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA synchronous=NORMAL');
        $db->exec('PRAGMA cache_size=-8000');
        $db->exec('PRAGMA temp_store=MEMORY');
        self::$connection = $db;
        self::migrate($db);
        return self::$connection;
    }

    private static $migrated = false;
    private static $migratedPg = false;

    public static function migratePg(\PDO $db)
    {
        if (self::$migratedPg) return;
        try { $db->exec('CREATE EXTENSION IF NOT EXISTS pg_trgm'); } catch (\Throwable $e) {}
        $db->exec('CREATE TABLE IF NOT EXISTS "aigc_status" ("ctx_id" TEXT PRIMARY KEY, "keyword" TEXT, "lang" TEXT, "pubdomain" TEXT, "createAt" TEXT, "publishAt" TEXT, "search_text" TEXT)');
        $db->exec('CREATE INDEX IF NOT EXISTS "idx_aigc_publishAt" ON "aigc_status" ("publishAt" DESC)');
        $db->exec('CREATE INDEX IF NOT EXISTS "idx_aigc_pubdomain" ON "aigc_status" USING GIN ("pubdomain" gin_trgm_ops)');
        $db->exec('CREATE INDEX IF NOT EXISTS "idx_aigc_lang" ON "aigc_status" ("lang")');
        $db->exec('CREATE INDEX IF NOT EXISTS "idx_aigc_keyword" ON "aigc_status" USING GIN ("keyword" gin_trgm_ops)');
        $db->exec('CREATE INDEX IF NOT EXISTS "idx_aigc_search" ON "aigc_status" ("search_text")');
        // 补列：存量 PG 库可能缺少 search_text 列
        try { $db->exec('ALTER TABLE "aigc_status" ADD COLUMN IF NOT EXISTS "search_text" TEXT'); } catch (\Throwable $e) {}
        $db->exec('CREATE TABLE IF NOT EXISTS "users" ("id" SERIAL PRIMARY KEY, "username" VARCHAR UNIQUE NOT NULL, "password_hash" VARCHAR NOT NULL, "display_name" VARCHAR DEFAULT \'\', "status" VARCHAR DEFAULT \'active\', "created_at" TIMESTAMP, "updated_at" TIMESTAMP, "last_login_at" TIMESTAMP)');
        $db->exec('CREATE TABLE IF NOT EXISTS "roles" ("id" SERIAL PRIMARY KEY, "name" VARCHAR UNIQUE NOT NULL, "description" VARCHAR DEFAULT \'\')');
        $db->exec('CREATE TABLE IF NOT EXISTS "permissions" ("id" SERIAL PRIMARY KEY, "code" VARCHAR UNIQUE NOT NULL, "name" VARCHAR NOT NULL, "description" VARCHAR DEFAULT \'\')');
        $db->exec('CREATE TABLE IF NOT EXISTS "user_roles" ("user_id" BIGINT NOT NULL REFERENCES "users"("id") ON DELETE CASCADE, "role_id" BIGINT NOT NULL REFERENCES "roles"("id") ON DELETE CASCADE, PRIMARY KEY ("user_id", "role_id"))');
        $db->exec('CREATE INDEX IF NOT EXISTS "idx_user_roles_role_id" ON "user_roles" ("role_id")');
        $db->exec('CREATE TABLE IF NOT EXISTS "role_permissions" ("role_id" BIGINT NOT NULL REFERENCES "roles"("id") ON DELETE CASCADE, "permission_id" BIGINT NOT NULL REFERENCES "permissions"("id") ON DELETE CASCADE, PRIMARY KEY ("role_id", "permission_id"))');
        $db->exec('CREATE INDEX IF NOT EXISTS "idx_role_permissions_permission_id" ON "role_permissions" ("permission_id")');
        $db->exec('CREATE TABLE IF NOT EXISTS "app_configs" ("id" SERIAL PRIMARY KEY, "config_key" VARCHAR UNIQUE NOT NULL, "config_value" TEXT NOT NULL DEFAULT \'\', "description" VARCHAR DEFAULT \'\', "updated_at" TIMESTAMP, "updated_by" VARCHAR DEFAULT \'\')');
        // 业务表 PG 兼容 - 使用最佳实践：TEXT + JSONB + TIMESTAMPTZ + GIN
        $db->exec('CREATE TABLE IF NOT EXISTS "siteops" ("id" BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, "ctx_id" TEXT NOT NULL UNIQUE, "git_name" TEXT, "domain" TEXT, "site_title" TEXT, "site_subtitle" TEXT, "site_logo" TEXT, "languages" TEXT, "sns_id" TEXT, "topnav_menus" TEXT, "keyword" TEXT, "theme_name" TEXT, "theme_type" TEXT, "sitedir" TEXT, "deploy" TEXT, "hostip" TEXT, "local_deploy" TEXT, "local_hostip" TEXT, "status" TEXT, "json" JSONB, "time" TIMESTAMPTZ DEFAULT now(), "git_account" TEXT)');
        $db->exec('CREATE TABLE IF NOT EXISTS "sitetopic" ("id" BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, "ctx_id" TEXT NOT NULL UNIQUE, "git_name" TEXT, "domain" TEXT, "keyword" TEXT, "pubdir" TEXT, "status" TEXT, "lang" TEXT, "geo" TEXT, "lasttask" TEXT, "json" JSONB, "time" TIMESTAMPTZ DEFAULT now())');
        $db->exec('CREATE TABLE IF NOT EXISTS "keywordmonitorlist" ("id" BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, "ctx_id" TEXT NOT NULL UNIQUE, "git_name" TEXT, "keyword" TEXT, "pubdir" TEXT, "status" TEXT, "lang" TEXT, "geo" TEXT, "lasttask" TEXT, "json" JSONB, "time" TIMESTAMPTZ DEFAULT now())');
        $db->exec('CREATE TABLE IF NOT EXISTS "article" ("id" BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, "ctx_id" TEXT NOT NULL UNIQUE, "url" TEXT, "title" TEXT, "keyword" TEXT, "tags" TEXT, "description" TEXT, "static_thumbnail" TEXT, "iframesrc" TEXT, "lang" TEXT, "series" TEXT, "pubdir" TEXT, "savename" TEXT, "globalpublish" TEXT, "pubdomain" TEXT, "translate_to_langs" TEXT, "content" TEXT, "json" JSONB, "json_file" TEXT, "time" TIMESTAMPTZ DEFAULT now(), "update_date" TIMESTAMPTZ)');
        $db->exec('CREATE TABLE IF NOT EXISTS "serverlist" ("id" BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, "ctx_id" TEXT NOT NULL UNIQUE, "git_name" TEXT UNIQUE, "domain" TEXT, "site_title" TEXT, "site_subtitle" TEXT, "site_logo" TEXT, "languages" TEXT, "sns_id" TEXT, "topnav_menus" TEXT, "keyword" TEXT, "theme_name" TEXT, "theme_type" TEXT, "sitedir" TEXT, "deploy" TEXT, "hostip" TEXT, "local_deploy" TEXT, "local_hostip" TEXT, "status" TEXT, "json" JSONB, "time" TIMESTAMPTZ DEFAULT now())');
        $db->exec('CREATE INDEX IF NOT EXISTS "idx_siteops_json_gin" ON "siteops" USING GIN ("json")');
        $db->exec('CREATE INDEX IF NOT EXISTS "idx_sitetopic_json_gin" ON "sitetopic" USING GIN ("json")');
        $db->exec('CREATE INDEX IF NOT EXISTS "idx_article_json_gin" ON "article" USING GIN ("json")');
        self::seedPg($db);
        self::seedAppConfigsPg($db);
        self::$migratedPg = true;
    }

    private static function seedPg(\PDO $db)
    {
        $perms = \App\Config::permissions();
        foreach ($perms as $code => $meta) {
            $stmt = $db->prepare('INSERT INTO "permissions" ("code","name","description") VALUES (:code,:name,:description) ON CONFLICT (code) DO NOTHING');
            $stmt->execute([':code'=>$code, ':name'=>$meta['name'], ':description'=>$meta['description']??'']);
        }
        $roles = \App\Config::roles();
        foreach ($roles as $name => $meta) {
            $stmt = $db->prepare('INSERT INTO "roles" ("name","description") VALUES (:name,:description) ON CONFLICT (name) DO NOTHING');
            $stmt->execute([':name'=>$name, ':description'=>$meta['description']??'']);
        }
        $map = \App\Config::rolePermissionMap();
        foreach ($map as $role => $codes) {
            $rid = $db->query("SELECT id FROM roles WHERE name=" . $db->quote($role))->fetchColumn();
            foreach ($codes as $code) {
                $pid = $db->query("SELECT id FROM permissions WHERE code=" . $db->quote($code))->fetchColumn();
                if ($rid && $pid) $db->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (:r,:p) ON CONFLICT DO NOTHING')->execute([':r'=>$rid, ':p'=>$pid]);
            }
        }
        $cnt = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($cnt===0) {
            $u = \App\Config::authUser(); $p = \App\Config::authPassword();
            if (($u!==null&&$u!=='')||($p!==null&&$p!=='')) {
                $user = $u!==null&&$u!==''?$u:'admin';
                $hash = $p!==null&&$p!=='' ? (strncmp($p,'$2y$',4)===0||strncmp($p,'$2a$',4)===0||strncmp($p,'$argon2',7)===0?$p:password_hash($p,PASSWORD_DEFAULT)) : password_hash(bin2hex(random_bytes(12)),PASSWORD_DEFAULT);
                $stmt=$db->prepare('INSERT INTO users (username,password_hash,display_name,status,created_at,updated_at) VALUES (:u,:p,:d,:s,:c,:u2)');
                $now=date('Y-m-d H:i:s');
                $stmt->execute([':u'=>$user,':p'=>$hash,':d'=>$user,':s'=>'active',':c'=>$now,':u2'=>$now]);
                $uid=$db->lastInsertId();
                $rid=$db->query("SELECT id FROM roles WHERE name='admin'")->fetchColumn();
                if($rid) $db->prepare('INSERT INTO user_roles (user_id,role_id) VALUES (:u,:r) ON CONFLICT DO NOTHING')->execute([':u'=>$uid,':r'=>$rid]);
            }
        }
    }

    private static function seedAppConfigsPg(\PDO $db)
    {
        $cnt=(int)$db->query("SELECT COUNT(*) FROM app_configs")->fetchColumn();
        if($cnt>0) return;
        $file=\App\Config::configFile();
        if(!is_file($file)) return;
        $cfg=include $file;
        if(!is_array($cfg)) return;
        foreach(\App\Config::dictionaryKeys() as $k){
            if(!isset($cfg[$k])||!is_array($cfg[$k])) continue;
            $stmt=$db->prepare('INSERT INTO app_configs (config_key,config_value,description,updated_at,updated_by) VALUES (:k,:v,:d,:u,:b) ON CONFLICT (config_key) DO NOTHING');
            $stmt->execute([':k'=>$k,':v'=>json_encode($cfg[$k],JSON_UNESCAPED_UNICODE),':d'=>\App\Config::dictionaryDescriptions()[$k]??'',':u'=>date('Y-m-d H:i:s'),':b'=>'seed']);
        }
    }

    public static function migrate(\SQLite3 $db)
    {
        if (self::$migrated) {
            return;
        }
        // 主存 aigc_status 索引表（与 sitetopic 同库，避免全量 JSON R/W）- PG 侧已 snake_case，此处保持兼容
        $db->exec('CREATE TABLE IF NOT EXISTS "aigc_status" ('
            . '"ctx_id" TEXT PRIMARY KEY, "keyword" TEXT, "lang" TEXT, "pubdomain" TEXT, "createAt" TEXT, "publishAt" TEXT, "search_text" TEXT'
            . ') WITHOUT ROWID');
        $db->exec('CREATE INDEX IF NOT EXISTS "idx_aigc_publishAt" ON "aigc_status" ("publishAt" DESC)');
        $db->exec('CREATE INDEX IF NOT EXISTS "idx_aigc_pubdomain" ON "aigc_status" ("pubdomain")');
        $db->exec('CREATE INDEX IF NOT EXISTS "idx_aigc_lang" ON "aigc_status" ("lang")');
        $db->exec('CREATE INDEX IF NOT EXISTS "idx_aigc_search" ON "aigc_status" ("search_text")');
        // 存量 JSON → DB 迁移（幂等）
        self::migrateAigcStatus($db);

        if (self::$isPg) {
            $usersExists = $db->querySingle("SELECT 1 FROM information_schema.tables WHERE table_name='users'");
        } else {
            $usersExists = $db->querySingle('SELECT 1 FROM "sqlite_master" WHERE "type" = \'table\' AND "name" = \'users\'');
        }
        if ($usersExists) {
            $userCount = (int)$db->querySingle('SELECT COUNT(*) FROM "users"');
            if ($userCount > 0) {
                self::$migrated = true;
                return;
            }
        }
        $db->exec('CREATE TABLE IF NOT EXISTS "users" (
            "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            "username" VARCHAR UNIQUE NOT NULL,
            "password_hash" VARCHAR NOT NULL,
            "display_name" VARCHAR DEFAULT \'\',
            "status" VARCHAR DEFAULT \'active\',
            "created_at" DATETIME,
            "updated_at" DATETIME,
            "last_login_at" DATETIME
        )');
        $db->exec('CREATE TABLE IF NOT EXISTS "roles" (
            "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            "name" VARCHAR UNIQUE NOT NULL,
            "description" VARCHAR DEFAULT \'\'
        )');
        $db->exec('CREATE TABLE IF NOT EXISTS "permissions" (
            "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            "code" VARCHAR UNIQUE NOT NULL,
            "name" VARCHAR NOT NULL,
            "description" VARCHAR DEFAULT \'\'
        )');
        $db->exec('CREATE TABLE IF NOT EXISTS "user_roles" (
            "user_id" INTEGER NOT NULL,
            "role_id" INTEGER NOT NULL,
            PRIMARY KEY ("user_id", "role_id")
        )');
        $db->exec('CREATE TABLE IF NOT EXISTS "role_permissions" (
            "role_id" INTEGER NOT NULL,
            "permission_id" INTEGER NOT NULL,
            PRIMARY KEY ("role_id", "permission_id")
        )');
        $db->exec('CREATE TABLE IF NOT EXISTS "app_configs" (
            "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            "config_key" VARCHAR UNIQUE NOT NULL,
            "config_value" TEXT NOT NULL DEFAULT \'\',
            "description" VARCHAR DEFAULT \'\',
            "updated_at" DATETIME,
            "updated_by" VARCHAR DEFAULT \'\'
        )');
        self::seed($db);
        self::seedAppConfigs($db);
        self::$migrated = true;
    }

    private static function seed(\SQLite3 $db)
    {
        $permissions = \App\Config::permissions();
        $insertPermission = $db->prepare('INSERT OR IGNORE INTO "permissions" ("code", "name", "description") VALUES (:code, :name, :description)');
        $permissionIds = [];
        $selectPermission = $db->prepare('SELECT "id" FROM "permissions" WHERE "code" = :code');
        foreach ($permissions as $code => $meta) {
            $insertPermission->bindValue(':code', $code);
            $insertPermission->bindValue(':name', $meta['name']);
            $insertPermission->bindValue(':description', isset($meta['description']) ? $meta['description'] : '');
            $insertPermission->execute();
            $selectPermission->bindValue(':code', $code);
            $row = $selectPermission->execute()->fetchArray(SQLITE3_ASSOC);
            $permissionIds[$code] = isset($row['id']) ? (int)$row['id'] : 0;
        }

        $roles = \App\Config::roles();
        $insertRole = $db->prepare('INSERT OR IGNORE INTO "roles" ("name", "description") VALUES (:name, :description)');
        $selectRole = $db->prepare('SELECT "id" FROM "roles" WHERE "name" = :name');
        $roleIds = [];
        foreach ($roles as $name => $meta) {
            $insertRole->bindValue(':name', $name);
            $insertRole->bindValue(':description', isset($meta['description']) ? $meta['description'] : '');
            $insertRole->execute();
            $selectRole->bindValue(':name', $name);
            $row = $selectRole->execute()->fetchArray(SQLITE3_ASSOC);
            $roleIds[$name] = isset($row['id']) ? (int)$row['id'] : 0;
        }

        $seedMap = \App\Config::rolePermissionMap();
        $insertRolePerm = $db->prepare('INSERT OR IGNORE INTO "role_permissions" ("role_id", "permission_id") VALUES (:role_id, :permission_id)');
        foreach ($seedMap as $role => $codes) {
            if (!isset($roleIds[$role])) {
                continue;
            }
            foreach ($codes as $code) {
                if (!isset($permissionIds[$code])) {
                    continue;
                }
                $insertRolePerm->bindValue(':role_id', $roleIds[$role]);
                $insertRolePerm->bindValue(':permission_id', $permissionIds[$code]);
                $insertRolePerm->execute();
            }
        }

        self::seedAdminUser($db, $roleIds);
    }

    private static function seedAdminUser(\SQLite3 $db, array $roleIds)
    {
        $count = (int)$db->querySingle('SELECT COUNT(*) FROM "users"');
        if ($count > 0) {
            return;
        }
        $envUser = \App\Config::authUser();
        $envPassword = \App\Config::authPassword();
        if (($envUser === null || $envUser === '') && ($envPassword === null || $envPassword === '')) {
            return;
        }
        $username = ($envUser !== null && $envUser !== '') ? $envUser : 'admin';
        if ($envPassword !== null && $envPassword !== '') {
            if (strncmp($envPassword, '$2y$', 4) === 0 || strncmp($envPassword, '$2a$', 4) === 0 || strncmp($envPassword, '$argon2', 7) === 0) {
                $hash = $envPassword;
            } else {
                $hash = password_hash($envPassword, PASSWORD_DEFAULT);
            }
        } else {
            $hash = password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT);
        }
        $statement = $db->prepare(
            'INSERT INTO "users" ("username", "password_hash", "display_name", "status", "created_at", "updated_at")'
            . ' VALUES (:username, :password_hash, :display_name, :status, :created_at, :updated_at)'
        );
        $statement->bindValue(':username', $username);
        $statement->bindValue(':password_hash', $hash);
        $statement->bindValue(':display_name', $username);
        $statement->bindValue(':status', 'active');
        $now = date('Y-m-d H:i:s');
        $statement->bindValue(':created_at', $now);
        $statement->bindValue(':updated_at', $now);
        $statement->execute();
        $userId = (int)$db->lastInsertRowID();
        if (isset($roleIds['admin'])) {
            $assign = $db->prepare('INSERT OR IGNORE INTO "user_roles" ("user_id", "role_id") VALUES (:user_id, :role_id)');
            $assign->bindValue(':user_id', $userId);
            $assign->bindValue(':role_id', $roleIds['admin']);
            $assign->execute();
        }
    }

    private static function migrateAigcStatus(\SQLite3 $db)
    {
        $file = \App\Config::dataDir() . '/seodata/aigc_status.json';
        if (!is_file($file) || !is_readable($file)) {
            return;
        }
        $raw = @file_get_contents($file);
        $items = json_decode((string)$raw, true);
        if (!is_array($items) || count($items) === 0) {
            return;
        }
        $exists = (int)$db->querySingle('SELECT COUNT(*) FROM "aigc_status"');
        if ($exists > 0) {
            return;
        }
        $db->exec('BEGIN');
        $stmt = $db->prepare('INSERT OR IGNORE INTO "aigc_status" ("ctx_id","keyword","lang","pubdomain","createAt","publishAt") VALUES (:c,:k,:l,:p,:ca,:pa)');
        foreach ($items as $r) {
            if (empty($r['ctx_id'])) continue;
            $stmt->bindValue(':c', (string)$r['ctx_id']);
            $stmt->bindValue(':k', (string)($r['keyword'] ?? ''));
            $stmt->bindValue(':l', (string)($r['lang'] ?? ''));
            $stmt->bindValue(':p', (string)($r['pubdomain'] ?? ''));
            $stmt->bindValue(':ca', (string)($r['createAt'] ?? ''));
            $stmt->bindValue(':pa', (string)($r['publishAt'] ?? ''));
            $stmt->execute();
        }
        $db->exec('COMMIT');
    }

    private static function seedAppConfigs(\SQLite3 $db)
    {
        $count = (int)$db->querySingle('SELECT COUNT(*) FROM "app_configs"');
        if ($count > 0) {
            return;
        }
        $file = \App\Config::configFile();
        if (!is_file($file)) {
            return;
        }
        $config = include $file;
        if (!is_array($config)) {
            return;
        }
        $dictionary = \App\Config::dictionaryKeys();
        $descriptions = \App\Config::dictionaryDescriptions();
        $insert = $db->prepare(
            'INSERT INTO "app_configs" ("config_key", "config_value", "description", "updated_at", "updated_by")'
            . ' VALUES (:key, :value, :description, :updated_at, :updated_by)'
        );
        $now = date('Y-m-d H:i:s');
        foreach ($dictionary as $key) {
            if (!isset($config[$key]) || !is_array($config[$key])) {
                continue;
            }
            $insert->bindValue(':key', $key);
            $insert->bindValue(':value', json_encode($config[$key], JSON_UNESCAPED_UNICODE));
            $insert->bindValue(':description', isset($descriptions[$key]) ? $descriptions[$key] : '');
            $insert->bindValue(':updated_at', $now);
            $insert->bindValue(':updated_by', 'seed');
            $insert->execute();
        }
    }

    private static function translatePg(string $sql): string
    {
        // 必须在泛型 DATETIME→TIMESTAMPTZ 之前，先将 datetime('now','localtime') 转为 NOW()
        $sql = preg_replace("/datetime\s*\(\s*'now'\s*,\s*'localtime'\s*\)/i", "NOW()", $sql);
        $sql = preg_replace("/datetime\s*\(\s*'now'\s*\)/i", "NOW()", $sql);
        $sql = preg_replace('/\bDATETIME\b/i', 'TIMESTAMPTZ', $sql);
        $sql = preg_replace('/INTEGER PRIMARY KEY AUTOINCREMENT/i', 'BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY', $sql);
        $sql = preg_replace('/"id"\s+INTEGER\s+NOT\s+NULL\s+UNIQUE,\s*PRIMARY\s+KEY\s*\(\s*"id"\s+AUTOINCREMENT\s*\)/i', '"id" BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY', $sql);
        // INSERT OR IGNORE -> ON CONFLICT DO NOTHING
        $sql = preg_replace_callback('/INSERT\s+OR\s+IGNORE\s+INTO\s+"?(\w+)"?\s*\(([^)]+)\)\s*VALUES\s*\(([^)]+)\)/i', function($m){
            $t=$m[1]; $cols=$m[2]; $vals=$m[3];
            $colList = array_map(fn($c)=>trim($c,' "'), explode(',', $cols));
            $pk = in_array('ctx_id', $colList) ? 'ctx_id' : (in_array('code', $colList) ? 'code' : (in_array('name', $colList) ? 'name' : (in_array('id', $colList) ? 'id' : $colList[0])));
            return "INSERT INTO \"$t\" ($cols) VALUES ($vals) ON CONFLICT (\"$pk\") DO NOTHING";
        }, $sql);
        // INSERT OR REPLACE -> ON CONFLICT DO UPDATE SET (修复：EXCLUDED."col" 缺少闭合引号)
        $sql = preg_replace_callback('/INSERT\s+OR\s+REPLACE\s+INTO\s+"?(\w+)"?\s*\(([^)]+)\)\s*VALUES\s*\(([^)]+)\)/i', function($m){
            $t=$m[1]; $cols=$m[2];
            $colList = array_map(fn($c)=>trim($c,' "'), explode(',', $cols));
            $pk = in_array('ctx_id', $colList) ? 'ctx_id' : (in_array('code', $colList) ? 'code' : (in_array('name', $colList) ? 'name' : $colList[0]));
            $sets = implode(', ', array_map(fn($c)=>'"'.trim($c,' "').'"=EXCLUDED."'.trim($c,' "').'"', $colList));
            return "INSERT INTO \"$t\" ($cols) VALUES ({$m[3]}) ON CONFLICT (\"$pk\") DO UPDATE SET $sets";
        }, $sql);
        $sql = str_ireplace(' WITHOUT ROWID', '', $sql);
        $sql = preg_replace('/json_extract\s*\(\s*"json"\s*,\s*\'\$\.(\w+)\'\s*\)/i', '"json"->>\'$1\'', $sql);
        // SQLite LIKE 默认大小写不敏感，PG 需用 ILIKE 保持一致（\b 保证不重复改写 ILIKE）
        $sql = preg_replace('/\bLIKE\b/', 'ILIKE', $sql);
        $sql = preg_replace('/WHERE\s*"\*"\s*=\s*"\*"/i', 'WHERE 1=1', $sql);
        if (stripos(trim($sql), 'PRAGMA')===0) return 'SELECT 1';
        return $sql;
    }

    public static function fetchAll($sql, array $params = [])
    {
        if (self::isPg()) {
            $sql = self::translatePg($sql);
            $stmt = self::connection()->prepare($sql);
            foreach ($params as $k => $v) $stmt->bindValue($k, $v);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }
        $statement = self::connection()->prepare($sql);
        if ($statement === false) return [];
        foreach ($params as $name => $value) $statement->bindValue($name, $value);
        $result = $statement->execute();
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
        return $rows;
    }

    public static function fetchOne($sql, array $params = [])
    {
        $rows = self::fetchAll($sql, $params);
        return $rows[0] ?? null;
    }

    public static function execute($sql, array $params = [])
    {
        if (self::isPg()) {
            $sql = self::translatePg($sql);
            $stmt = self::connection()->prepare($sql);
            foreach ($params as $k => $v) $stmt->bindValue($k, $v);
            $stmt->execute();
            return $stmt->rowCount();
        }
        $statement = self::connection()->prepare($sql);
        if ($statement === false) return 0;
        foreach ($params as $name => $value) $statement->bindValue($name, $value);
        $statement->execute();
        return self::connection()->changes();
    }
}
