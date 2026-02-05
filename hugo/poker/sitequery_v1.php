<?php

error_reporting(0);

define("LocalPATH", dirname(__FILE__));

$savedir = "keywordmonitor";
$logFile = 'siteops_setting.txt';
$taskfilePrefix = "tasklist_siteops_setting_";

function check_keyword_in_file($keyword, $file_path) {
    if (empty($keyword)) return true;
    if (file_exists($file_path) && strpos(file_get_contents($file_path), $keyword) !== false) {
        return true;
    } else {
        return false;
    }
}

if ($_SERVER["REQUEST_METHOD"] == "GET") {
    if (!empty($_GET['t'])) {
        $q = $_GET['t'];
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int) $_GET['limit'] : 0;

        $taskfile = $taskfilePrefix . md5($q) . ".txt";

        // Read all lines from the log file
        $lines = file($logFile, FILE_IGNORE_NEW_LINES);
        $totalLines = count($lines);

        // Filter lines based on $q
        if ($q !== "all") {
            $filteredLines = array_filter($lines, function ($line) use ($q) {
                return preg_match("/" . preg_quote('"' . $q . '"', '/') . "/", $line);
            });
        } else {
            // For "all", do not filter lines
            $filteredLines = $lines;
        }
        $filteredLines = array_values($filteredLines); // reindex

        if ($limit == 0 || count($filteredLines) <= $limit) {
            // No limit or not enough lines: output all filtered lines
            $output = [];
            foreach ($filteredLines as $line) {
                $parts = explode('|', $line);
                $jsondata = end($parts);
                $output[] = array('id' => $parts[0]) + json_decode($jsondata, true);
            }
            $response = $output;
        } else {
            // With limit: loop and track processed lines with taskfile
            if (file_exists($taskfile)) {
                $doneLines = file($taskfile, FILE_IGNORE_NEW_LINES);
                $doneLines = array_unique($doneLines);
                // Reset if all filtered lines processed
                if (count($doneLines) >= count($filteredLines)) {
                    $doneLines = [];
                    unlink($taskfile);
                }
            } else {
                $doneLines = [];
            }

            // Get available lines excluding doneLines
            $availableLines = array_diff($filteredLines, $doneLines);

            // If no available lines, reset doneLines for new cycle
            if (empty($availableLines)) {
                $doneLines = [];
                $availableLines = $filteredLines;
            }

            shuffle($availableLines);
            $availableLines = array_slice($availableLines, 0, $limit);

            $output = [];
            $doneline = [];

            foreach ($availableLines as $line) {
                $parts = explode('|', $line);
                $jsondata = end($parts);
                $output[] = array('id' => $parts[0]) + json_decode($jsondata, true);
                $doneline[] = $line;
            }

            // Update doneLines and save to taskfile
            $doneLines = array_unique(array_merge($doneLines, $doneline));
            file_put_contents($taskfile, implode("\n", $doneLines));
            $response = $output;
        }

        if (!empty($response)) {
            header('Content-Type: application/json');
            echo json_encode($response);
        }
    }
}
