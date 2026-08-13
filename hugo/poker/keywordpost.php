<?php

// Function to make the array of objects unique based on "$key"

function makeArrayUnique($array, $key) {
    $result = array();
    $temp = array();
    $hasKey = false;

    foreach ($array as $item) {
        if (!empty($item[$key])) {
            $lowercaseValue = strtolower($item[$key]);
            if (!in_array($lowercaseValue, $temp)) {
                $temp[] = $lowercaseValue;
                $result[md5($item[$key])] = $item;
                $hasKey = true;
            } else {
                $result[md5($item[$key])] = $item;
            }
        }
    }

    return $hasKey ? array_values($result) : array_values($array);
}

function unixToUtcIso8601(int $timestamp): string {
    return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
}

function datareport_format($json_data) {
    if (empty($json_data['post_uuid'])) return [];

    $output['ctx_id'] = $json_data['post_uuid'] ?? "";
    $output['keyword'] = $json_data['topic'] ?? "";
    $output['lang'] = $json_data['lang'] ?? "";
    $output['pubdomain'] = isset($json_data['pubdomain']) && is_array($json_data['pubdomain']) ? implode(',', $json_data['pubdomain']) : "";

    if ( isset($json_data['post_uuid']) ) {
        $ctx_id = $json_data['post_uuid'];
        $timestamp_substr = substr($ctx_id, 0, 10); // first 10 chars assumed as timestamp string
        $timestamp = intval($timestamp_substr);
        // Format as "YYYYMMDDHHMMSS"
        $formatted_date = date("Y-m-d\TH:i:s\Z", $timestamp);
        $output['createAt'] = $formatted_date;
    } else {
        $output['createAt'] = "";
    }

    $output['publishAt'] = date("Y-m-d\TH:i:s\Z");
    return $output;
}

// Check if a JSON payload was received
if (!empty($_POST)) {
    // Retrieve the JSON payload from the POST request
    $jsonPost = $_POST['json'];
    $dir = 'seodata';

    // Check if the directory exists
    if (!is_dir($dir)) {
        // Try to create the directory
        if (!mkdir($dir, 0755, true)) {
            echo "create dir failed.";
        } 
    }

    if (!is_dir($dir.'/json')) {
        mkdir($dir.'/json', 0775, true);
    }

    // Check if the JSON payload is present
    if (!empty($jsonPost)) {
        $jsonName = isset($_POST['name']) ? $_POST['name'] : "";
        $uniqKey = isset($_POST['uniq']) ? $_POST['uniq'] : "url";
        // Decode the JSON payload
        $jsonData = json_decode($jsonPost, true);
        $localJson = 'seodata/'.$jsonName;

        // create localjson and index file

        if (!empty($jsonData["post_uuid"])) {
            $localJsonFile = $dir.'/json/'.$jsonData["post_uuid"].'.json';

            file_put_contents($localJsonFile, json_encode($jsonData));

            // $jsonDataIndex = [
            //     "ctx_id" => $jsonData['post_uuid'],
            //     "keyword" => $jsonData['title']['text'][0] ?? "",
            //     "lang" => $jsonData['lang'] ?? "",
            //     "pubdomain" => implode(',', $jsonData['pubdomain'] ?? []),
            //     "createAt" => unixToUtcIso8601($jsonData['createAt']['text'][0] ?? 0) ?? date("Y-m-d\TH:i:s\Z"),
            //     "publishAt" => $jsonData['update_date'] ?? date("Y-m-d\TH:i:s\Z"),
            // ];

           $jsonDataIndex = datareport_format($jsonData);

            if (file_exists($localJson)) {
                $oldJson = file_get_contents($localJson);
                $oldJson = json_decode($oldJson, true);
                // Merge the arrays and ensure unique URLs using array_column() and array_merge()
                $mergedData = array_merge(
                    array_column($oldJson, null, $uniqKey ),
                    array_column($jsonDataIndex, null, $uniqKey )
                );

                // Convert the merged data array back to sequential array
                $jsonDataIndex = array_values($mergedData);
            }

            $jsonDataIndex = makeArrayUnique($jsonDataIndex, $uniqKey);

            // Check if the JSON decoding was successful
            if ($jsonDataIndex !== null && !empty($jsonName)) {
                file_put_contents('seodata/'.$jsonName, json_encode($jsonDataIndex));
                echo "{$jsonName} saved.".PHP_EOL;
            }
        }


    } else {
        // Display an error message if the JSON payload is empty
        echo "Empty JSON posted.";
    }
}
?>