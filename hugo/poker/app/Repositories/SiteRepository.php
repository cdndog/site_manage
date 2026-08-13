<?php

namespace App\Repositories;

use App\Database;
use App\Services\SiteService;

class SiteRepository
{
    public static function findByCtxId($ctxId)
    {
        return Database::fetchOne(
            'SELECT * FROM "siteops" WHERE "ctx_id" = :ctx_id LIMIT 1',
            ['ctx_id' => $ctxId]
        );
    }

    public static function upsertByDomain(array $site)
    {
        $db = Database::connection();
        $existing = Database::fetchOne(
            'SELECT * FROM "siteops" WHERE "domain" = :domain LIMIT 1',
            ['domain' => $site['domain']]
        );
        $now = date('Y-m-d H:i:s');
        $db->exec('BEGIN');
        try {
            if ($existing !== null) {
                $data = $site;
                if (!empty($existing['ctx_id'])) {
                    $data['ctx_id'] = $existing['ctx_id'];
                }
                $data['time'] = $now;
                $setParts = [];
                foreach (SiteService::RENEW_COLUMNS as $column) {
                    $setParts[] = '"' . $column . '" = :' . $column;
                }
                $sql = 'UPDATE "siteops" SET ' . implode(', ', $setParts) . ' WHERE "domain" = :domain';
                $statement = $db->prepare($sql);
                foreach (SiteService::RENEW_COLUMNS as $column) {
                    $statement->bindValue(':' . $column, isset($data[$column]) ? $data[$column] : '');
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
                    $statement->bindValue(':' . $column, isset($data[$column]) ? $data[$column] : '');
                }
                $statement->execute();
                $result = null;
            }
            $db->exec('COMMIT');
        } catch (\Exception $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
        return $result;
    }

    public static function all()
    {
        return Database::fetchAll('SELECT * FROM "siteops" WHERE "*" = "*"');
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
