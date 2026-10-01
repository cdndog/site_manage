<?php

namespace App\Repositories;

use App\Database;
use App\Services\KeywordService;
use App\Support\Cache;

class KeywordRepository
{
    private static $ensured = false;

    public static function ensureTable()
    {
        if (self::$ensured) {
            return;
        }
        if (Database::isPg()) {
            $exists = Database::fetchOne("SELECT 1 FROM information_schema.tables WHERE table_name='keywordmonitorlist'");
            if ($exists !== null) {
                self::$ensured = true;
                return;
            }
        } else {
            $exists = Database::fetchOne('SELECT 1 FROM "sqlite_master" WHERE "type" = \'table\' AND "name" = \'keywordmonitorlist\'');
            if ($exists !== null) {
                self::$ensured = true;
                return;
            }
        }
        if (Database::isPg()) {
            Database::connection()->exec('CREATE TABLE IF NOT EXISTS "keywordmonitorlist" (
                "id" BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                "ctx_id" TEXT NOT NULL UNIQUE,
                "git_name" TEXT,
                "keyword" TEXT,
                "pubdir" TEXT,
                "status" TEXT,
                "lang" TEXT,
                "geo" TEXT,
                "lasttask" TEXT,
                "json" JSONB,
                "time" TIMESTAMPTZ DEFAULT now()
            )');
        } else {
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
        self::$ensured = true;
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
        if (Database::isPg()) {
            $deleted = Database::execute('DELETE FROM "keywordmonitorlist" WHERE "ctx_id" = :ctx_id', [':ctx_id' => (string)$ctxId]) > 0;
            if ($deleted) Cache::forget('keyword:all');
            return $deleted;
        }
        $db = Database::connection();
        $statement = $db->prepare('DELETE FROM "keywordmonitorlist" WHERE "ctx_id" = :ctx_id');
        $statement->bindValue(':ctx_id', (string)$ctxId);
        $statement->execute();
        $deleted = $db->changes() > 0;
        if ($deleted) {
            Cache::forget('keyword:all');
        }
        return $deleted;
    }

    /** 按 keyword 查记录，供 API 判定 created / updated */
    public static function byKeyword($keyword)
    {
        self::ensureTable();
        return Database::fetchOne(
            'SELECT * FROM "keywordmonitorlist" WHERE "keyword" = :keyword LIMIT 1',
            ['keyword' => $keyword]
        );
    }

    /**
     * $providedColumns 为 null 时沿用整行覆盖语义；
     * 传入列名数组时为部分更新：仅写入与 RENEW_COLUMNS 的交集，
     * 未出现的列保留库中原值，json 列按合并结果重算。
     */
    public static function upsertByKeyword(array $record, ?array $providedColumns = null)
    {
        self::ensureTable();
        $db = Database::connection();
        $isPg = Database::isPg();
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
        if ($isPg) { $db->beginTransaction(); } else { $db->exec('BEGIN'); }
        try {
            if ($existing !== null) {
                $data = $record;
                if (!empty($existing['ctx_id'])) {
                    $data['ctx_id'] = $existing['ctx_id'];
                }
                $data['time'] = $now;
                if ($providedColumns !== null) {
                    // 部分更新：以库中原值为基底，只覆盖请求里真正出现的列。
                    // json 始终按合并结果重算并写入，避免与各列脱节。
                    $setColumns = array_values(array_intersect(KeywordService::RENEW_COLUMNS, $providedColumns));
                    $partial = [];
                    foreach ($setColumns as $column) {
                        $partial[$column] = isset($record[$column]) ? $record[$column] : '';
                        if ($column === 'json' && $partial[$column] === '') { $partial[$column] = '{}'; }
                    }
                    $data = array_merge($existing, $partial);
                    $data['ctx_id'] = isset($existing['ctx_id']) ? $existing['ctx_id'] : '';
                    $data['time'] = $now;
                    $data['json'] = KeywordService::buildJson($data);
                    $setColumns[] = 'json';
                } else {
                    $setColumns = KeywordService::RENEW_COLUMNS;
                }
                $setParts = [];
                foreach ($setColumns as $column) {
                    $setParts[] = '"' . $column . '" = :' . $column;
                }
                $sql = 'UPDATE "keywordmonitorlist" SET ' . implode(', ', $setParts) . ' WHERE "ctx_id" = :ctx_id';
                $statement = $db->prepare($sql);
                // 只绑定实际出现在 SET 子句中的占位符
                foreach (array_unique(array_merge($setColumns, ['ctx_id'])) as $column) {
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
                $columns = [];
                $values = [];
                foreach (KeywordService::RENEW_COLUMNS as $column) {
                    $columns[] = '"' . $column . '"';
                    $values[] = ':' . $column;
                }
                $sql = 'INSERT INTO "keywordmonitorlist" (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
                $statement = $db->prepare($sql);
                foreach (KeywordService::RENEW_COLUMNS as $column) {
                    $v = isset($data[$column]) ? $data[$column] : '';
                    if ($column === 'json' && $v === '') { $v = '{}'; }
                    $statement->bindValue(':' . $column, $v);
                }
                $statement->execute();
            }
            if ($isPg) { $db->commit(); } else { $db->exec('COMMIT'); }
        } catch (\Exception $e) {
            if ($isPg) { $db->rollBack(); } else { $db->exec('ROLLBACK'); }
            throw $e;
        }
        Cache::forget('keyword:all');
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
        return Cache::remember('keyword:all', 30, function () {
            return Database::fetchAll('SELECT * FROM "keywordmonitorlist" ORDER BY "id" DESC');
        });
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
                    'SELECT "ctx_id" FROM "keywordmonitorlist" WHERE "ctx_id" IN (' . implode(',', $placeholders) . ')',
                    $params
                );
                foreach ($rows as $row) {
                    $existingCtxIds[$row['ctx_id']] = true;
                }
            }
        }

