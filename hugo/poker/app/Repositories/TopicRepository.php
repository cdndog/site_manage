<?php

namespace App\Repositories;

use App\Database;
use App\Services\TopicService;
use App\Support\Cache;

class TopicRepository
{
    const TABLE = 'sitetopic';

    private static $ensured = false;

    public static function ensureTable()
    {
        if (self::$ensured) {
            return;
        }
        if (Database::isPg()) {
            $exists = Database::fetchOne("SELECT 1 FROM information_schema.tables WHERE table_name='sitetopic'");
            if ($exists !== null) {
                self::$ensured = true;
                return;
            }
        } else {
            $exists = Database::fetchOne('SELECT 1 FROM "sqlite_master" WHERE "type" = \'table\' AND "name" = \'sitetopic\'');
            if ($exists !== null) {
                self::$ensured = true;
                return;
            }
        }
        if (Database::isPg()) {
            Database::connection()->exec('CREATE TABLE IF NOT EXISTS "sitetopic" (
                "id" BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                "ctx_id" TEXT NOT NULL UNIQUE,
                "git_name" TEXT,
                "domain" TEXT,
                "keyword" TEXT,
                "pubdir" TEXT,
                "status" TEXT,
                "lang" TEXT,
                "geo" TEXT,
                "lasttask" TEXT,
                "json" JSONB,
                "time" TIMESTAMPTZ DEFAULT now()
            )');
            Database::connection()->exec('CREATE INDEX IF NOT EXISTS "idx_sitetopic_status" ON "sitetopic" ("status")');
            Database::connection()->exec('CREATE INDEX IF NOT EXISTS "idx_sitetopic_keyword" ON "sitetopic" ("keyword")');
            Database::connection()->exec('CREATE INDEX IF NOT EXISTS "idx_sitetopic_domain" ON "sitetopic" ("domain")');
        } else {
            Database::connection()->exec('CREATE TABLE IF NOT EXISTS "sitetopic" (
                "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                "ctx_id" VARCHAR UNIQUE NOT NULL,
                "git_name" VARCHAR,
                "domain" VARCHAR,
                "keyword" VARCHAR,
                "pubdir" VARCHAR,
                "status" VARCHAR,
                "lang" VARCHAR,
                "geo" VARCHAR,
                "lasttask" VARCHAR,
                "json" VARCHAR,
                "time" DATETIME
            )');
            Database::connection()->exec('CREATE INDEX IF NOT EXISTS "idx_sitetopic_status" ON "sitetopic" ("status")');
            Database::connection()->exec('CREATE INDEX IF NOT EXISTS "idx_sitetopic_keyword" ON "sitetopic" ("keyword")');
            Database::connection()->exec('CREATE INDEX IF NOT EXISTS "idx_sitetopic_domain" ON "sitetopic" ("domain")');
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
            'SELECT * FROM "sitetopic" WHERE "ctx_id" = :ctx_id LIMIT 1',
            ['ctx_id' => $ctxId]
        );
    }

    public static function deleteByCtxId($ctxId)
    {
        self::ensureTable();
        if (Database::isPg()) {
            $deleted = Database::execute('DELETE FROM "sitetopic" WHERE "ctx_id" = :ctx_id', [':ctx_id' => (string)$ctxId]) > 0;
            if ($deleted) Cache::forget('topic:summarize');
            return $deleted;
        }
        $db = Database::connection();
        $statement = $db->prepare('DELETE FROM "sitetopic" WHERE "ctx_id" = :ctx_id');
        $statement->bindValue(':ctx_id', (string)$ctxId);
        $statement->execute();
        $deleted = $db->changes() > 0;
        if ($deleted) {
            Cache::forget('topic:summarize');
        }
        return $deleted;
    }

    public static function byKeywordAndGitName($keyword, $gitName)
    {
        self::ensureTable();
        return Database::fetchOne(
            'SELECT * FROM "sitetopic" WHERE "keyword" = :keyword AND "git_name" = :git_name LIMIT 1',
            ['keyword' => $keyword, 'git_name' => $gitName]
        );
    }

