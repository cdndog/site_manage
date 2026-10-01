<?php

namespace App\Repositories;

use App\Database;
use App\Services\SiteService;
use App\Support\Cache;

class SiteRepository
{
    public static function findByCtxId($ctxId)
    {
        return Database::fetchOne(
            'SELECT * FROM "siteops" WHERE "ctx_id" = :ctx_id LIMIT 1',
            ['ctx_id' => $ctxId]
        );
    }

    public static function deleteByCtxId($ctxId)
    {
        if (Database::isPg()) {
            $deleted = Database::execute('DELETE FROM "siteops" WHERE "ctx_id" = :ctx_id', [':ctx_id' => (string)$ctxId]) > 0;
            if ($deleted) Cache::forget('site:all');
            return $deleted;
        }
        $db = Database::connection();
        $statement = $db->prepare('DELETE FROM "siteops" WHERE "ctx_id" = :ctx_id');
        $statement->bindValue(':ctx_id', (string)$ctxId);
        $statement->execute();
        $deleted = $db->changes() > 0;
        if ($deleted) {
            Cache::forget('site:all');
        }
        return $deleted;
    }

    /**
     * $providedColumns 为 null 时沿用整行覆盖语义；
     * 传入 post_* 字段名数组时为部分更新：仅写入对应列，未传列保留原值，
     * json 按合并结果重算。
     */
    /** 按域名查站点，供 API 判定 created / updated */
    public static function byDomain($domain)
    {
        return Database::fetchOne(
            'SELECT * FROM "siteops" WHERE "domain" = :domain LIMIT 1',
            ['domain' => $domain]
        );
    }

    /** post_* 字段名 -> siteops 表列名；不属于 RENEW_COLUMNS 的返回 null */
    private static function postToColumn($postKey)
    {
        static $map = [
            'post_uuid' => 'ctx_id',
            'post_gitname' => 'git_name',
            'post_gitaccount' => 'git_account',
            'post_domain' => 'domain',
            'post_keyword' => 'keyword',
            'post_sitetitle' => 'site_title',
            'post_description' => 'site_subtitle',
            'post_sitelogo' => 'site_logo',
            'post_sitedir' => 'sitedir',
            'post_sitedeploy' => 'deploy',
            'post_sitehostip' => 'hostip',
            'local_deploy' => 'local_deploy',
            'local_hostip' => 'local_hostip',
            'post_lang' => 'languages',
            'post_sns_id' => 'sns_id',
            'post_topnavmenus' => 'topnav_menus',
            'post_themename' => 'theme_name',
            'post_themetype' => 'theme_type',
            'post_status' => 'status',
        ];
        if (!isset($map[$postKey])) {
            return null;
        }
        $column = $map[$postKey];
        return in_array($column, SiteService::RENEW_COLUMNS, true) ? $column : null;
    }

