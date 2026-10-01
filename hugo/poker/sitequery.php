<?php

// 错误写入日志但不输出，避免污染 JSON 响应（原先 error_reporting(0) 会让故障静默成空白 body）
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

defined('LocalPATH') || define("LocalPATH", dirname(__FILE__));

require __DIR__ . '/app/bootstrap.php';
\App\Support\Security::requireApiToken();

// ---------------------------------------------------------------------------
// 以下为历史遗留的 SQLite 专用辅助函数（均为死代码，主 GET 流程不再调用）。
// 保留供参考，但注意：它们直接 new SQLite3($db_name)，
// 在 PostgreSQL 部署下 $db_name 会是 DSN 字符串而非文件路径，调用即出错。
// 新代码请统一使用 \App\Database::fetchAll()/execute()。
// ---------------------------------------------------------------------------
if (!function_exists('renewDBtable')) {
    function renewDBtable($db_name, $table_name, $sitedatas, $query_column, $renew_columns) {

        $db = new SQLite3($db_name, SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);

        // Errors are emitted as warnings by default, enable proper error handling.
        $db->enableExceptions(true);

        foreach ($sitedatas as $site) {

            // $git_name = $site['git_name'];

            // $statement = $db->prepare('SELECT * FROM "'.$table_name.'" WHERE "'.$query_column.'" = :query_value');
            // $statement->bindValue(':query_value', $site[$query_column]);
            // $result = $statement->execute()->fetchArray(SQLITE3_ASSOC);

            // Handle single or multiple query columns (e.g., "ctx_id" or "key1,key2")
            $query_cols = array_map('trim', explode(',', $query_column));
            $where_conditions = [];
            $param_placeholders = [];

            foreach ($query_cols as $col) {
                $where_conditions[] = '"' . $col . '" = :' . $col;
                $param_placeholders[] = ':' . $col;
            }

            $where_clause = implode(' AND ', $where_conditions);
            $select_sql = 'SELECT * FROM "' . $table_name . '" WHERE ' . $where_clause;

            $statement = $db->prepare($select_sql);
            foreach ($query_cols as $col) {
                $statement->bindValue(':' . $col, $site[$col]);
            }
            $result = $statement->execute()->fetchArray(SQLITE3_ASSOC);

            if (isset($result[$query_cols[0]])) {
                // if (isset($result[$query_column]))  {
                // echo "{$site[$query_column]} exist {$result['ctx_id']}, updating".PHP_EOL;
                $SQL = 'UPDATE "'.$table_name.'" SET ';
                foreach ($renew_columns as $column) {
                    $SQL .= '"'.$column.'" = :'.$column.', ';
                }
                $SQL = rtrim($SQL, ', ');
                $SQL .= ' WHERE '. $where_clause;
                // echo $SQL;
                // $SQL = 'UPDATE "'.$table_name.'" SET "git_name" = :git_name, "domain" = :domain, "site_title" = :site_title, "site_subtitle" = :site_subtitle, "site_logo" = :site_logo, "languages" = :languages, "sns_id" = :sns_id, "topnav_menus" = :topnav_menus, "keyword" = :keyword, "theme_name" = :theme_name, "theme_type" = :theme_type, "sitedir" = :sitedir, "status" = :status, "json" = :json, "time" = :time WHERE "ctx_id" = :ctx_id';
            
                $statement = $db->prepare($SQL);
                foreach ($renew_columns as $column) {
                    if ($column == 'ctx_id') {
                        $ctx_id = str_replace('.', '', uniqid(time(), true));
                        $site['ctx_id'] = !empty($result['ctx_id']) ? $result['ctx_id'] : $site['ctx_id'];
                        $statement->bindValue(':'.$column, isset($site[$column]) ? $site[$column] : $ctx_id );
                    } else {
                        $statement->bindValue(':'.$column, isset($site[$column]) ? $site[$column] : "");
                    }
                }

                $statement->bindValue(':time', date("Y-m-d H:i:s"));
                $statement->execute(); // you can reuse the statement with different values
            } else {
                $ctx_id = !empty($site['ctx_id']) ? $site['ctx_id'] : str_replace('.','',uniqid(time(), true));
                // echo "{$site[$query_column]} not found, inserting {$ctx_id}".PHP_EOL;
                $insertColumns = '';
                $insertValue = '';
                $SQL = 'INSERT INTO "'.$table_name.'" ';
                foreach ($renew_columns as $column) {
                    $insertColumns .= '"'.$column.'", ';
                    $insertValue .= ':'.$column.', ';
                }
                $insertColumns = ' ('.rtrim($insertColumns, ", ").') ';
                $insertValue = ' ('.rtrim($insertValue, ", ").') ';
                $SQL .= $insertColumns . ' VALUES ' . $insertValue;
                // echo $SQL.PHP_EOL;
                // $SQL = 'INSERT INTO "'.$table_name.'" ("ctx_id", "git_name", "domain", "site_title", "site_subtitle", "site_logo", "time", "languages", "sns_id", "topnav_menus", "keyword", "theme_name", "theme_type", "sitedir", "status", "json")
                // VALUES (:ctx_id, :git_name, :domain, :site_title, :site_subtitle, :site_logo, :time, :languages, :sns_id, :topnav_menus, :keyword, :theme_name, :theme_type, :sitedir, :status, :json)';
                // echo $SQL.PHP_EOL;
                $statement = $db->prepare($SQL);
                foreach ($renew_columns as $column) {
                    if ($column == 'ctx_id') {
                        $ctx_id = str_replace('.', '', uniqid(time(), true));
                        $statement->bindValue(':'.$column, isset($site[$column]) ? $site[$column] : $ctx_id );
                    } else {
                        $statement->bindValue(':'.$column, isset($site[$column]) ? $site[$column] : "");
                    }
                }
                // $ctx_id = str_replace('.','',uniqid(time(), true));
                // $statement->bindValue(':ctx_id', isset($site['ctx_id']) ? $site['ctx_id'] : $ctx_id );
                // $statement->bindValue(':git_name', isset($site['git_name']) ? $site['git_name'] : "");
                // $statement->bindValue(':domain', isset($site['domain']) ? $site['domain'] : "");
                // $statement->bindValue(':site_title', isset($site['site_title']) ? $site['site_title'] : "");
                // $statement->bindValue(':site_subtitle', isset($site['site_subtitle']) ? $site['site_subtitle'] : "");
                // $statement->bindValue(':site_logo', isset($site['site_logo']) ? $site['site_logo'] : "");
                // $statement->bindValue(':languages', isset($site['languages']) ? $site['languages'] : "");
                // $statement->bindValue(':sns_id', isset($site['sns_id']) ? $site['sns_id'] : "");
                // $statement->bindValue(':topnav_menus', isset($site['topnav_menus']) ? $site['topnav_menus'] : "");
                // $statement->bindValue(':keyword', isset($site['keyword']) ? $site['keyword'] : "");
                // $statement->bindValue(':theme_name', isset($site['theme_name']) ? $site['theme_name'] : "");
                // $statement->bindValue(':theme_type', isset($site['theme_type']) ? $site['theme_type'] : "");
                // $statement->bindValue(':sitedir', isset($site['sitedir']) ? $site['sitedir'] : "" );
                // $statement->bindValue(':status', isset($site['status']) ? $site['status'] : "");
                // $statement->bindValue(':json', isset($site['json']) ? $site['json'] : "") ;
                $statement->bindValue(':time', date("Y-m-d H:i:s"));
                $statement->execute(); // you can reuse the statement with different values
            } 

        }
        $db->close();
    }
}

