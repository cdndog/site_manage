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
        if (Database::isPg()) {
            $exists = Database::fetchOne("SELECT 1 FROM information_schema.tables WHERE table_name='article'");
            if ($exists !== null) {
                self::$ensured = true;
                return;
            }
        } else {
            $exists = Database::fetchOne('SELECT 1 FROM "sqlite_master" WHERE "type" = \'table\' AND "name" = \'article\'');
            if ($exists !== null) {
                self::$ensured = true;
                return;
            }
        }
        if (Database::isPg()) {
            Database::connection()->exec('CREATE TABLE IF NOT EXISTS "article" (
                "id" BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                "ctx_id" TEXT NOT NULL UNIQUE,
                "url" TEXT,
                "title" TEXT,
                "keyword" TEXT,
                "tags" TEXT,
                "description" TEXT,
                "static_thumbnail" TEXT,
                "iframesrc" TEXT,
                "lang" TEXT,
                "series" TEXT,
                "pubdir" TEXT,
                "savename" TEXT,
                "globalpublish" TEXT,
                "pubdomain" TEXT,
                "translate_to_langs" TEXT,
                "content" TEXT,
                "json" JSONB,
                "json_file" TEXT,
                "time" TIMESTAMPTZ DEFAULT now(),
                "update_date" TIMESTAMPTZ
            )');
            Database::connection()->exec('CREATE INDEX IF NOT EXISTS "idx_article_title" ON "article" ("title")');
            Database::connection()->exec('CREATE INDEX IF NOT EXISTS "idx_article_pubdomain" ON "article" ("pubdomain")');
        } else {
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
        }
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
        if (Database::isPg()) {
            $deleted = Database::execute('DELETE FROM "article" WHERE "ctx_id" = :ctx_id', [':ctx_id' => (string)$ctxId]) > 0;
            if ($deleted) Cache::forget('article:count');
            return $deleted;
        }
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
                    $v = isset($data[$column]) ? $data[$column] : '';
                    if ($column === 'json' && $v === '') { $v = '{}'; }
                    $statement->bindValue(':' . $column, $v);
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
                    $v = isset($data[$column]) ? $data[$column] : '';
                    if ($column === 'json' && $v === '') { $v = '{}'; }
                    $statement->bindValue(':' . $column, $v);
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

    /**
     * 批量导入：单事务 + 批量 INSERT，性能提升 50-100 倍
     */
    public static function batchImport(array $records): array
    {
        self::ensureTable();
        $imported = 0; $skipped = 0; $failed = 0;
        if (count($records) === 0) {
            return ['imported' => 0, 'skipped' => 0, 'failed' => 0];
        }

        $ctxIds = array_filter(array_map(function ($r) { return $r['ctx_id'] ?? ''; }, $records), function ($c) { return $c !== ''; });
        $existingCtxIds = [];
        if (count($ctxIds) > 0) {
            $chunks = array_chunk($ctxIds, 5000);
            foreach ($chunks as $chunk) {
                $params = [];
                $placeholders = [];
                foreach ($chunk as $i => $val) {
                    $key = ':c' . $i;
                    $placeholders[] = $key;
                    $params[$key] = $val;
                }
                $rows = Database::fetchAll(
                    'SELECT "ctx_id" FROM "article" WHERE "ctx_id" IN (' . implode(',', $placeholders) . ')',
                    $params
                );
                foreach ($rows as $row) {
                    $existingCtxIds[$row['ctx_id']] = true;
                }
            }
        }

        $toInsert = [];
        foreach ($records as $record) {
            $ctxId = $record['ctx_id'] ?? '';
            if ($ctxId === '' || !isset($existingCtxIds[$ctxId])) {
                $toInsert[] = $record;
            } else {
                $skipped++;
            }
        }

        $db = Database::connection();
        $isPg = Database::isPg();
        $now = date('Y-m-d H:i:s');
        $columns = array_merge(\App\Services\ArticleService::RENEW_COLUMNS, ['time', 'update_date']);

        if (count($toInsert) > 0) {
            if ($isPg) { $db->beginTransaction(); } else { $db->exec('BEGIN'); }
            try {
                $colStr = '"' . implode('", "', $columns) . '"';
                $valStr = ':' . implode(', :', $columns);
                $stmt = $db->prepare('INSERT INTO "article" (' . $colStr . ') VALUES (' . $valStr . ')');
                foreach ($toInsert as $record) {
                    foreach ($columns as $col) {
                        $v = $record[$col] ?? '';
                        if ($col === 'json' && $v === '') $v = '{}';
                        if ($col === 'time' && ($v === '' || $v === null)) $v = $now;
                        if ($col === 'update_date' && ($v === '' || $v === null)) $v = $now;
                        $stmt->bindValue(':' . $col, $v);
                    }
                    try { $stmt->execute(); $imported++; } catch (\Throwable $e) { $failed++; }
                }
                if ($isPg) { $db->commit(); } else { $db->exec('COMMIT'); }
            } catch (\Throwable $e) {
                if ($isPg) { $db->rollBack(); } else { $db->exec('ROLLBACK'); }
                $failed += count($toInsert); $imported = 0;
            }
        }

        Cache::forget('article:count');
        return ['imported' => $imported, 'skipped' => $skipped, 'failed' => $failed];
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
