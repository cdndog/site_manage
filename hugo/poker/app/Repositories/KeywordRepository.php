<?php

namespace App\Repositories;

use App\Database;
use App\Services\KeywordService;

class KeywordRepository
{
    public static function ensureTable()
    {
        Database::connection()->exec('CREATE TABLE IF NOT EXISTS "keywordmonitorlist" (
            "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            "ctx_id" VARCHAR UNIQUE NOT NULL,
            "git_name" VARCHAR,
            "keyword" VARCHAR,
            "pubdir" VARCHAR,
            "status" VARCHAR,
            "lang" VARCHAR,
            "geo" VARCHAR,
            "lasttask" VARCHAR,
            "json" VARCHAR,
            "time" DATETIME
        )');
    }

    public static function byCtxId($ctxId)
    {
        if ($ctxId === '' || $ctxId === null) {
            return null;
        }
        self::ensureTable();
        return Database::fetchOne(
            'SELECT * FROM "keywordmonitorlist" WHERE "ctx_id" = :ctx_id LIMIT 1',
            ['ctx_id' => $ctxId]
        );
    }

    public static function deleteByCtxId($ctxId)
    {
        self::ensureTable();
        $db = Database::connection();
        $statement = $db->prepare('DELETE FROM "keywordmonitorlist" WHERE "ctx_id" = :ctx_id');
        $statement->bindValue(':ctx_id', (string)$ctxId);
        $statement->execute();
        return $db->changes() > 0;
    }

    public static function upsertByKeyword(array $record)
    {
        self::ensureTable();
        $db = Database::connection();
        $existing = null;
        $ctxId = isset($record['ctx_id']) && $record['ctx_id'] !== '' ? $record['ctx_id'] : '';
        if ($ctxId !== '') {
            $existing = Database::fetchOne(
                'SELECT * FROM "keywordmonitorlist" WHERE "ctx_id" = :ctx_id LIMIT 1',
                ['ctx_id' => $ctxId]
            );
        }
        if ($existing === null) {
            $existing = Database::fetchOne(
                'SELECT * FROM "keywordmonitorlist" WHERE "keyword" = :keyword LIMIT 1',
                ['keyword' => $record['keyword']]
            );
        }
        $now = date('Y-m-d H:i:s');
        $db->exec('BEGIN');
        try {
            if ($existing !== null) {
                $data = $record;
                if (!empty($existing['ctx_id'])) {
                    $data['ctx_id'] = $existing['ctx_id'];
                }
                $data['time'] = $now;
                $setParts = [];
                foreach (KeywordService::RENEW_COLUMNS as $column) {
                    $setParts[] = '"' . $column . '" = :' . $column;
                }
                $sql = 'UPDATE "keywordmonitorlist" SET ' . implode(', ', $setParts) . ' WHERE "ctx_id" = :ctx_id';
                $statement = $db->prepare($sql);
                foreach (KeywordService::RENEW_COLUMNS as $column) {
                    $statement->bindValue(':' . $column, isset($data[$column]) ? $data[$column] : '');
                }
                $statement->bindValue(':ctx_id', $data['ctx_id']);
                $statement->execute();
            } else {
                $data = $record;
                if (!isset($data['ctx_id']) || $data['ctx_id'] === '') {
                    $data['ctx_id'] = str_replace('.', '', uniqid(time(), true));
                }
                $data['time'] = $now;
                $columns = [];
                $values = [];
                foreach (KeywordService::RENEW_COLUMNS as $column) {
                    $columns[] = '"' . $column . '"';
                    $values[] = ':' . $column;
                }
                $sql = 'INSERT INTO "keywordmonitorlist" (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
                $statement = $db->prepare($sql);
                foreach (KeywordService::RENEW_COLUMNS as $column) {
                    $statement->bindValue(':' . $column, isset($data[$column]) ? $data[$column] : '');
                }
                $statement->execute();
            }
            $db->exec('COMMIT');
        } catch (\Exception $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
        $data['ctx_id'] = isset($data['ctx_id']) ? $data['ctx_id'] : (isset($existing['ctx_id']) ? $existing['ctx_id'] : '');
        return $data;
    }

    public static function byGitName($gitName)
    {
        self::ensureTable();
        return Database::fetchAll(
            'SELECT * FROM "keywordmonitorlist" WHERE "git_name" = :git_name ORDER BY "id" DESC',
            ['git_name' => $gitName]
        );
    }

    public static function all()
    {
        self::ensureTable();
        return Database::fetchAll('SELECT * FROM "keywordmonitorlist" ORDER BY "id" DESC');
    }

    const SORTABLE = ['id', 'ctx_id', 'keyword', 'status', 'git_name', 'pubdir', 'lang', 'geo', 'lasttask'];

    public static function search($search, $page, $perPage, $sort = 'id', $order = 'desc')
    {
        self::ensureTable();
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = ' WHERE "keyword" LIKE :kw OR "status" LIKE :kw OR "git_name" LIKE :kw OR "pubdir" LIKE :kw OR "lang" LIKE :kw OR "geo" LIKE :kw OR "lasttask" LIKE :kw OR "ctx_id" LIKE :kw';
            $params['kw'] = '%' . $search . '%';
        }
        $orderBy = ' ORDER BY "id" DESC';
        if (in_array($sort, self::SORTABLE, true)) {
            $direction = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
            $orderBy = ' ORDER BY "' . $sort . '" ' . $direction . ', "id" DESC';
        }
        $total = Database::fetchOne('SELECT COUNT(*) AS "c" FROM "keywordmonitorlist"' . $where, $params);
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $rows = Database::fetchAll(
            'SELECT "id", "ctx_id", "keyword", "status", "git_name", "pubdir", "lang", "lasttask"'
            . ' FROM "keywordmonitorlist"' . $where . $orderBy . ' LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset,
            $params
        );
        return [
            'rows' => $rows,
            'total' => isset($total['c']) ? (int)$total['c'] : 0,
        ];
    }
}