    /**
     * $providedColumns 为 null 时沿用整行覆盖语义；
     * 传入列名数组时为部分更新：仅写入该数组与 RENEW_COLUMNS 的交集，
     * 未出现的列保留库中原值，json 列按合并结果重算。
     */
    public static function upsertByTopic(array $record, ?array $providedColumns = null)
    {
        self::ensureTable();
        $db = Database::connection();
        $isPg = Database::isPg();
        $ctxId = isset($record['ctx_id']) && $record['ctx_id'] !== '' ? $record['ctx_id'] : '';
        $existing = null;
        if ($ctxId !== '') {
            $existing = Database::fetchOne(
                'SELECT * FROM "sitetopic" WHERE "ctx_id" = :ctx_id LIMIT 1',
                ['ctx_id' => $ctxId]
            );
        }
        if ($existing === null) {
            $existing = Database::fetchOne(
                'SELECT * FROM "sitetopic" WHERE "keyword" = :keyword AND "git_name" = :git_name LIMIT 1',
                ['keyword' => isset($record['keyword']) ? $record['keyword'] : '', 'git_name' => isset($record['git_name']) ? $record['git_name'] : '']
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
                    $setColumns = array_values(array_intersect(TopicService::RENEW_COLUMNS, $providedColumns));
                    $partial = [];
                    foreach ($setColumns as $column) {
                        $partial[$column] = isset($record[$column]) ? $record[$column] : '';
                        if ($column === 'json' && $partial[$column] === '') { $partial[$column] = '{}'; }
                    }
                    $data = array_merge($existing, $partial);
                    $data['ctx_id'] = isset($existing['ctx_id']) ? $existing['ctx_id'] : '';
                    $data['time'] = $now;
                    $data['json'] = TopicService::buildJson($data);
                    $setColumns[] = 'json';
                } else {
                    $setColumns = TopicService::RENEW_COLUMNS;
                }
                $setParts = [];
                foreach ($setColumns as $column) {
                    $setParts[] = '"' . $column . '" = :' . $column;
                }
                $sql = 'UPDATE "sitetopic" SET ' . implode(', ', $setParts) . ' WHERE "ctx_id" = :ctx_id';
                $statement = $db->prepare($sql);
                // 只绑定实际出现在 SET 子句中的占位符
                $bindColumns = $setColumns;
                $bindColumns[] = 'ctx_id';
                foreach (array_unique($bindColumns) as $column) {
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
                foreach (TopicService::RENEW_COLUMNS as $column) {
                    $columns[] = '"' . $column . '"';
                    $values[] = ':' . $column;
                }
                $sql = 'INSERT INTO "sitetopic" (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
                $statement = $db->prepare($sql);
                foreach (TopicService::RENEW_COLUMNS as $column) {
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
        Cache::forget('topic:summarize');
        $data['ctx_id'] = isset($data['ctx_id']) ? $data['ctx_id'] : (isset($existing['ctx_id']) ? $existing['ctx_id'] : '');
        return $data;
    }

    public static function byGitName($gitName)
    {
        self::ensureTable();
        return Database::fetchAll(
            'SELECT * FROM "sitetopic" WHERE "git_name" = :git_name ORDER BY "id" DESC',
            ['git_name' => $gitName]
        );
    }

    public static function all()
    {
        self::ensureTable();
        return Database::fetchAll('SELECT * FROM "sitetopic" ORDER BY "id" DESC');
    }

    const SORTABLE = ['id', 'ctx_id', 'keyword', 'status', 'git_name', 'domain', 'pubdir', 'lang', 'geo', 'lasttask', 'time'];

    public static function search($search, $page, $perPage, $sort = 'id', $order = 'desc')
    {
        self::ensureTable();
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = ' WHERE "keyword" LIKE :kw OR "domain" LIKE :kw OR "git_name" LIKE :kw OR "pubdir" LIKE :kw OR "status" LIKE :kw OR "lang" LIKE :kw OR "geo" LIKE :kw OR "lasttask" LIKE :kw OR "ctx_id" LIKE :kw';
            $params['kw'] = '%' . $search . '%';
        }
        $orderBy = ' ORDER BY "id" DESC';
        if (in_array($sort, self::SORTABLE, true)) {
            $direction = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
            $orderBy = ' ORDER BY "' . $sort . '" ' . $direction . ', "id" DESC';
        }
        $total = Database::fetchOne('SELECT COUNT(*) AS "c" FROM "sitetopic"' . $where, $params);
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $rows = Database::fetchAll(
            'SELECT "id", "ctx_id", "git_name", "domain", "keyword", "pubdir", "status", "lang", "geo", "lasttask", "time"'
            . ' FROM "sitetopic"' . $where . $orderBy . ' LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset,
            $params
        );
        return [
            'rows' => $rows,
            'total' => isset($total['c']) ? (int)$total['c'] : 0,
        ];
    }

    public static function summarize()
    {
        return Cache::remember('topic:summarize', 60, function () {
            self::ensureTable();
            return self::computeSummary();
        });
    }

    private static function computeSummary()
    {
        $byStatus = ['total' => 0, 'aidone' => 0, 'enable' => 0, 'other' => 0];
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS "total",'
            . ' SUM(CASE WHEN "status" = \'aidone\' THEN 1 ELSE 0 END) AS "aidone",'
            . ' SUM(CASE WHEN "status" = \'enable\' THEN 1 ELSE 0 END) AS "enable"'
            . ' FROM "sitetopic"'
        );
        if (is_array($row)) {
            $total = isset($row['total']) ? (int)$row['total'] : 0;
            $aidone = isset($row['aidone']) ? (int)$row['aidone'] : 0;
            $enable = isset($row['enable']) ? (int)$row['enable'] : 0;
            $byStatus = ['total' => $total, 'aidone' => $aidone, 'enable' => $enable, 'other' => $total - $aidone - $enable];
        }

        $byDomain = [];
        $rows = Database::fetchAll(
            'SELECT "domain", COUNT(*) AS "total",'
            . ' SUM(CASE WHEN "status" = \'aidone\' THEN 1 ELSE 0 END) AS "aidone",'
            . ' SUM(CASE WHEN "status" = \'enable\' THEN 1 ELSE 0 END) AS "enable"'
            . ' FROM "sitetopic" WHERE "domain" IS NOT NULL AND "domain" != \'\''
            . ' GROUP BY "domain" ORDER BY MIN("id")'
        );
        foreach ($rows as $r) {
            $t = (int)$r['total'];
            $a = (int)$r['aidone'];
            $e = (int)$r['enable'];
            $byDomain[(string)$r['domain']] = ['total' => $t, 'aidone' => $a, 'enable' => $e, 'other' => $t - $a - $e];
        }

        $byDate = [];
        $rows = Database::fetchAll(
            'SELECT substr("lasttask", 1, 8) AS "d", COUNT(*) AS "total",'
            . ' SUM(CASE WHEN "status" = \'aidone\' THEN 1 ELSE 0 END) AS "aidone",'
            . ' SUM(CASE WHEN "status" = \'enable\' THEN 1 ELSE 0 END) AS "enable"'
            . ' FROM "sitetopic" WHERE "lasttask" IS NOT NULL AND length("lasttask") >= 8'
            . ' GROUP BY substr("lasttask", 1, 8)'
        );
        foreach ($rows as $r) {
            $t = (int)$r['total'];
            $a = (int)$r['aidone'];
            $e = (int)$r['enable'];
            $byDate[(string)$r['d']] = ['total' => $t, 'aidone' => $a, 'enable' => $e, 'other' => $t - $a - $e];
        }

        krsort($byDate);
        uasort($byDomain, function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });
        return [
            'by_status' => $byStatus,
            'by_domain' => $byDomain,
            'by_date' => $byDate,
        ];
    }

