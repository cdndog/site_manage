<?php

error_reporting(0);

define("LocalPATH", dirname(__FILE__));

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

$db_name = 'sitedata.sqlite';
$table_name = 'siteops';

$site_columns = ['ctx_id', 'git_name', 'status', 'theme_type', 'languages', 'domain', 'sns_id', 'topnav_menus', 'site_title', 'site_subtitle', 'json'];

$savedir = "sitemonitor";
$logFile = 'siteops_setting.txt';
$taskfilePrefix = "tasklist_siteops_setting_";

function check_keyword_in_file($keyword, $file_path) {
    if (empty($keyword)) return true;
    return (file_exists($file_path) && strpos(file_get_contents($file_path), $keyword) !== false);
}

if ($_SERVER["REQUEST_METHOD"] == "GET" && !empty($_GET['t'])) {
    $q = $_GET['t'];
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 0;
    $taskfile = $taskfilePrefix . md5($q) . ".txt";

    // Read and filter log lines
    // $lines = @file($logFile, FILE_IGNORE_NEW_LINES);
    // $lines = queryDBAllColumn($db_name, $table_name, $q);
    // $lines = is_array($lines) ? $lines : [];
    
    if ($q !== "all") {
        // $filteredLines = array_filter($lines, function($line) use ($q) {
        //     return preg_match('/"' . preg_quote($q, '/') . '"/', $line);
        // });
        $lines = queryDBAllColumn($db_name, $table_name, $q);
    } else {
        $SQL = "";
        $lines = queryDB2Array($db_name, $table_name, $SQL);
    }

    $filteredLines = [];

    foreach ($lines as $lineKey => $lineValue) {
        foreach ($site_columns as $column) {
            $rowData .= $lineValue[$column] . '|';
        }
        $filteredLines[] = rtrim($rowData, '|') . PHP_EOL;
    }

    $filteredLines = array_values($filteredLines);

    // NON-LIMITED BRANCH: Return ALL matching "done" entries
    if ($limit == 0 || count($filteredLines) <= $limit) {
        $output = [];
        foreach ($filteredLines as $line) {
            $parts = explode('|', $line);
            if (count($parts) < 2) continue;
            $jsondata = end($parts);
            $siteData = json_decode($jsondata, true);
            if (json_last_error() !== JSON_ERROR_NONE) continue;
            if (isset($siteData['status']) && $siteData['status'] === "done") {
                $output[] = array('id' => $parts[0]) + $siteData;
            }
        }
        $response = $output;
    } 
    // LIMITED BRANCH: Return UP TO $limit "done" entries (cycle-safe)
    else {
        // Load/reset task tracking
        $doneLines = [];
        if (file_exists($taskfile)) {
            $doneLines = @file($taskfile, FILE_IGNORE_NEW_LINES);
            $doneLines = is_array($doneLines) ? array_unique($doneLines) : [];
            if (count($doneLines) >= count($filteredLines)) {
                $doneLines = [];
                @unlink($taskfile);
            }
        }

        // var_dump($filteredLines);
        // var_dump($doneLines);

        $availableLines = array_diff($filteredLines, $doneLines);
        if (empty($availableLines)) {
            $doneLines = [];
            $availableLines = $filteredLines;
        }

        // CRITICAL FIX: Filter AVAILABLE lines to ONLY "done" status entries
        $candidateLines = [];
        foreach ($availableLines as $line) {
            $parts = explode('|', $line);
            if (count($parts) < 2) continue;
            $jsondata = end($parts);
            $siteData = json_decode($jsondata, true);
            if (json_last_error() !== JSON_ERROR_NONE) continue;
            if (isset($siteData['status']) && $siteData['status'] === "done") {
                $candidateLines[] = $line;
            }
            // Non-"done" lines are SKIPPED and NOT marked as processed
        }

        // var_dump($candidateLines);
        // Select randomized subset of valid "done" entries
        shuffle($candidateLines);
        $selectedLines = array_slice($candidateLines, 0, $limit);

        // Build response AND track ONLY served "done" lines
        $output = [];
        $newDoneLines = [];
        foreach ($selectedLines as $line) {
            $parts = explode('|', $line);
            $jsondata = end($parts);
            $siteData = json_decode($jsondata, true); // Already validated above
            $output[] = array('id' => $parts[0]) + $siteData;
            $newDoneLines[] = $line;
        }

        // Persist tracking ONLY for successfully served "done" entries
        $doneLines = array_unique(array_merge($doneLines, $newDoneLines));
        if (!empty($doneLines)) {
            file_put_contents($taskfile, implode("\n", $doneLines));
        }
        $response = $output;
    }

    // Output JSON response
    if (!empty($response)) {
        header('Content-Type: application/json');
        echo json_encode($response);
    }
}
?>