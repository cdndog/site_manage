<?php

namespace App\Support;

use App\Config;

class Logger
{
    const AUDIT_FILE = 'siteops_submit.log';

    public static function auditSubmit($uuid, $gitName, $domain, $status)
    {
        $line = date('Y-m-d H:i:s');
        foreach ([$uuid, $gitName, $domain, $status] as $value) {
            $line .= '|' . str_replace('|', '', (string)$value);
        }
        @file_put_contents(Config::dataDir() . '/' . self::AUDIT_FILE, $line . PHP_EOL, FILE_APPEND);
    }

    public static function auditKeyword($keyword, $gitName, $status)
    {
        $line = date('Y-m-d H:i:s');
        foreach ([$keyword, $gitName, $status] as $value) {
            $line .= '|' . str_replace('|', '', (string)$value);
        }
        @file_put_contents(Config::dataDir() . '/' . self::AUDIT_FILE, $line . PHP_EOL, FILE_APPEND);
    }

    public static function auditTopic($keyword, $gitName, $status)
    {
        $line = date('Y-m-d H:i:s');
        foreach ([$keyword, $gitName, $status] as $value) {
            $line .= '|' . str_replace('|', '', (string)$value);
        }
        @file_put_contents(Config::dataDir() . '/' . self::AUDIT_FILE, $line . PHP_EOL, FILE_APPEND);
    }
}