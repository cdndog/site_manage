<?php

error_reporting(0);

define("LocalPATH", dirname(__FILE__));
// 
// ; siteops_config.ini
// [server]
// url = "http://43.153.41.108"
// username = "db_user"
// password = "db_password"
// dbname = "database_name"

// [settings]
// display_errors = true

// if (file_exists('siteops_config.ini')) {
//     $config = parse_ini_file('siteops_config.ini', true);
//     $remoteServerURL = $config['server']['url'];
// }

if (!file_exists('global_config.php')) {
    die('file global_config.php not found.');
}

$config = include 'global_config.php';

if (!empty($config)) {
    $remoteServerURL = $config['base']['seoServerURL'];
}

if (empty($remoteServerURL)) {
    $remoteServerURL = "https://wptg.wptdata.com";
}

$savedir = "topicmonitor";
$logFile = 'topic_monitor_list.txt';

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
        $time = date("Ymd");

        if ($q == "all") {
            $output = array();
            $lines = file($logFile, FILE_IGNORE_NEW_LINES);
            // rsort($lines);
            shuffle($lines);
            foreach ($lines as $key => $line) {
                // if (preg_match("/" . preg_quote($q . '|', '/') . "/", $line)) {
                $parts = explode('|', $line);
                $jsondata = array_pop($parts);
                $alldata = json_decode($jsondata, true);
                if ( $alldata['lasttask'] !== $time && !empty($parts[0]) && $alldata['status'] === "enable" ) {
                    $output[] = implode('|', $parts).'|'.json_encode($alldata);
                    $alldata['lasttask'] = $time;
                    // echo json_encode($alldata).PHP_EOL;
                    $lines[$key] = implode('|', $parts).'|'.json_encode($alldata);
                    break;
                }

            }

            // Prepare the JSON response
            $response = $output;

        } elseif ( check_keyword_in_file('"' . $q . '"', $logFile)  ) {
            $output = array();
            $lines = file($logFile, FILE_IGNORE_NEW_LINES);
            shuffle($lines);
            foreach ($lines as $key => $line) {
                if (preg_match("/" . preg_quote('"'. $q .'"', '/') . "/", $line)) {
                    $parts = explode('|', $line);
                    $jsondata = array_pop($parts);
                    $alldata = json_decode($jsondata, true);
                    if ( $alldata['lasttask'] !== $time && !empty($parts[0]) && $alldata['status'] === "enable" ) {
                        $output[] = implode('|', $parts).'|'.json_encode($alldata);
                        $alldata['lasttask'] = $time;
                        // echo json_encode($alldata).PHP_EOL;
                        $lines[$key] = implode('|', $parts).'|'.json_encode($alldata);
                        break;
                    }
                }
            }
            // Prepare the JSON response
            $response = $output;
        }
        

        if (!empty($response)) {
            // Set the response headers
            header('Content-Type: text/plain');
            // Convert the response array to JSON format and echo it
            echo implode(PHP_EOL, $response).PHP_EOL;
            // sort($lines);
            // file_put_contents($logFile, implode(PHP_EOL, $lines).PHP_EOL);
            $lines = array_filter($response);

            // var_dump($response);

            // Print the results
            foreach ($lines as $line) {
                $responseArray = explode("|", $line);
                $post_uuid = $responseArray[0];
                $json = end($responseArray);

                $keywordData = json_decode($json, true);
                $keywordData['post_uuid'] = $keywordData['post_uuid'] ?? $post_uuid;

                // var_dump($sitedata);
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
                        case "tw":
                            $geo = "TW";
                            break;
                        case "hk":
                            $geo = "HK";
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
                            $geo = strtoupper($keywordData['lang']);  // Default case for unrecognized languages
                            break;
                    }

                    $keywordData['geo'] = $geo;
                }

                if (empty($keywordData['lasttask'])) {
                    $keywordData['lasttask'] = date("Ymd");
                }

                // var_dump($sitedata);

                $keyword = $keywordData['keyword'] ?? ""; // Replace with your actual keyword
                $git_name = $keywordData['git_name'] ?? ""; // Replace with your actual git name
                $domain = $keywordData['domain'] ?? ""; // Replace with your actual git name
                $lang = $keywordData['lang'] ?? ""; // Replace with your actual language
                $geo = $keywordData['geo'] ?? ""; // Replace with your actual geo
                $lasttask = $keywordData['lasttask'] ?? date("Ymd");
                $status = $keywordData['status'] ?? "running";
                $pubdir = $keywordData['pubdir'] ?? "";
                $post_uuid = $keywordData['post_uuid'] ?? "";

                if (!empty($keyword)) {
                    // $url = 'http://43.153.41.108/hugo/keywordops.php';
                    $url = $remoteServerURL.'/hugo/topicops.php';
                    $data = [
                        'post_uuid' => $post_uuid,
                        'post_keyword' => $keyword,
                        'setupNum' => 'ckeditorFormated',
                        'post_bulkkeyword' => 'disable',
                        'post_gitname' => $git_name,
                        'post_domain' => $domain,
                        'post_lang' => $lang,
                        'post_geo' => $geo,
                        'post_pubdir' => $pubdir,
                        'post_status' => $status,
                        'post_lasttask' => $lasttask,
                    ];

                    $options = [
                        CURLOPT_URL => $url,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => http_build_query($data),
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => true, // Follow redirects
                        CURLOPT_SSL_VERIFYPEER => false, // Ignore SSL certificate verification
                        CURLOPT_TIMEOUT => 5,
                    ];

                    $ch = curl_init();
                    curl_setopt_array($ch, $options);
                    $response = curl_exec($ch);
                    curl_close($ch);

                    // Optionally handle the response
                    // echo $response;
                }
            }
        }
    }
}