if (!function_exists('queryDB2text')) {
    function queryDB2text($db_name, $table_name, $output_name, $columns) {

        $db = new SQLite3($db_name, SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);

        // Errors are emitted as warnings by default, enable proper error handling.
        $db->enableExceptions(true);


        $statement = $db->prepare('SELECT * FROM "'.$table_name.'" WHERE "*" = "*"');

        $result = $statement->execute();

        // Fetch all rows from the result set
        $rows = array();
        $output_text = '';
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
            $rowData = '';
            foreach ($columns as $column) {
                $rowData .= $row[$column] . '|';
            }
            $output_text .= rtrim($rowData, '|') . PHP_EOL;
        }

        file_put_contents($output_name, $output_text);

        $db->close();
    }
}

if (!function_exists('queryDB2Array')) {
    function queryDB2Array($db_name, $table_name, $SQL) {

        $db = new SQLite3($db_name, SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);

        // Errors are emitted as warnings by default, enable proper error handling.
        $db->enableExceptions(true);

        if (empty($SQL)) {
            $SQL = 'SELECT * FROM "'.$table_name.'" WHERE "*" = "*"';
        } 
        $statement = $db->prepare($SQL);

        $result = $statement->execute();

        // Fetch all rows from the result set
        $rows = array();
        $output_text = '';
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }

        $db->close();

        return $rows;
    }
}

