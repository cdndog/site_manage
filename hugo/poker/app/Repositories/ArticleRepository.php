<?php

namespace App\Repositories;

use App\Database;
use App\Services\ArticleService;
use App\Support\Cache;

class ArticleRepository
{
    const TABLE = 'article';

    private static $ensured = false;

    public static function ensureTable()
    {
        if (self::$ensured) {
            return;
        }
        $exists = Database::fetchOne('SELECT 1 FROM "sqlite_master" WHERE "type" = \'table\' AND "name" = \'article\'');
        if ($exists !== null) {
            self::$ensured = true;
            return;
        }
        Database::connection()->exec('CREATE TABLE IF NOT EXISTS "article" (
            "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            "ctx_id" VARCHAR UNIQUE NOT NULL,
            "url" VARCHAR,
            "title" VARCHAR,
            "keyword" VARCHAR,
            "tags" VARCHAR,
            "description" VARCHAR,
            "static_thumbnail" VARCHAR,
            "iframesrc" VARCHAR,
            "lang" VARCHAR,
            "series" VARCHAR,
            "pubdir" VARCHAR,
            "savename" VARCHAR,
            "globalpublish" VARCHAR,
            "pubdomain" VARCHAR,
            "translate_to_langs" VARCHAR,
            "content" TEXT,
            "json" TEXT,
            "json_file" VARCHAR,
            "time" DATETIME,
            "update_date" DATETIME
        )');
        Database::connection()->exec('CREATE INDEX IF NOT EXISTS "idx_article_title" ON "article" ("title")');
        Database::connection()->exec('CREATE INDEX IF NOT EXISTS "idx_article_pubdomain" ON "article" ("pubdomain")');
        self::$ensured = true;
    }

    public static function byCtxId($ctxId)
    {
        if ($ctxId === '' || $ctxId === null) {
            return null;
        }
        self::ensureTable();
        return Database::fetchOne(
            'SELECT * FROM "article" WHERE "ctx_id" = :ctx_id LIMIT 1',
            ['ctx_id' => $ctxId]
        );
    }

    public static function deleteByCtxId($ctxId)
    {
        self::ensureTable();
        $db = Database::connection();
        $statement = $db->prepare('DELETE FROM "article" WHERE "ctx_id" = :ctx_id');
        $statement->bindValue(':ctx_id', (string)$ctxId);
        $statement->execute();
        $deleted = $db->changes() > 0;
        if ($deleted) {
            Cache::forget('article:count');
        }
        return $deleted;
    }

    public static function upsertByCtxId(array $record)
    {
        self::ensureTable();
        $db = Database::connection();
        $ctxId = isset($record['ctx_id']) && $record['ctx_id'] !== '' ? $record['ctx_id'] : '';
        $existing = null;
        if ($ctxId !== '') {
            $existing = Database::fetchOne(
                'SELECT * FROM "article" WHERE "ctx_id" = :ctx_id LIMIT 1',
                ['ctx_id' => $ctxId]
            );
        }
        $now = date('Y-m-d H:i:s');
        $db->exec('BEGIN');
        try {
            if ($existing !== null) {
                $data = $record;
                $columns = array_merge(ArticleService::RENEW_COLUMNS, ['update_date']);
                $setParts = [];
                foreach ($columns as $column) {
                    $setParts[] = '"' . $column . '" = :' . $column;
                }
                $sql = 'UPDATE "article" SET ' . implode(', ', $setParts) . ' WHERE "ctx_id" = :ctx_id';
                $statement = $db->prepare($sql);
                foreach ($columns as $column) {
                    $statement->bindValue(':' . $column, isset($data[$column]) ? $data[$column] : '');
                }
                $statement->execute();
            } else {
                $data = $record;
                if (!isset($data['ctx_id']) || $data['ctx_id'] === '') {
                    $data['ctx_id'] = str_replace('.', '', uniqid(time(), true));
                }
                $data['time'] = $now;
                $columns = array_merge(ArticleService::RENEW_COLUMNS, ['time', 'update_date']);
                $columnsSql = [];
                $values = [];
                foreach ($columns as $column) {
                    $columnsSql[] = '"' . $column . '"';
                    $values[] = ':' . $column;
                }
                $sql = 'INSERT INTO "article" (' . implode(', ', $columnsSql) . ') VALUES (' . implode(', ', $values) . ')';
                $statement = $db->prepare($sql);
                foreach ($columns as $column) {
                    $statement->bindValue(':' . $column, isset($data[$column]) ? $data[$column] : '');
                }
                $statement->execute();
            }
            $db->exec('COMMIT');
        } catch (\Exception $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
        Cache::forget('article:count');
        $data['ctx_id'] = isset($data['ctx_id']) ? $data['ctx_id'] : (isset($existing['ctx_id']) ? $existing['ctx_id'] : '');
        return $data;
    }

    public static function all()
    {
        self::ensureTable();
        return Database::fetchAll('SELECT * FROM "article" ORDER BY "id" DESC');
    }

    const SORTABLE = ['id', 'ctx_id', 'url', 'title', 'keyword', 'tags', 'lang', 'series', 'pubdir', 'globalpublish', 'pubdomain', 'time'];

    public static function search($search, $page, $perPage, $sort = 'id', $order = 'desc')
    {
        self::ensureTable();
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = ' WHERE "title" LIKE :kw OR "keyword" LIKE :kw OR "tags" LIKE :kw OR "url" LIKE :kw OR "pubdomain" LIKE :kw OR "lang" LIKE :kw OR "series" LIKE :kw OR "pubdir" LIKE :kw OR "ctx_id" LIKE :kw';
            $params['kw'] = '%' . $search . '%';
        }
        $orderBy = ' ORDER BY "id" DESC';
        if (in_array($sort, self::SORTABLE, true)) {
            $direction = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
            $orderBy = ' ORDER BY "' . $sort . '" ' . $direction . ', "id" DESC';
        }
        $total = Database::fetchOne('SELECT COUNT(*) AS "c" FROM "article"' . $where, $params);
        $totalCount = isset($total['c']) ? (int)$total['c'] : 0;
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $rows = Database::fetchAll(
            'SELECT "id", "ctx_id", "url", "title", "keyword", "tags", "description", "static_thumbnail", "iframesrc", "lang", "series", "pubdir", "savename", "globalpublish", "pubdomain", "translate_to_langs", "json_file", "time", "update_date"'
            . ' FROM "article"' . $where . $orderBy . ' LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset,
            $params
        );
        return [
            'rows' => $rows,
            'total' => $totalCount,
        ];
    }
}
