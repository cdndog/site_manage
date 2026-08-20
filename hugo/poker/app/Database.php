<?php

namespace App;

use SQLite3;

class Database
{
    private static $connection = null;

    public static function reset()
    {
        if (self::$connection !== null) {
            self::$connection->close();
            self::$connection = null;
        }
        self::$migrated = false;
        \App\Support\Cache::reset();
    }

    public static function connection()
    {
        if (self::$connection === null) {
            $db = new SQLite3(Config::dbFile(), SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);
            $db->enableExceptions(true);
            $db->busyTimeout(5000);
            $db->exec('PRAGMA journal_mode=WAL');
            $db->exec('PRAGMA synchronous=NORMAL');
            $db->exec('PRAGMA cache_size=-8000');
            $db->exec('PRAGMA temp_store=MEMORY');
            self::$connection = $db;
            self::migrate($db);
        }
        return self::$connection;
    }

    private static $migrated = false;

    public static function migrate(\SQLite3 $db)
    {
        if (self::$migrated) {
            return;
        }
        $usersExists = $db->querySingle('SELECT 1 FROM "sqlite_master" WHERE "type" = \'table\' AND "name" = \'users\'');
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

    public static function fetchAll($sql, array $params = [])
    {
        $statement = self::connection()->prepare($sql);
        if ($statement === false) {
            return [];
        }
        foreach ($params as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $result = $statement->execute();
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        return $rows;
    }

    public static function fetchOne($sql, array $params = [])
    {
        $rows = self::fetchAll($sql, $params);
        return isset($rows[0]) ? $rows[0] : null;
    }

    public static function execute($sql, array $params = [])
    {
        $statement = self::connection()->prepare($sql);
        if ($statement === false) {
            return 0;
        }
        foreach ($params as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->execute();
        return self::connection()->changes();
    }
}
