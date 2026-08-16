<?php

error_reporting(0);

define("LocalPATH", dirname(__FILE__));

require __DIR__ . '/app/bootstrap.php';

\App\Support\Security::requireApiToken();

use App\Database;

$savedir = "keywordmonitor";
$logFile = 'keyword_monitor_list.txt';

if ($_SERVER["REQUEST_METHOD"] == "GET") {
    if (!empty($_GET['t'])) {
        $q = $_GET['t'];

        if ($q == "all") {
            $rows = Database::fetchAll(
                'SELECT "ctx_id", "json" FROM "keywordmonitorlist" ORDER BY "id"'
            );
        } else {
            $rows = Database::fetchAll(
                'SELECT "ctx_id", "json" FROM "keywordmonitorlist" WHERE "ctx_id" = :q OR "keyword" = :q',
                ['q' => $q]
            );
        }

        $response = array();
        foreach ($rows as $row) {
            $decoded = json_decode($row['json'], true);
            if (!is_array($decoded)) {
                $decoded = array();
            }
            $response[] = array('id' => $row['ctx_id']) + $decoded;
        }

        if (!empty($response)) {
            header('Content-Type: application/json');
            echo json_encode($response);
        }
    }
}