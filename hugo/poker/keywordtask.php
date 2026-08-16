<?php

error_reporting(0);

define("LocalPATH", dirname(__FILE__));

require __DIR__ . '/app/bootstrap.php';

\App\Support\Security::requireApiToken();

use App\Database;

$savedir = "keywordmonitor";
$logFile = 'keyword_monitor_list.txt';

// 将 keywordmonitorlist 行拼成与 keyword_monitor_list.txt 完全一致的行格式
if (!function_exists('build_keyword_line')) {
    function build_keyword_line($row) {
        return implode('|', [
            (string)$row['ctx_id'],
            (string)$row['keyword'],
            (string)$row['status'],
            (string)$row['git_name'],
            (string)$row['pubdir'],
            (string)$row['lang'],
            (string)$row['json'],
        ]);
    }
}

if ($_SERVER["REQUEST_METHOD"] == "GET") {
    if (!empty($_GET['t'])) {
        $q = $_GET['t'];
        $time = date("Ymd");

        if ($q == "all") {
            $rows = Database::fetchAll(
                'SELECT * FROM "keywordmonitorlist"'
                . ' WHERE (json_extract("json", \'$.lasttask\') IS NULL OR json_extract("json", \'$.lasttask\') != :today)'
                . ' ORDER BY RANDOM() LIMIT 1',
                ['today' => $time]
            );
        }

        $response = array();
        foreach ($rows as $row) {
            $response[] = build_keyword_line($row);
        }

        if (!empty($response)) {
            header('Content-Type: text/plain');
            echo implode(PHP_EOL, $response) . PHP_EOL;

            foreach ($response as $line) {
                $responseArray = explode("|", $line);
                $ctx_id = $responseArray[0];
                $json = end($responseArray);

                $keywordData = json_decode($json, true);
                if (!is_array($keywordData)) {
                    $keywordData = array();
                }

                if (empty($keywordData["keyword"])) {
                    continue;
                }
                if (empty($keywordData['geo']) && !empty($keywordData['lang'])) {
                    switch ($keywordData['lang']) {
                        case "en":
                            $geo = "US";
                            break;
                        case "ja":
                            $geo = "JP";
                            break;
                        case "zh":
                            $geo = "CN";
                            break;
                        case "es":
                            $geo = "MX";
                            break;
                        case "ko":
                            $geo = "KR";
                            break;
                        case "ar":
                            $geo = "AR";
                            break;
                        case "ru":
                            $geo = "RU";
                            break;
                        case "fr":
                            $geo = "FR";
                            break;
                        case "pt":
                            $geo = "BR";
                            break;
                        case "bn":
                            $geo = "BD";
                            break;
                        case "ur":
                            $geo = "PK";
                            break;
                        case "de":
                            $geo = "DE";
                            break;
                        case "sv":
                            $geo = "SE";
                            break;
                        case "vi":
                            $geo = "VN";
                            break;
                        case "tr":
                            $geo = "TR";
                            break;
                        default:
                            $geo = "US";  // Default case for unrecognized languages
                            break;
                    }

                    $keywordData['geo'] = $geo;
                }

                if (empty($keywordData['lasttask'])) {
                    $keywordData['lasttask'] = date("Ymd");
                }

                $keyword = $keywordData['keyword'] ?? "";
                $git_name = $keywordData['git_name'] ?? "";
                $lang = $keywordData['lang'] ?? "";
                $geo = $keywordData['geo'] ?? "";
                $lasttask = $keywordData['lasttask'] ?? date("Ymd");
                $status = $keywordData['status'] ?? "";
                $pubdir = $keywordData['pubdir'] ?? "";

                if (!empty($keyword)) {
                    if (empty($status)) {
                        // 防止 json 缺少 status 时把行状态误改为默认值
                        $existingRow = Database::fetchOne('SELECT "status" FROM "keywordmonitorlist" WHERE "ctx_id" = :ctx_id', ['ctx_id' => $ctx_id]);
                        $status = (!empty($existingRow['status'])) ? (string)$existingRow['status'] : "enable";
                    }

                    // 本地直写：标记 lasttask / status（等价于远端 keywordops.php 回写，
                    // 但避免网络往返、鉴权依赖与静默失败）
                    $writeJson = $keywordData;
                    $writeJson['keyword'] = $keyword;
                    $writeJson['git_name'] = $git_name;
                    $writeJson['pubdir'] = $pubdir;
                    $writeJson['status'] = $status;
                    $writeJson['lang'] = $lang;
                    $writeJson['geo'] = $geo;
                    $writeJson['lasttask'] = $lasttask;

                    Database::execute(
                        'UPDATE "keywordmonitorlist" SET "status" = :status, "lasttask" = :lasttask, "json" = :json, "time" = datetime(\'now\', \'localtime\') WHERE "ctx_id" = :ctx_id',
                        ['status' => $status, 'lasttask' => $lasttask, 'json' => json_encode($writeJson), 'ctx_id' => $ctx_id]
                    );

                    \App\Services\KeywordService::export();
                }
            }
        }
    }
}