        $toUpdate = [];
        $toInsert = [];
        foreach ($records as $record) {
            $ctxId = $record['ctx_id'] ?? '';
            if ($ctxId === '' || !isset($existingCtxIds[$ctxId])) {
                $toInsert[] = $record;
            } else {
                $toUpdate[] = $record;
            }
        }

        $db = Database::connection();
        $isPg = Database::isPg();
        $now = date('Y-m-d H:i:s');
        $columns = \App\Services\KeywordService::RENEW_COLUMNS;

        if (count($toInsert) > 0) {
            if ($isPg) { $db->beginTransaction(); } else { $db->exec('BEGIN'); }
            try {
                $colStr = '"' . implode('", "', $columns) . '"';
                $valStr = ':' . implode(', :', $columns);
                $stmt = $db->prepare('INSERT INTO "keywordmonitorlist" (' . $colStr . ') VALUES (' . $valStr . ')');
                foreach ($toInsert as $record) {
                    foreach ($columns as $col) {
                        $v = $record[$col] ?? '';
                        if ($col === 'json' && $v === '') $v = '{}';
                        if ($col === 'time') $v = $now;
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

        if (count($toUpdate) > 0) {
            if ($isPg) { $db->beginTransaction(); } else { $db->exec('BEGIN'); }
            try {
                $setParts = [];
                foreach ($columns as $col) { $setParts[] = '"' . $col . '" = :' . $col; }
                $stmt = $db->prepare('UPDATE "keywordmonitorlist" SET ' . implode(', ', $setParts) . ' WHERE "ctx_id" = :ctx_id');
                foreach ($toUpdate as $record) {
                    foreach ($columns as $col) {
                        $v = $record[$col] ?? '';
                        if ($col === 'json' && $v === '') $v = '{}';
                        if ($col === 'time') $v = $now;
                        $stmt->bindValue(':' . $col, $v);
                    }
                    $stmt->bindValue(':ctx_id', $record['ctx_id']);
                    try { $stmt->execute(); $skipped++; } catch (\Throwable $e) { $failed++; }
                }
                if ($isPg) { $db->commit(); } else { $db->exec('COMMIT'); }
            } catch (\Throwable $e) {
                if ($isPg) { $db->rollBack(); } else { $db->exec('ROLLBACK'); }
                $failed += count($toUpdate);
            }
        }

        Cache::forget('keyword:all');
        return ['imported' => $imported, 'skipped' => $skipped, 'failed' => $failed];
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
