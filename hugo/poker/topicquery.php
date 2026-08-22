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
            // t 可为任意列的值：ctx_id / keyword / git_name / domain / pubdir / status / lang / geo / lasttask / json
            $rows = Database::fetchAll(
                'SELECT "ctx_id", "json" FROM "sitetopic" WHERE "ctx_id" = :q OR "keyword" = :q OR "git_name" = :q OR "domain" = :q OR "pubdir" = :q OR "status" = :q OR "lang" = :q OR "geo" = :q OR "lasttask" = :q OR "json" LIKE :like ORDER BY "id"',
                ['q' => $q, 'like' => '%' . $q . '%']
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