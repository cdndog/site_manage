<?php

$data_file = 'datas/leagues_datas_all.json';

if (file_exists($data_file)) {
    $config_datas = file_get_contents($data_file);
    $config_datas_json = json_decode($config_datas, true);
    // Sort seasons by year in descending order for each league
    // foreach ($config_datas_json['response'] as &$league) {
    //     usort($league['seasons'], function ($a, $b) {
    //         return $b['year'] <=> $a['year']; // Descending order
    //     });
    // }
} else {
    $config_datas_json = array();
}

return $config_datas_json['response'];

// print_r($config_datas_json['response']);
