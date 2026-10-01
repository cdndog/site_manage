<?php

namespace App\Repositories;

use App\Database;

class AigcStatusRepository
{
    const TABLE = 'aigc_status';

    public static function ensureTable()
    {
        if (Database::isPg()) {
            Database::connection()->exec('CREATE TABLE IF NOT EXISTS "aigc_status" ('
                . '"ctx_id" TEXT PRIMARY KEY, "keyword" TEXT, "lang" TEXT, "pubdomain" TEXT, "createAt" TEXT, "publishAt" TEXT, "search_text" TEXT)');
            $hasSearch = Database::fetchOne("SELECT 1 FROM information_schema.columns WHERE table_name='aigc_status' AND column_name='search_text'");
            if ($hasSearch === null) {
                Database::connection()->exec('ALTER TABLE "aigc_status" ADD COLUMN IF NOT EXISTS "search_text" TEXT');
            }
            Database::connection()->exec('CREATE INDEX IF NOT EXISTS "idx_aigc_publishAt" ON "aigc_status" ("publishAt" DESC)');
            Database::connection()->exec('CREATE INDEX IF NOT EXISTS "idx_aigc_pubdomain" ON "aigc_status" ("pubdomain")');
            Database::connection()->exec('CREATE INDEX IF NOT EXISTS "idx_aigc_lang" ON "aigc_status" ("lang")');
            Database::connection()->exec('CREATE INDEX IF NOT EXISTS "idx_aigc_keyword" ON "aigc_status" ("keyword")');
            Database::connection()->exec('CREATE INDEX IF NOT EXISTS "idx_aigc_search" ON "aigc_status" ("search_text")');
            return;
        }
        Database::connection()->exec('CREATE TABLE IF NOT EXISTS "aigc_status" ('
            . '"ctx_id" TEXT PRIMARY KEY, "keyword" TEXT, "lang" TEXT, "pubdomain" TEXT, "createAt" TEXT, "publishAt" TEXT, "search_text" TEXT'
            . ') WITHOUT ROWID');
        Database::connection()->exec('CREATE INDEX IF NOT EXISTS "idx_aigc_publishAt" ON "aigc_status" ("publishAt" DESC)');
        Database::connection()->exec('CREATE INDEX IF NOT EXISTS "idx_aigc_pubdomain" ON "aigc_status" ("pubdomain")');
        Database::connection()->exec('CREATE INDEX IF NOT EXISTS "idx_aigc_lang" ON "aigc_status" ("lang")');
        Database::connection()->exec('CREATE INDEX IF NOT EXISTS "idx_aigc_keyword" ON "aigc_status" ("keyword")');
        $hasSearch = Database::connection()->querySingle('SELECT 1 FROM pragma_table_info("aigc_status") WHERE name="search_text"');
        if (!$hasSearch) {
            Database::connection()->exec('ALTER TABLE "aigc_status" ADD COLUMN "search_text" TEXT');
            Database::connection()->exec('UPDATE "aigc_status" SET "search_text"=lower("ctx_id"||"|"||"keyword"||"|"||"lang"||"|"||"pubdomain"||"|"||"createAt"||"|"||"publishAt")');
        }
        Database::connection()->exec('CREATE INDEX IF NOT EXISTS "idx_aigc_search" ON "aigc_status" ("search_text")');
    }

    public static function search($search, $page, $perPage, $sort = 'publishAt', $order = 'desc')
    {
        self::ensureTable();
        $allowedSort = ['ctx_id', 'keyword', 'lang', 'pubdomain', 'createAt', 'publishAt'];
        if (!in_array($sort, $allowedSort, true)) {
            $sort = 'publishAt';
        }
        $order = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
        // 空搜索走覆盖索引的 COUNT，无需 LIKE
        if ($search === '') {
            $row = Database::fetchOne('SELECT COUNT(*) as cnt FROM "' . self::TABLE . '"');
            $total = $row ? (int)$row['cnt'] : 0;
            $offset = ($page - 1) * $perPage;
            $sql = 'SELECT * FROM "' . self::TABLE . '" ORDER BY "' . $sort . '" ' . $order . ' LIMIT :limit OFFSET :offset';
            $rows = Database::fetchAll($sql, [':limit' => $perPage, ':offset' => $offset]);
            return ['rows' => $rows, 'total' => $total];
        }
        // 全列检索走单列 search_text 索引，避免 6 列 OR 的多次绑定与重复 SCAN
        $where = ' WHERE "search_text" LIKE :s';
        $params = [':s' => '%' . strtolower($search) . '%'];
        $countSql = 'SELECT COUNT(*) as cnt FROM "' . self::TABLE . '"' . $where;
        $row = Database::fetchOne($countSql, $params);
        $total = $row ? (int)$row['cnt'] : 0;
        if ($total === 0) {
            return ['rows' => [], 'total' => 0];
        }
        $offset = ($page - 1) * $perPage;
        if ($offset >= $total) {
            return ['rows' => [], 'total' => $total];
        }
        $sql = 'SELECT * FROM "' . self::TABLE . '"' . $where . ' ORDER BY "' . $sort . '" ' . $order . ' LIMIT :limit OFFSET :offset';
        $params[':limit'] = $perPage;
        $params[':offset'] = $offset;
        $rows = Database::fetchAll($sql, $params);
        return ['rows' => $rows, 'total' => $total];
    }

    public static function all()
    {
        self::ensureTable();
        return Database::fetchAll('SELECT * FROM "' . self::TABLE . '" ORDER BY "publishAt" DESC');
    }
}
