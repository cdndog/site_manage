<?php

namespace App\Repositories;

use App\Database;

class UserRepository
{
    public static function findByUsername($username)
    {
        if ($username === '' || $username === null) {
            return null;
        }
        return Database::fetchOne(
            'SELECT * FROM "users" WHERE "username" = :username',
            [':username' => (string)$username]
        );
    }

    public static function findById($id)
    {
        $id = (int)$id;
        if ($id <= 0) {
            return null;
        }
        return Database::fetchOne('SELECT * FROM "users" WHERE "id" = :id', [':id' => $id]);
    }

    public static function all($search = '', $page = 1, $perPage = 20, $sort = 'id', $order = 'desc')
    {
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = ' WHERE "username" LIKE :kw OR "display_name" LIKE :kw';
            $params[':kw'] = '%' . $search . '%';
        }
        $total = Database::fetchOne('SELECT COUNT(*) AS "c" FROM "users"' . $where, $params);
        $total = isset($total['c']) ? (int)$total['c'] : 0;

        $sorts = ['id', 'username', 'display_name', 'status', 'created_at', 'last_login_at'];
        $sort = in_array($sort, $sorts, true) ? $sort : 'id';
        $order = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
        $offset = max(0, ((int)$page - 1) * (int)$perPage);
        $limit = max(1, min(1000, (int)$perPage));

        $rows = Database::fetchAll(
            'SELECT * FROM "users"' . $where . ' ORDER BY "' . $sort . '" ' . $order . ' LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );
        foreach ($rows as &$row) {
            $row['roles'] = self::roleNames((int)$row['id']);
            $row['role_ids'] = self::roleIds((int)$row['id']);
        }
        unset($row);
        return ['rows' => $rows, 'total' => $total];
    }

    public static function roleNames($userId)
    {
        $rows = Database::fetchAll(
            'SELECT r."name" FROM "roles" r JOIN "user_roles" ur ON ur."role_id" = r."id" WHERE ur."user_id" = :uid ORDER BY r."id"',
            [':uid' => (int)$userId]
        );
        return array_map(function ($row) {
            return $row['name'];
        }, $rows);
    }

    public static function roleIds($userId)
    {
        $rows = Database::fetchAll(
            'SELECT "role_id" FROM "user_roles" WHERE "user_id" = :uid',
            [':uid' => (int)$userId]
        );
        return array_map(function ($row) {
            return (int)$row['role_id'];
        }, $rows);
    }

    public static function permissionsOf($userId)
    {
        $rows = Database::fetchAll(
            'SELECT DISTINCT p."code" FROM "permissions" p'
            . ' JOIN "role_permissions" rp ON rp."permission_id" = p."id"'
            . ' JOIN "user_roles" ur ON ur."role_id" = rp."role_id"'
            . ' WHERE ur."user_id" = :uid',
            [':uid' => (int)$userId]
        );
        return array_map(function ($row) {
            return $row['code'];
        }, $rows);
    }

    public static function create(array $data)
    {
        $db = Database::connection();
        $statement = $db->prepare(
            'INSERT INTO "users" ("username", "password_hash", "display_name", "status", "created_at", "updated_at")'
            . ' VALUES (:username, :password_hash, :display_name, :status, :created_at, :updated_at)'
        );
        $statement->bindValue(':username', $data['username']);
        $statement->bindValue(':password_hash', $data['password_hash']);
        $statement->bindValue(':display_name', isset($data['display_name']) ? $data['display_name'] : '');
        $statement->bindValue(':status', isset($data['status']) ? $data['status'] : 'active');
        $now = date('Y-m-d H:i:s');
        $statement->bindValue(':created_at', $now);
        $statement->bindValue(':updated_at', $now);
        $statement->execute();
        return (int)$db->lastInsertRowID();
    }

    public static function update($id, array $data)
    {
        $db = Database::connection();
        $sets = ['"updated_at" = :updated_at'];
        $params = [':id' => (int)$id, ':updated_at' => date('Y-m-d H:i:s')];
        foreach (['username' => 'username', 'password_hash' => 'password_hash', 'display_name' => 'display_name', 'status' => 'status'] as $key => $column) {
            if (isset($data[$key])) {
                $sets[] = '"' . $column . '" = :' . $column;
                $params[':' . $column] = $data[$key];
            }
        }
        $statement = $db->prepare('UPDATE "users" SET ' . implode(', ', $sets) . ' WHERE "id" = :id');
        foreach ($params as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->execute();
    }

    public static function delete($id)
    {
        $id = (int)$id;
        if ($id <= 0) {
            return;
        }
        $db = Database::connection();
        $db->exec('DELETE FROM "user_roles" WHERE "user_id" = ' . $id);
        $db->exec('DELETE FROM "users" WHERE "id" = ' . $id);
    }

    public static function setRoles($userId, array $roleIds)
    {
        $userId = (int)$userId;
        $db = Database::connection();
        $db->exec('DELETE FROM "user_roles" WHERE "user_id" = ' . $userId);
        $statement = $db->prepare('INSERT OR IGNORE INTO "user_roles" ("user_id", "role_id") VALUES (:uid, :rid)');
        foreach (array_unique(array_map('intval', $roleIds)) as $roleId) {
            if ($roleId <= 0) {
                continue;
            }
            $statement->bindValue(':uid', $userId);
            $statement->bindValue(':rid', $roleId);
            $statement->execute();
        }
    }

    public static function touchLastLogin($id)
    {
        $statement = Database::connection()->prepare('UPDATE "users" SET "last_login_at" = :now WHERE "id" = :id');
        $statement->bindValue(':now', date('Y-m-d H:i:s'));
        $statement->bindValue(':id', (int)$id);
        $statement->execute();
    }

    public static function count()
    {
        $row = Database::fetchOne('SELECT COUNT(*) AS "c" FROM "users"');
        return isset($row['c']) ? (int)$row['c'] : 0;
    }

    public static function countAdmins()
    {
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS "c" FROM "user_roles" ur'
            . ' JOIN "roles" r ON r."id" = ur."role_id"'
            . ' JOIN "users" u ON u."id" = ur."user_id"'
            . ' WHERE r."name" = \'admin\' AND u."status" = \'active\''
        );
        return isset($row['c']) ? (int)$row['c'] : 0;
    }
}
