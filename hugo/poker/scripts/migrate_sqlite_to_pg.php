<?php
declare(strict_types=1);

$pgDsn = 'pgsql:host=127.0.0.1;port=5432;dbname=sitedb;user=postgres;password=NiceGame12#$';
$sqliteFile = '/Users/apache/hugo/site_manage/hugo/poker/sitedata.sqlite';

define('APP_PATH', dirname(__DIR__));
require APP_PATH . '/app/bootstrap.php';

use App\Database;
use App\Config;

// 1. 连 PG 并清库
$pg = new PDO($pgDsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pg->exec("DROP SCHEMA IF EXISTS public CASCADE");
$pg->exec("CREATE SCHEMA public");
echo "PG schema reset\n";

// 2. 让 Database::migratePg 建规范表（JSONB/TIMESTAMPTZ/IDENTITY）
putenv('APP_DB_DSN=' . $pgDsn);
putenv('APP_DB_FILE=');
Config::reset();
Database::reset();
Database::connection(); // 触发 migratePg
echo "PG schema created via migratePg\n";

// 3. 连 SQLite
$sqlite = new SQLite3($sqliteFile);
$sqlite->enableExceptions(true);
echo "SQLite opened: $sqliteFile\n";

// 4. 表映射：SQLite 表名 => PG 表名（此处相同）
// json 列需 cast 为 JSONB，time 列需 cast 为 TIMESTAMPTZ
$jsonColumns = ['siteops' => ['json'], 'sitetopic' => ['json'], 'keywordmonitorlist' => ['json'], 'article' => ['json'], 'serverlist' => ['json']];
$timeColumns = ['siteops' => ['time'], 'sitetopic' => ['time'], 'keywordmonitorlist' => ['time'], 'article' => ['time', 'update_date'], 'serverlist' => ['time'], 'users' => ['created_at', 'updated_at', 'last_login_at'], 'app_configs' => ['updated_at']];

// 迁移顺序（依赖关系）
$migrateOrder = ['users', 'roles', 'permissions', 'user_roles', 'role_permissions', 'app_configs', 'siteops', 'sitetopic', 'keywordmonitorlist', 'article', 'serverlist', 'aigc_status'];

$stats = [];
foreach ($migrateOrder as $table) {
    $liteCount = $sqlite->querySingle("SELECT COUNT(*) FROM \"$table\"");
    if ($liteCount === null) {
        echo "SKIP $table (not in SQLite)\n";
        continue;
    }
    echo "Migrating $table: $liteCount rows ... ";

    // 获取 SQLite 列
    $cols = [];
    $colInfo = $sqlite->query("PRAGMA table_info(\"$table\")");
    while ($c = $colInfo->fetchArray(SQLITE3_ASSOC)) {
        $cols[] = $c['name'];
    }

    // 获取 PG 实际列（migratePg 可能建了不同类型）
    $pgColsRaw = $pg->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name='$table' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_ASSOC);
    $pgCols = [];
    $pgColTypes = [];
    foreach ($pgColsRaw as $pc) {
        $pgCols[] = $pc['column_name'];
        $pgColTypes[$pc['column_name']] = $pc['data_type'];
    }

    // 只迁移 SQLite 和 PG 都有的列
    $commonCols = array_values(array_intersect($cols, $pgCols));
    if (count($commonCols) === 0) {
        echo "no common columns, SKIP\n";
        continue;
    }

    $colList = '"' . implode('","', $commonCols) . '"';
    $placeholders = ':' . implode(',:', $commonCols);

    // 对 PG 表先清空（migratePg 可能 seed 了 roles/permissions）
    $pg->exec("DELETE FROM \"$table\"");

    // 特殊处理：aigc_status 需要补 search_text
    if ($table === 'aigc_status' && in_array('search_text', $pgCols) && !in_array('search_text', $commonCols)) {
        $commonCols[] = 'search_text';
        $colList = '"' . implode('","', $commonCols) . '"';
        $placeholders = ':' . implode(',:', $commonCols);
    }

    // 检查是否有 IDENTITY 列
    $hasIdentity = false;
    $idColInfo = $pg->query("SELECT column_name, is_identity, identity_generation FROM information_schema.columns WHERE table_name='$table' AND column_name='id'")->fetch(PDO::FETCH_ASSOC);
    if ($idColInfo && $idColInfo['is_identity'] === 'YES') {
        $hasIdentity = true;
    }

    // OVERRIDING SYSTEM VALUE 需放在 VALUES 之前（PG 语法）
    $overrideClause = $hasIdentity ? ' OVERRIDING SYSTEM VALUE' : '';
    $sql = "INSERT INTO \"$table\" ($colList)$overrideClause VALUES ($placeholders)";
    $stmt = $pg->prepare($sql);

    $res = $sqlite->query("SELECT * FROM \"$table\"");
    $inserted = 0;
    $failed = 0;
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $params = [];
        foreach ($commonCols as $col) {
            $val = $row[$col] ?? null;

            // search_text 自动生成
            if ($table === 'aigc_status' && $col === 'search_text') {
                $val = strtolower(($row['ctx_id'] ?? '') . '|' . ($row['keyword'] ?? '') . '|' . ($row['lang'] ?? '') . '|' . ($row['pubdomain'] ?? '') . '|' . ($row['createAt'] ?? '') . '|' . ($row['publishAt'] ?? ''));
            }

            // json 列：确保是合法 JSON 字符串（PG JSONB 需要）
            $isJsonCol = isset($jsonColumns[$table]) && in_array($col, $jsonColumns[$table]);
            if ($isJsonCol) {
                if ($val === null || $val === '') {
                    $val = '{}'; // 空值给空 JSON 对象
                }
                // 验证是否合法 JSON
                $decoded = json_decode((string)$val, true);
                if ($decoded === null && trim((string)$val) !== '' && trim((string)$val) !== '{}') {
                    // 非 JSON 文本，包裹为 JSON 对象
                    $val = json_encode(['raw' => $val]);
                } else {
                    // 重新编码确保合法
                    $val = json_encode($decoded ?? new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }

            // time 列：空值给 null（PG TIMESTAMPTZ 可接受）
            $isTimeCol = isset($timeColumns[$table]) && in_array($col, $timeColumns[$table]);
            if ($isTimeCol && ($val === null || $val === '')) {
                $val = null;
            }

            $params[':' . $col] = $val;
        }
        try {
            $stmt->execute($params);
            $inserted++;
        } catch (Exception $e) {
            $failed++;
            if ($failed <= 3) {
                echo "\n  FAIL row in $table: " . substr($e->getMessage(), 0, 200) . "\n";
            }
        }
    }

    // 重置 sequence
    if (in_array('id', $pgCols)) {
        try {
            $maxId = (int)$pg->query("SELECT COALESCE(MAX(id), 0) FROM \"$table\"")->fetchColumn();
            if ($maxId > 0) {
                $pg->exec("SELECT setval(pg_get_serial_sequence('$table', 'id'), $maxId)");
            }
        } catch (Exception $e) {}
    }

    echo "inserted=$inserted failed=$failed\n";
    $stats[$table] = ['sqlite' => $liteCount, 'pg' => $inserted, 'failed' => $failed];
}

// 5. 验证
echo "\n=== Verification ===\n";
foreach ($migrateOrder as $table) {
    $pgCount = (int)$pg->query("SELECT COUNT(*) FROM \"$table\"")->fetchColumn();
    $liteCount = $stats[$table]['sqlite'] ?? 0;
    $status = $pgCount === $liteCount ? 'OK' : 'MISMATCH';
    echo sprintf("%-25s SQLite=%-6d PG=%-6d %s\n", $table, $liteCount, $pgCount, $status);
}

// 6. 补 aigc_status search_text 列和索引
try {
    $hasCol = $pg->query("SELECT 1 FROM information_schema.columns WHERE table_name='aigc_status' AND column_name='search_text'")->fetchColumn();
    if (!$hasCol) {
        $pg->exec('ALTER TABLE "aigc_status" ADD COLUMN "search_text" TEXT');
    }
    $pg->exec("UPDATE \"aigc_status\" SET \"search_text\"=lower(\"ctx_id\"||'|'||\"keyword\"||'|'||\"lang\"||'|'||\"pubdomain\"||'|'||\"createAt\"||'|'||\"publishAt\")");
    $pg->exec('CREATE INDEX IF NOT EXISTS "idx_aigc_search" ON "aigc_status" ("search_text")');
    echo "aigc_status search_text populated\n";
} catch (Exception $e) {
    echo "search_text fix: " . $e->getMessage() . "\n";
}

echo "\nMigration complete.\n";