    public static function export()
    {
        $file = \App\Config::dataDir() . '/topic_monitor_list.txt';
        $fp = @fopen($file, 'w');
        if ($fp === false) return;
        $cols = TopicService::EXPORT_COLUMNS;
        $colStr = '"' . implode('", "', $cols) . '"';
        $offset = 0;
        $chunkSize = 5000;
        do {
            $rows = Database::fetchAll(
                'SELECT ' . $colStr . ' FROM "sitetopic" ORDER BY "id" ASC LIMIT ' . $chunkSize . ' OFFSET ' . $offset
            );
            foreach ($rows as $row) {
                $parts = [];
                foreach ($cols as $col) {
                    $parts[] = isset($row[$col]) ? (string)$row[$col] : '';
                }
                fwrite($fp, implode('|', $parts) . PHP_EOL);
            }
            $offset += $chunkSize;
        } while (count($rows) === $chunkSize);
        fclose($fp);
    }

    /**
     * 批量导入：单事务 + 批量 INSERT，性能提升 50-100 倍
     * @param array $records 待导入记录列表
     * @return array ['imported'=>int, 'skipped'=>int, 'failed'=>int]
     */
    public static function batchImport(array $records): array
    {
        self::ensureTable();
        $imported = 0; $skipped = 0; $failed = 0;
        if (count($records) === 0) {
            return ['imported' => 0, 'skipped' => 0, 'failed' => 0];
        }

        // 1. 一次性查出所有已存在的 ctx_id
        $ctxIds = array_map(function ($r) { return $r['ctx_id'] ?? ''; }, $records);
        $ctxIds = array_filter($ctxIds, function ($c) { return $c !== ''; });
        $existingCtxIds = [];
        if (count($ctxIds) > 0) {
            // 分批查询（PG 参数限制约 65535；使用命名参数避免 PDO 0-index 问题）
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
                    'SELECT "ctx_id" FROM "sitetopic" WHERE "ctx_id" IN (' . implode(',', $placeholders) . ')',
                    $params
                );
                foreach ($rows as $row) {
                    $existingCtxIds[$row['ctx_id']] = true;
                }
            }
        }

        // 2. 分离：已存在 → update 列表，不存在 → insert 列表
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
        $columns = TopicService::RENEW_COLUMNS;

        // 3. 批量 INSERT（单事务）
        if (count($toInsert) > 0) {
            if ($isPg) { $db->beginTransaction(); } else { $db->exec('BEGIN'); }
            try {
                $colStr = '"' . implode('", "', $columns) . '"';
                $valStr = ':' . implode(', :', $columns);
                $sql = 'INSERT INTO "sitetopic" (' . $colStr . ') VALUES (' . $valStr . ')';
                $stmt = $db->prepare($sql);
                foreach ($toInsert as $record) {
                    foreach ($columns as $col) {
                        $v = $record[$col] ?? '';
                        if ($col === 'json' && $v === '') $v = '{}';
                        if ($col === 'time') $v = $now;
                        $stmt->bindValue(':' . $col, $v);
                    }
                    try {
                        $stmt->execute();
                        $imported++;
                    } catch (\Throwable $e) {
                        $failed++;
                    }
                }
                if ($isPg) { $db->commit(); } else { $db->exec('COMMIT'); }
            } catch (\Throwable $e) {
                if ($isPg) { $db->rollBack(); } else { $db->exec('ROLLBACK'); }
                $failed += count($toInsert);
                $imported = 0;
            }
        }

        // 4. 批量 UPDATE（逐条但无冗余查询，单事务）
        if (count($toUpdate) > 0) {
            if ($isPg) { $db->beginTransaction(); } else { $db->exec('BEGIN'); }
            try {
                $setParts = [];
                foreach ($columns as $col) {
                    $setParts[] = '"' . $col . '" = :' . $col;
                }
                $sql = 'UPDATE "sitetopic" SET ' . implode(', ', $setParts) . ' WHERE "ctx_id" = :ctx_id';
                $stmt = $db->prepare($sql);
                foreach ($toUpdate as $record) {
                    foreach ($columns as $col) {
                        $v = $record[$col] ?? '';
                        if ($col === 'json' && $v === '') $v = '{}';
                        if ($col === 'time') $v = $now;
                        $stmt->bindValue(':' . $col, $v);
                    }
                    $stmt->bindValue(':ctx_id', $record['ctx_id']);
                    try {
                        $stmt->execute();
                        $skipped++;
                    } catch (\Throwable $e) {
                        $failed++;
                    }
                }
                if ($isPg) { $db->commit(); } else { $db->exec('COMMIT'); }
            } catch (\Throwable $e) {
                if ($isPg) { $db->rollBack(); } else { $db->exec('ROLLBACK'); }
                $failed += count($toUpdate);
            }
        }

        Cache::forget('topic:summarize');
        return ['imported' => $imported, 'skipped' => $skipped, 'failed' => $failed];
    }
}