if (!function_exists('queryDBAllColumn')) {
    function queryDBAllColumn($db_name, $table_name, $search_value) {
        // Open DB
        $db = new SQLite3($db_name);

        // 1) Get all column names
        $cols   = [];
        $result = $db->query("PRAGMA table_info($table_name)");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $cols[] = $row['name'];
        }

        if (empty($cols)) {
            $db->close();
            return [];
        }

        // 2) Build WHERE: col1 LIKE :p0 OR col2 LIKE :p1 ...
        $whereParts = [];
        foreach ($cols as $i => $col) {
            $whereParts[] = "$col LIKE :p$i";
        }
        $where = implode(' OR ', $whereParts);

        // 3) Prepare statement
        $sql  = "SELECT * FROM $table_name WHERE $where";
        $stmt = $db->prepare($sql);

        // 4) Bind values
        $pattern = '%' . $search_value . '%';
        foreach ($cols as $i => $col) {
            $stmt->bindValue(":p$i", $pattern, SQLITE3_TEXT);
        }

        // 5) Execute & collect rows
        $res  = $stmt->execute();
        $rows = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }

        $res->finalize();
        $db->close();

        return $rows;
    }
}

if (!function_exists('getDomainCountMap')) {
    function getDomainCountMap($db_name) {
        // Get count of domain from sitetopic table, grouped by domain
        $db = new SQLite3($db_name);
        $sql = "SELECT domain, COUNT(*) as cnt FROM sitetopic GROUP BY domain";
        $result = $db->query($sql);
    
        $domainCountMap = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $domainCountMap[$row['domain']] = (int)$row['cnt'];
        }
    
        $db->close();
        return $domainCountMap;
    }
}

// function initDBlite($db_name, $table_name, $SQL) {
//     $db = new SQLite3($db_name, SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);

//     // Errors are emitted as warnings by default, enable proper error handling.
//     $db->enableExceptions(true);

//     // Create a table.

//     $db->query($SQL);

//     $db->close();
// }


// $rows = queryDBAllColumn('sitedata.sqlite', 'siteops', '172588621366deef05a8dd3542715744');
// foreach ($rows as $r) {
//     print_r($r);
//     echo "<br>";
// }

// 统一走 Config 解析（支持 APP_DB_FILE / APP_DB_DSN），不再硬编码 sitedata.sqlite
$db_name = \App\Config::dbFile();
$table_name = 'siteops';

$site_columns = ['ctx_id', 'git_name', 'status', 'theme_type', 'languages', 'domain', 'sns_id', 'topnav_menus', 'site_title', 'site_subtitle', 'json'];

$savedir = "sitemonitor";
$logFile = 'siteops_setting.txt';

if (!function_exists('check_keyword_in_file')) {
    function check_keyword_in_file($keyword, $file_path) {
        if (empty($keyword)) return true;
        return (file_exists($file_path) && strpos(file_get_contents($file_path), $keyword) !== false);
    }
}

// 通过 Database 层读取 siteops 列名（SQLite 走 PRAGMA；PG 分支在调用处用 information_schema）
if (!function_exists('siteopsColumns')) {
    function siteopsColumns() {
        if (\App\Database::isPg()) {
            return []; // PG 无 PRAGMA，调用方须改用 information_schema 分支
        }
        $cols = [];
        $res = \App\Database::connection()->query('PRAGMA table_info("siteops")');
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            if (isset($row['name'])) $cols[] = $row['name'];
        }
        return $cols;
    }
}

