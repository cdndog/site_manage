<?php

error_reporting(0);

define("LocalPATH", dirname(__FILE__));

$savedir = "keywordmonitor";
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
    $lines = @file($logFile, FILE_IGNORE_NEW_LINES);
    $lines = is_array($lines) ? $lines : [];
    
    if ($q !== "all") {
        $filteredLines = array_filter($lines, function($line) use ($q) {
            return preg_match('/"' . preg_quote($q, '/') . '"/', $line);
        });
    } else {
        $filteredLines = $lines;
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