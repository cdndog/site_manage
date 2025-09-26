<?php

ini_set('memory_limit', '256M');

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
    // Check if the JSON payload is present
    if (!empty($jsonPost)) {
        $jsonName = isset($_POST['name']) ? $_POST['name'] : "";
        $uniqKey = isset($_POST['uniq']) ? $_POST['uniq'] : "url";
        // Decode the JSON payload
        $jsonData = json_decode($jsonPost, true);
        $localJson = 'seodata/'.$jsonName;

        if (file_exists($localJson)) {
            $oldJson = file_get_contents($localJson);
            $oldJson = json_decode($oldJson, true);
            // $jsonData = array_merge($jsonData, $oldJson);
            // Merge the arrays and ensure unique URLs using array_column() and array_merge()
            $mergedData = array_merge(
                array_column($oldJson, null, $uniqKey ),
                array_column($jsonData, null, $uniqKey )
            );

            // Convert the merged data array back to sequential array
            $jsonData = array_values($mergedData);
        }

        $jsonData = makeArrayUnique($jsonData, $uniqKey);

        // Check if the JSON decoding was successful
        if ($jsonData !== null && !empty($jsonName)) {
            file_put_contents('seodata/'.$jsonName, json_encode($jsonData));
            echo "{$jsonName} saved.".PHP_EOL;
        }
    } else {
        // Display an error message if the JSON payload is empty
        echo "Empty JSON posted.";
    }
}
?>