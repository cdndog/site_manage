<?php
if (!file_exists('global_config.php')) {
    die('file global_config.php not found.');
}

$config = include 'global_config.php';

define("LocalPATH", dirname(__FILE__));

function renewDBtable($db_name, $table_name, $sitedatas, $query_column, $renew_columns) {

    $db = new SQLite3($db_name, SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);

    // Enable exceptions for errors
    $db->enableExceptions(true);

    // Split query_column by commas to support multiple columns
    $queryColumns = array_map('trim', explode(',', $query_column));

    foreach ($sitedatas as $site) {

        // Build WHERE clause for SELECT with multiple columns
        $whereConditions = [];
        foreach ($queryColumns as $index => $col) {
            $whereConditions[] = '"' . $col . '" = :query_value_' . $index;
        }
        $whereClause = implode(' AND ', $whereConditions);

        // Prepare and execute SELECT to check if record exists
        $selectSQL = 'SELECT * FROM "' . $table_name . '" WHERE ' . $whereClause;
        $statement = $db->prepare($selectSQL);

        foreach ($queryColumns as $index => $col) {
            if (!isset($site[$col])) {
                throw new Exception("Missing query column value for '{$col}'");
            }
            $statement->bindValue(':query_value_' . $index, $site[$col]);
        }

        $result = $statement->execute()->fetchArray(SQLITE3_ASSOC);

        if ($result) {
            // Record exists, run UPDATE
            $updateSQL = 'UPDATE "' . $table_name . '" SET ';
            foreach ($renew_columns as $column) {
                if (in_array($column, $queryColumns)) {
                    continue; // skip updating columns used in WHERE clause
                }
                $updateSQL .= '"' . $column . '" = :' . $column . ', ';
            }
            $updateSQL = rtrim($updateSQL, ', ');
            $updateSQL .= ' WHERE ' . $whereClause;

            $updateStmt = $db->prepare($updateSQL);

            // Bind all renew columns except query keys
            foreach ($renew_columns as $column) {
                if (in_array($column, $queryColumns)) {
                    continue;
                }
                if ($column == 'ctx_id') {
                    $ctx_id = str_replace('.', '', uniqid(time(), true));
                    $site['ctx_id'] = !empty($result['ctx_id']) ? $result['ctx_id'] : $site['ctx_id'];
                    $updateStmt->bindValue(':' . $column, isset($site[$column]) ? $site[$column] : $ctx_id);
                } else {
                    $updateStmt->bindValue(':' . $column, isset($site[$column]) ? $site[$column] : "");
                }
            }

            // Bind query columns in WHERE clause
            foreach ($queryColumns as $index => $col) {
                $updateStmt->bindValue(':query_value_' . $index, $site[$col]);
            }
            $updateStmt->bindValue(':time', date("Y-m-d H:i:s"));
            $updateStmt->execute();

        } else {
            // Record does not exist, INSERT new record
            $ctx_id = !empty($site['ctx_id']) ? $site['ctx_id'] : str_replace('.', '', uniqid(time(), true));

            $insertColumns = '';
            $insertValues = '';
            foreach ($renew_columns as $column) {
                $insertColumns .= '"' . $column . '", ';
                $insertValues .= ':' . $column . ', ';
            }

            $insertColumns = '(' . rtrim($insertColumns, ', ') . ')';
            $insertValues = '(' . rtrim($insertValues, ', ') . ')';

            $insertSQL = 'INSERT INTO "' . $table_name . '" ' . $insertColumns . ' VALUES ' . $insertValues;

            $insertStmt = $db->prepare($insertSQL);

            foreach ($renew_columns as $column) {
                if ($column == 'ctx_id') {
                    $insertStmt->bindValue(':' . $column, isset($site[$column]) ? $site[$column] : $ctx_id);
                } else {
                    $insertStmt->bindValue(':' . $column, isset($site[$column]) ? $site[$column] : "");
                }
            }
            $insertStmt->bindValue(':time', date("Y-m-d H:i:s"));
            $insertStmt->execute();
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

function initDBlite($db_name, $table_name, $SQL) {
    $db = new SQLite3($db_name, SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);

    // Errors are emitted as warnings by default, enable proper error handling.
    $db->enableExceptions(true);

    // Create a table.

    $db->query($SQL);

    $db->close();
}

function check_keyword_in_file($keyword, $file_path) {
    // Check if the post ID already exists in the file
    if ( empty($keyword)) return true;
    if (file_exists($file_path) && strpos(file_get_contents($file_path), $keyword) !== false) {
        return true;
    } else {
        return false;
    }
}


$db_name = 'sitedata.sqlite';
$table_name = 'sitetopic';
$output_name = 'topic_monitor_list.txt';
$keyword_columns = ['ctx_id', 'git_name', 'domain', 'keyword', 'pubdir', 'status', 'lang', 'geo', 'json'];
$query_column = 'git_name,keyword,pubdir,domain';


if ( PHP_SAPI === 'cli' ) {
    parse_str(implode('&', array_slice($argv, 1)), $parameters);
    // if ( !isset($parameters['video']) || !isset($parameters['cookie'])) {
    if ( !isset($parameters['import_file']) ) {
        echo 'error :' . PHP_EOL;
        echo 'usage : '.$argv[0].' import_file=/path/to/import_file] ' . PHP_EOL;
        die('import_file missed.'. PHP_EOL);
    }
    $import_file = !empty($parameters['import_file']) ? trim($parameters['import_file']) : '';
    // $post_pubdir = !empty($parameters['pubdir']) ? $parameters['pubdir'] : '';
} else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $parameters = $_POST;
        $import_file = !empty($parameters['import_file']) ? $parameters['import_file'] : '';
        // $post_pubdir = !empty($parameters['pubdir']) ? $parameters['pubdir'] : '';
        
    } else {
        $parameters = array();
    }
}

if ( ! file_exists($import_file) ) {
    echo "  Not Found {$import_file}, exit...".PHP_EOL;
    die();
}

// $import_file = "table_faq_demo.txt";
$updated_file = "published_faq_topic_list.txt";

if (!file_exists($import_file)) {
    die("  Import file does not exist.");
}

if (!file_exists($updated_file)) {
    // Create the updated file if missing to avoid errors
    file_put_contents($updated_file, '');
}

$lines = file($import_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$updatedLines = file($updated_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

$needUpdateLines = array_diff($lines, $updatedLines);

$importDatas = array();

foreach ($needUpdateLines as $singleLine) {
    $parts = explode('|', $singleLine);

    if (count($parts) !== 7) {
        // Skip or handle incorrect line format
        continue;
    }

    list($git_name, $domain, $keyword, $pubdir, $status, $lang, $geo) = $parts;

    $content_array = [
        'git_name' => $git_name,
        'domain'   => $domain,
        'keyword'  => $keyword,
        'pubdir'   => $pubdir,
        'status'   => $status,
        'lang'     => $lang,
        'geo'      => $geo,
    ];

    // Encode the content array to JSON without self reference
    $content_array['json'] = json_encode($content_array);

    $importDatas[] = $content_array;

    // Append line with newline to updated file
    file_put_contents($updated_file, $singleLine . PHP_EOL, FILE_APPEND);
}

if (is_array($importDatas) && !empty($importDatas)) {
    echo "  Importing -> ". count($importDatas) .PHP_EOL;
    $query_column = "keyword,git_name,domain,pubdir";
    $renew_columns = ['ctx_id', 'keyword', 'status', 'git_name', 'domain', 'pubdir', 'lang', 'geo', 'lasttask', 'json'];
    renewDBtable($db_name, $table_name, $importDatas, $query_column, $renew_columns);
    $keyword_columns = ['ctx_id', 'git_name', 'domain', 'keyword', 'pubdir', 'status', 'lang', 'geo', 'json'];
    queryDB2text($db_name, $table_name, $output_name, $keyword_columns);
    echo "  Done Importing".PHP_EOL;
}

?>