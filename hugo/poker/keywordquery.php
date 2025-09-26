<?php

error_reporting(0);

define("LocalPATH", dirname(__FILE__));

$savedir = "keywordmonitor";
$logFile = 'keyword_monitor_list.txt';


function check_keyword_in_file($keyword, $file_path) {
    // Check if the post ID already exists in the file
    if ( empty($keyword)) return true;
    if (file_exists($file_path) && strpos(file_get_contents($file_path), $keyword) !== false) {
        return true;
    } else {
        return false;
    }
}

if ($_SERVER["REQUEST_METHOD"] == "GET") {
    if (!empty($_GET['t'])) {
        $q = $_GET['t'];

        if ($q == "all") {
            $output = array();
            $lines = file($logFile, FILE_IGNORE_NEW_LINES);
            foreach ($lines as $key => $line) {
                // if (preg_match("/" . preg_quote($q . '|', '/') . "/", $line)) {
                $parts = explode('|', $line);
                $jsondata = end($parts);
                $output[] = array('id' => $parts[0]) + json_decode($jsondata, true); 
                // }
            }
            // Prepare the JSON response
            $response = $output;

        } elseif ( check_keyword_in_file($q.'|', $logFile)  ) {
            $output = array();
            $lines = file($logFile, FILE_IGNORE_NEW_LINES);

            foreach ($lines as $key => $line) {
                if (preg_match("/" . preg_quote($q . '|', '/') . "/", $line)) {
                    $parts = explode('|', $line);
                    $jsondata = end($parts);
                    $output[] = array('id' => $parts[0]) + json_decode($jsondata, true); 
                }
            }
            // Prepare the JSON response
            $response = $output;
        }

        if (!empty($response)) {
            // Set the response headers
            header('Content-Type: application/json');

            // Convert the response array to JSON format and echo it
            echo json_encode($response);
        }
    }
}