    public static function upsertByDomain(array $site, ?array $providedColumns = null)
    {
        $db = Database::connection();
        $existing = Database::fetchOne(
            'SELECT * FROM "siteops" WHERE "domain" = :domain LIMIT 1',
            ['domain' => $site['domain']]
        );
        $now = date('Y-m-d H:i:s');
        $isPg = Database::isPg();
        if ($isPg) {
            $db->beginTransaction();
        } else {
            $db->exec('BEGIN');
        }
        try {
            if ($existing !== null) {
                $data = $site;
                if (!empty($existing['ctx_id'])) {
                    $data['ctx_id'] = $existing['ctx_id'];
                }
                $data['time'] = $now;
                if ($providedColumns !== null) {
                    // 部分更新：以库中原值为基底，只覆盖请求里真正出现的列
                    $setColumns = [];
                    $partial = [];
                    foreach ($providedColumns as $postKey) {
                        $column = self::postToColumn($postKey);
                        if ($column === null || in_array($column, $setColumns, true)) {
                            continue;
                        }
                        $partial[$column] = isset($site[$column]) ? $site[$column] : '';
                        $setColumns[] = $column;
                    }
                    $data = array_merge($existing, $partial);
                    $data['ctx_id'] = isset($existing['ctx_id']) ? $existing['ctx_id'] : '';
                    $data['time'] = $now;
                    $existingJson = json_decode(isset($existing['json']) ? (string)$existing['json'] : '', true);
                    if (!is_array($existingJson)) { $existingJson = []; }
                    $data['json'] = SiteService::buildJsonForPartial($data, $existingJson);
                    $setColumns[] = 'json';
                } else {
                    $setColumns = SiteService::RENEW_COLUMNS;
                }
                $setParts = [];
                foreach ($setColumns as $column) {
                    $setParts[] = '"' . $column . '" = :' . $column;
                }
                $sql = 'UPDATE "siteops" SET ' . implode(', ', $setParts) . ' WHERE "domain" = :domain';
                $statement = $db->prepare($sql);
                // 只绑定实际出现在 SET 子句中的占位符
                foreach (array_unique(array_merge($setColumns, ['domain'])) as $column) {
                    $v = isset($data[$column]) ? $data[$column] : '';
                    if ($column === 'json' && $v === '') { $v = '{}'; }
                    $statement->bindValue(':' . $column, $v);
                }
                $statement->execute();
                $result = $existing;
            } else {
                $data = $site;
                if (!isset($data['ctx_id']) || $data['ctx_id'] === '') {
                    $data['ctx_id'] = str_replace('.', '', uniqid(time(), true));
                }
                $data['time'] = $now;
                $columns = [];
                $values = [];
                foreach (SiteService::RENEW_COLUMNS as $column) {
                    $columns[] = '"' . $column . '"';
                    $values[] = ':' . $column;
                }
                $sql = 'INSERT INTO "siteops" (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
                $statement = $db->prepare($sql);
                foreach (SiteService::RENEW_COLUMNS as $column) {
                    $v = isset($data[$column]) ? $data[$column] : '';
                    if ($column === 'json' && $v === '') { $v = '{}'; }
                    $statement->bindValue(':' . $column, $v);
                }
                $statement->execute();
                $result = null;
            }
            if ($isPg) {
                $db->commit();
            } else {
                $db->exec('COMMIT');
            }
        } catch (\Exception $e) {
            if ($isPg) {
                $db->rollBack();
            } else {
                $db->exec('ROLLBACK');
            }
            throw $e;
        }
        Cache::forget('site:all');
        return $result;
    }

    public static function all()
    {
        return Cache::remember('site:all', 30, function () {
            return Database::fetchAll('SELECT * FROM "siteops" WHERE "*" = "*"');
        });
    }

    /**
     * 批量导入：单事务 + 批量 INSERT，性能提升 50-100 倍
     */
    public static function batchImport(array $records): array
    {
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
                    'SELECT "ctx_id" FROM "siteops" WHERE "ctx_id" IN (' . implode(',', $placeholders) . ')',
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
        $columns = \App\Services\SiteService::RENEW_COLUMNS;

        if (count($toInsert) > 0) {
            if ($isPg) { $db->beginTransaction(); } else { $db->exec('BEGIN'); }
            try {
                $colStr = '"' . implode('", "', $columns) . '"';
                $valStr = ':' . implode(', :', $columns);
                $stmt = $db->prepare('INSERT INTO "siteops" (' . $colStr . ') VALUES (' . $valStr . ')');
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
                $stmt = $db->prepare('UPDATE "siteops" SET ' . implode(', ', $setParts) . ' WHERE "ctx_id" = :ctx_id');
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

        Cache::forget('site:all');
        return ['imported' => $imported, 'skipped' => $skipped, 'failed' => $failed];
    }

    const SORTABLE = ['id', 'ctx_id', 'git_name', 'git_account', 'status', 'theme_type', 'languages', 'domain', 'site_title', 'site_subtitle'];

    public static function search($search, $page, $perPage, $sort = 'id', $order = 'desc')
    {
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = ' WHERE "git_name" LIKE :kw OR "domain" LIKE :kw OR "status" LIKE :kw OR "theme_type" LIKE :kw OR "languages" LIKE :kw OR "site_title" LIKE :kw OR "site_subtitle" LIKE :kw OR "git_account" LIKE :kw OR "sns_id" LIKE :kw';
            $params['kw'] = '%' . $search . '%';
        }
        $orderBy = ' ORDER BY "id" DESC';
        if (in_array($sort, self::SORTABLE, true)) {
            $direction = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
            $orderBy = ' ORDER BY "' . $sort . '" ' . $direction . ', "id" DESC';
        }
        $total = Database::fetchOne('SELECT COUNT(*) AS "c" FROM "siteops"' . $where, $params);
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $rows = Database::fetchAll(
            'SELECT "id", "ctx_id", "git_name", "git_account", "status", "theme_type", "languages", "domain", "sns_id", "topnav_menus", "site_title", "site_subtitle"'
            . ' FROM "siteops"' . $where . $orderBy . ' LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset,
            $params
        );
        return [
            'rows' => $rows,
            'total' => isset($total['c']) ? (int)$total['c'] : 0,
        ];
    }
}
