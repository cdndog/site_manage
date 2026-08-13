<?php

namespace App\Services;

use App\Config;

class ReportService
{
    const WORDLIST_COLUMNS = ['ctx_id', 'keyword', 'status', 'git_name', 'pubdir', 'lang', 'json'];

    const SITELIST_COLUMNS = [
        'ctx_id', 'git_name', 'git_account', 'status', 'theme_type', 'languages',
        'domain', 'sns_id', 'topnav_menus', 'site_title', 'site_subtitle', 'json',
    ];

    const RELATEWORD_COLUMNS = ['createtime', 'subword', 'status', 'domain', 'pubdir', 'lang', 'mainword'];

    const TOPICLIST_COLUMNS = ['ctx_id', 'keyword', 'status', 'git_name', 'domain', 'pubdir', 'lang', 'json'];

    public static function readLines($file)
    {
        $path = Config::dataDir() . '/' . $file;
        if (!file_exists($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return is_array($lines) ? $lines : [];
    }

    public static function parseColumns(array $lines, array $defaultColumns, $headerPattern = '')
    {
        $columns = $defaultColumns;
        $rows = [];
        foreach ($lines as $dataKey => $dataLine) {
            $dataLine = trim($dataLine);
            if ($dataLine === '') {
                continue;
            }
            if ($dataKey === 0 && $headerPattern !== '' && preg_grep('#' . preg_quote($headerPattern, '#') . '#i', [$dataLine])) {
                $columns = explode('|', $dataLine);
                continue;
            }
            $parts = explode('|', $dataLine);
            if (count($columns) !== count($parts)) {
                continue;
            }
            $rows[] = array_combine($columns, $parts);
        }
        return $rows;
    }

    public static function wordlist()
    {
        $rows = self::parseColumns(self::readLines('keyword_monitor_list.txt'), self::WORDLIST_COLUMNS);
        foreach ($rows as &$row) {
            $json = isset($row['json']) ? json_decode($row['json'], true) : null;
            $row['lasttask'] = is_array($json) && !empty($json['lasttask']) ? $json['lasttask'] : '';
            $row['ctx_id'] = isset($row['ctx_id']) ? $row['ctx_id'] : '';
            $row['keyword'] = isset($row['keyword']) ? $row['keyword'] : '';
            $row['status'] = isset($row['status']) ? $row['status'] : '';
            $row['git_name'] = isset($row['git_name']) ? $row['git_name'] : '';
            $row['pubdir'] = isset($row['pubdir']) ? $row['pubdir'] : '';
            $row['lang'] = isset($row['lang']) ? $row['lang'] : '';
        }
        unset($row);
        return $rows;
    }

    public static function sitelist()
    {
        return self::parseColumns(self::readLines('siteops_setting.txt'), self::SITELIST_COLUMNS);
    }

    public static function relateword()
    {
        return self::parseColumns(
            self::readLines('table_relatedword.txt'),
            self::RELATEWORD_COLUMNS,
            'createtime|subword'
        );
    }

    public static function topiclist()
    {
        $rows = self::parseColumns(self::readLines('topic_monitor_list.txt'), self::TOPICLIST_COLUMNS);
        if (count($rows) === 0) {
            $rows = self::topiclistFromDb();
        }
        foreach ($rows as &$row) {
            $json = isset($row['json']) ? json_decode($row['json'], true) : null;
            $row['lasttask'] = is_array($json) && !empty($json['lasttask']) ? $json['lasttask'] : (isset($row['lasttask']) ? $row['lasttask'] : '');
            $row['geo'] = is_array($json) && !empty($json['geo']) ? $json['geo'] : (isset($row['geo']) ? $row['geo'] : '');
            $row['title'] = isset($row['keyword']) ? $row['keyword'] : '';
            if (is_array($json)) {
                if (!empty($json['title'])) {
                    $row['title'] = $json['title'];
                } elseif (!empty($json['keyword'])) {
                    $row['title'] = $json['keyword'];
                }
            }
            $row['ctx_id'] = isset($row['ctx_id']) ? $row['ctx_id'] : '';
            $row['keyword'] = isset($row['keyword']) ? $row['keyword'] : '';
            $row['status'] = isset($row['status']) ? $row['status'] : '';
            $row['git_name'] = isset($row['git_name']) ? $row['git_name'] : '';
            $row['domain'] = isset($row['domain']) ? $row['domain'] : '';
            $row['pubdir'] = isset($row['pubdir']) ? $row['pubdir'] : '';
            $row['lang'] = isset($row['lang']) ? $row['lang'] : '';
        }
        unset($row);
        return $rows;
    }

    private static function topiclistFromDb()
    {
        $rows = \App\Repositories\TopicRepository::all();
        $output = [];
        foreach ($rows as $row) {
            $json = isset($row['json']) ? json_decode($row['json'], true) : null;
            $output[] = [
                'ctx_id' => isset($row['ctx_id']) ? $row['ctx_id'] : '',
                'keyword' => isset($row['keyword']) ? $row['keyword'] : '',
                'title' => isset($row['keyword']) ? $row['keyword'] : '',
                'status' => isset($row['status']) ? $row['status'] : '',
                'git_name' => isset($row['git_name']) ? $row['git_name'] : '',
                'domain' => isset($row['domain']) ? $row['domain'] : '',
                'pubdir' => isset($row['pubdir']) ? $row['pubdir'] : '',
                'lang' => isset($row['lang']) ? $row['lang'] : '',
                'geo' => isset($row['geo']) ? $row['geo'] : '',
                'lasttask' => isset($row['lasttask']) ? $row['lasttask'] : '',
                'json' => isset($row['json']) ? $row['json'] : '',
            ];
            if (is_array($json)) {
                if (!empty($json['title'])) {
                    $output[count($output) - 1]['title'] = $json['title'];
                } elseif (!empty($json['keyword'])) {
                    $output[count($output) - 1]['title'] = $json['keyword'];
                }
            }
        }
        return $output;
    }
}
