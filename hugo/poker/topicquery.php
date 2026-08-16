<?php

error_reporting(0);

define("LocalPATH", dirname(__FILE__));

require __DIR__ . '/app/bootstrap.php';

\App\Support\Security::requireApiToken();

use App\Database;

$savedir = "topicmonitor";
$logFile = 'topic_monitor_list.txt';

if ($_SERVER["REQUEST_METHOD"] == "GET") {
    if (!empty($_GET['t'])) {
        $q = $_GET['t'];

        if ($q == "all") {
            $rows = Database::fetchAll('SELECT "ctx_id", "json" FROM "sitetopic" ORDER BY "id"');
        } else {
            $rows = Database::fetchAll(
                'SELECT "ctx_id", "json" FROM "sitetopic" WHERE "keyword" = :keyword ORDER BY "id"',
                ['keyword' => $q]
            );
        }

        $response = array();
        foreach ($rows as $row) {
            $jsondata = json_decode((string)$row['json'], true);
            if (!is_array($jsondata)) {
                $jsondata = array();
            }
            $response[] = array('id' => (string)$row['ctx_id']) + $jsondata;
        }

        if (!empty($response)) {
            header('Content-Type: application/json');
            echo json_encode($response);
        }
    }
}