if ($_SERVER["REQUEST_METHOD"] == "GET") {
    // 参数校验放在外层守卫之前，避免缺失 t 时静默无输出
    $q = trim((string)($_GET['t'] ?? ''));
    if ($q === '') {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 't parameter required']);
        if (!defined('SOPS_TESTING')) { exit; }
        return;
    }
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 0;

    // 性能优化：将原 PHP 侧的筛选/排序下推到 SQL，去掉全表 SELECT * 与字符串拼接/explode 往返；
    // 输出字段（id + json 展开）与分支语义保持不变。
    // 数据源统一走 Database 层，兼容 SQLite / PostgreSQL（不再硬编码 new SQLite3('sitedata.sqlite')）。
    try {
        $isPg = \App\Database::isPg();

        // 1) 获取列名：PG 用 information_schema，SQLite 用 PRAGMA
        $cols = [];
        if ($isPg) {
            $colRows = \App\Database::fetchAll(
                'SELECT "column_name" FROM "information_schema"."columns" WHERE "table_name" = :t ORDER BY "ordinal_position"',
                [':t' => $table_name]
            );
            foreach ($colRows as $cr) { $cols[] = $cr['column_name']; }
        } else {
            $cols = siteopsColumns();
        }

        // 2) 构造 LIKE / ILIKE WHERE 条件（与原 queryDBAllColumn 一致：对所有列模糊匹配）
        $likeOp = $isPg ? 'ILIKE' : 'LIKE';
        $likeWhere = '';
        $likeParams = [];
        if ($q !== "all" && !empty($cols)) {
            $whereParts = [];
            foreach ($cols as $i => $col) {
                // PG 必须统一 ::text：json 列是 JSONB（无 LIKE 操作符），
                // id 是 BIGINT、time 是 TIMESTAMPTZ（同样无 ILIKE 操作符），
                // 不加 ::text 会抛 42883 operator does not exist
                $colExpr = $isPg ? '"' . $col . '"::text' : '"' . $col . '"';
                $whereParts[] = $colExpr . ' ' . $likeOp . ' :p' . $i;
                $likeParams[':p' . $i] = '%' . $q . '%';
            }
            $likeWhere = implode(' OR ', $whereParts);
        }

        // 与原逻辑一致：先统计全部匹配行数（含非 done），决定走全量还是 limit 分支
        // 优化：limit==0（默认调用）必然走全量分支，跳过 COUNT 避免二次全表扫描
        if ($limit > 0) {
            $countSql = 'SELECT COUNT(*) AS n FROM "' . $table_name . '"' . ($likeWhere !== '' ? ' WHERE ' . $likeWhere : '');
            $countRow = \App\Database::fetchOne($countSql, $likeParams);
            $total = (int)($countRow['n'] ?? 0);
        } else {
            $total = 0;
        }

        // PG 没有 json_valid()，用 jsonb_typeof 代替
        $doneCond = $isPg
            ? 'jsonb_typeof("json") = \'object\' AND ("json"->>\'status\') = \'done\''
            : 'json_valid("json") AND json_extract("json", \'$.status\') = \'done\'';

        // NON-LIMITED BRANCH: Return ALL matching "done" entries（行序与原 SELECT * 全表扫描一致）
        if ($limit == 0 || $total <= $limit) {
            $sql = 'SELECT "ctx_id", "json" FROM "' . $table_name . '"'
                 . ($likeWhere !== '' ? ' WHERE (' . $likeWhere . ') AND ' : ' WHERE ') . $doneCond;
            $rows = \App\Database::fetchAll($sql, $likeParams);

            $output = [];
            foreach ($rows as $row) {
                $siteData = json_decode((string)$row['json'], true);
                if (!is_array($siteData)) continue;
                $output[] = array('id' => $row['ctx_id']) + $siteData;
            }
            $response = $output;
        }
        // LIMITED BRANCH: Return UP TO $limit "done" entries, prioritize domains with smallest count in sitetopic
        else {
            $aliasWhere = $likeWhere !== '' ? preg_replace('/"(\w+)"(::text)? ' . preg_quote($likeOp) . '/', 's."$1"$2 ' . $likeOp, $likeWhere) : ($isPg ? 'TRUE' : '1');
            $sql = 'SELECT s."ctx_id", s."json" FROM "' . $table_name . '" s'
                 . ' LEFT JOIN (SELECT "domain", COUNT(*) AS "cnt" FROM "sitetopic" GROUP BY "domain") c ON c."domain" = s."domain"'
                 . ' WHERE ' . $aliasWhere . ' AND ' . $doneCond
                 . ' ORDER BY COALESCE(c."cnt", 0) ASC, RANDOM()'
                 . ' LIMIT ' . (int)$limit;
            $rows = \App\Database::fetchAll($sql, $likeParams);

            $output = [];
            foreach ($rows as $row) {
                $siteData = json_decode((string)$row['json'], true);
                if (!is_array($siteData)) continue;
                $output[] = array('id' => $row['ctx_id']) + $siteData;
            }
            $response = $output;
        }
    } catch (\Throwable $e) {
        error_log('sitequery DB error: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'query failed']);
        if (!defined('SOPS_TESTING')) { exit; }
        return;
    }

    // Output JSON response（格式与原逻辑一致）；无匹配时返回 200 + []，而非空白 body
    header('Content-Type: application/json');
    echo json_encode($response);
}
?>