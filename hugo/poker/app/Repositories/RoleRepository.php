<?php

namespace App\Repositories;

use App\Database;

class RoleRepository
{
    public static function all()
    {
        return Database::fetchAll('SELECT * FROM "roles" ORDER BY "id"');
    }

    public static function findById($id)
    {
        $id = (int)$id;
        if ($id <= 0) {
            return null;
        }
        return Database::fetchOne('SELECT * FROM "roles" WHERE "id" = :id', [':id' => $id]);
    }

    public static function findByName($name)
    {
        if ($name === '' || $name === null) {
            return null;
        }
        return Database::fetchOne('SELECT * FROM "roles" WHERE "name" = :name', [':name' => (string)$name]);
    }

    public static function create(array $data)
    {
        $db = Database::connection();
        $statement = $db->prepare('INSERT INTO "roles" ("name", "description") VALUES (:name, :description)');
        $statement->bindValue(':name', $data['name']);
        $statement->bindValue(':description', isset($data['description']) ? $data['description'] : '');
        $statement->execute();
        return (int)$db->lastInsertRowID();
    }

    public static function update($id, array $data)
    {
        $db = Database::connection();
        $statement = $db->prepare('UPDATE "roles" SET "name" = :name, "description" = :description WHERE "id" = :id');
        $statement->bindValue(':name', $data['name']);
        $statement->bindValue(':description', isset($data['description']) ? $data['description'] : '');
        $statement->bindValue(':id', (int)$id);
        $statement->execute();
    }

    public static function delete($id)
    {
        $id = (int)$id;
        if ($id <= 0) {
            return;
        }
        $db = Database::connection();
        $db->exec('DELETE FROM "user_roles" WHERE "role_id" = ' . $id);
        $db->exec('DELETE FROM "role_permissions" WHERE "role_id" = ' . $id);
        $db->exec('DELETE FROM "roles" WHERE "id" = ' . $id);
    }

    public static function setPermissions($roleId, array $permissionIds)
    {
        $roleId = (int)$roleId;
        $db = Database::connection();
        $db->exec('DELETE FROM "role_permissions" WHERE "role_id" = ' . $roleId);
        $statement = $db->prepare('INSERT OR IGNORE INTO "role_permissions" ("role_id", "permission_id") VALUES (:rid, :pid)');
        foreach (array_unique(array_map('intval', $permissionIds)) as $permissionId) {
            if ($permissionId <= 0) {
                continue;
            }
            $statement->bindValue(':rid', $roleId);
            $statement->bindValue(':pid', $permissionId);
            $statement->execute();
        }
    }

    public static function permissionIdsOf($roleId)
    {
        $rows = Database::fetchAll(
            'SELECT "permission_id" FROM "role_permissions" WHERE "role_id" = :rid',
            [':rid' => (int)$roleId]
        );
        return array_map(function ($row) {
            return (int)$row['permission_id'];
        }, $rows);
    }

    public static function userCount($roleId)
    {
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS "c" FROM "user_roles" WHERE "role_id" = :rid',
            [':rid' => (int)$roleId]
        );
        return isset($row['c']) ? (int)$row['c'] : 0;
    }
}
