<?php

namespace App\Repositories;

use App\Database;
use App\Services\TopicService;

class TopicRepository
{
    const TABLE = 'sitetopic';

    public static function ensureTable()
    {
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

    public static function byKeywordAndGitName($keyword, $gitName)
    {
        self::ensureTable();
        return Database::fetchOne(
            'SELECT * FROM "sitetopic" WHERE "keyword" = :keyword AND "git_name" = :git_name LIMIT 1',
            ['keyword' => $keyword, 'git_name' => $gitName]
        );
    }

    public static function upsertByTopic(array $record)
    {
        self::ensureTable();
        $db = Database::connection();
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
        $db->exec('BEGIN');
        try {
            if ($existing !== null) {
                $data = $record;
                if (!empty($existing['ctx_id'])) {
                    $data['ctx_id'] = $existing['ctx_id'];
                }
                $data['time'] = $now;
                $setParts = [];
                foreach (TopicService::RENEW_COLUMNS as $column) {
                    $setParts[] = '"' . $column . '" = :' . $column;
                }
                $sql = 'UPDATE "sitetopic" SET ' . implode(', ', $setParts) . ' WHERE "ctx_id" = :ctx_id';
                $statement = $db->prepare($sql);
                foreach (TopicService::RENEW_COLUMNS as $column) {
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
                foreach (TopicService::RENEW_COLUMNS as $column) {
                    $columns[] = '"' . $column . '"';
                    $values[] = ':' . $column;
                }
                $sql = 'INSERT INTO "sitetopic" (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
                $statement = $db->prepare($sql);
                foreach (TopicService::RENEW_COLUMNS as $column) {
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
        self::ensureTable();
        $rows = Database::fetchAll('SELECT "domain", "status", "lasttask" FROM "sitetopic"');
        $byStatus = ['total' => 0, 'aidone' => 0, 'enable' => 0, 'other' => 0];
        $byDomain = [];
        $byDate = [];
        foreach ($rows as $row) {
            $status = isset($row['status']) ? $row['status'] : '';
            $byStatus['total']++;
            if ($status === 'aidone') {
                $byStatus['aidone']++;
            } elseif ($status === 'enable') {
                $byStatus['enable']++;
            } else {
                $byStatus['other']++;
            }

            $domain = isset($row['domain']) ? $row['domain'] : '';
            if ($domain !== '') {
                if (!isset($byDomain[$domain])) {
                    $byDomain[$domain] = ['total' => 0, 'aidone' => 0, 'enable' => 0, 'other' => 0];
                }
                $byDomain[$domain]['total']++;
                if ($status === 'aidone') {
                    $byDomain[$domain]['aidone']++;
                } elseif ($status === 'enable') {
                    $byDomain[$domain]['enable']++;
                } else {
                    $byDomain[$domain]['other']++;
                }
            }

            $lasttask = isset($row['lasttask']) ? (string)$row['lasttask'] : '';
            $taskDate = strlen($lasttask) >= 8 ? substr($lasttask, 0, 8) : '';
            if ($taskDate !== '') {
                if (!isset($byDate[$taskDate])) {
                    $byDate[$taskDate] = ['total' => 0, 'aidone' => 0, 'enable' => 0, 'other' => 0];
                }
                $byDate[$taskDate]['total']++;
                if ($status === 'aidone') {
                    $byDate[$taskDate]['aidone']++;
                } elseif ($status === 'enable') {
                    $byDate[$taskDate]['enable']++;
                } else {
                    $byDate[$taskDate]['other']++;
                }
            }
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
        $lines = [];
        foreach (self::all() as $row) {
            $parts = [];
            foreach (TopicService::EXPORT_COLUMNS as $column) {
                $parts[] = isset($row[$column]) ? (string)$row[$column] : '';
            }
            $lines[] = implode('|', $parts);
        }
        file_put_contents(\App\Config::dataDir() . '/topic_monitor_list.txt', implode(PHP_EOL, $lines));
    }
}
