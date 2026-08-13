<?php

namespace App\Services;

use App\Config;
use App\Database;

class ExportService
{
    public static function export()
    {
        $rows = Database::fetchAll('SELECT * FROM "siteops" WHERE "*" = "*"');
        $outputText = '';
        foreach ($rows as $row) {
            $rowData = '';
            foreach (SiteService::EXPORT_COLUMNS as $column) {
                $rowData .= (isset($row[$column]) ? $row[$column] : '') . '|';
            }
            $outputText .= rtrim($rowData, '|') . PHP_EOL;
        }
        $outputFile = Config::dataDir() . '/siteops_setting.txt';
        $tmpFile = $outputFile . '.tmp';
        file_put_contents($tmpFile, $outputText);
        rename($tmpFile, $outputFile);
    }
}
