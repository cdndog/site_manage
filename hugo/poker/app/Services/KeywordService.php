<?php

namespace App\Services;

use App\Config;
use App\Repositories\KeywordRepository;

class KeywordService
{
    const RENEW_COLUMNS = [
        'ctx_id', 'keyword', 'status', 'git_name', 'pubdir', 'lang', 'geo',
        'lasttask', 'json', 'time',
    ];

    const EXPORT_COLUMNS = [
        'ctx_id', 'keyword', 'status', 'git_name', 'pubdir', 'lang', 'json',
    ];

    public static function sanitizePost(array $post)
    {
        array_walk($post, function (&$item) {
            $item = str_replace('|', '', $item);
        });
        return $post;
    }

    public static function defaultForm()
    {
        return [
            'ctx_id' => '',
            'post_keyword' => '',
            'post_gitname' => '',
            'post_lang' => 'en',
            'post_geo' => 'US',
            'post_pubdir' => 'article',
            'post_status' => 'enable',
            'post_bulkkeyword' => '',
        ];
    }

    public static function buildRecords(array $post)
    {
        $records = [];
        $keywordField = isset($post['post_keyword']) ? trim((string)$post['post_keyword']) : '';
        if ($keywordField === '') {
            return $records;
        }
        $bulk = isset($post['post_bulkkeyword']) && trim((string)$post['post_bulkkeyword']) === 'enable';
        $keywords = $bulk ? explode(',', $keywordField) : [$keywordField];
        $base = [
            'ctx_id' => isset($post['post_ctxid']) ? trim((string)$post['post_ctxid']) : '',
            'git_name' => isset($post['post_gitname']) ? trim((string)$post['post_gitname']) : '',
            'pubdir' => isset($post['post_pubdir']) ? trim((string)$post['post_pubdir']) : '',
            'status' => isset($post['post_status']) ? trim((string)$post['post_status']) : '',
            'lang' => isset($post['post_lang']) ? trim((string)$post['post_lang']) : '',
            'geo' => isset($post['post_geo']) ? trim((string)$post['post_geo']) : '',
            'lasttask' => isset($post['post_lasttask']) ? trim((string)$post['post_lasttask']) : '',
        ];
        foreach ($keywords as $keyword) {
            $keyword = trim($keyword);
            if ($keyword === '') {
                continue;
            }
            $record = $base;
            $record['keyword'] = $keyword;
            $record['json'] = json_encode([
                'keyword' => $keyword,
                'git_name' => $base['git_name'],
                'pubdir' => $base['pubdir'],
                'status' => $base['status'],
                'lang' => $base['lang'],
                'geo' => $base['geo'],
                'lasttask' => $base['lasttask'],
            ]);
            $records[] = $record;
        }
        return $records;
    }

    public static function saveAll(array $records)
    {
        $saved = [];
        foreach ($records as $record) {
            $saved[] = KeywordRepository::upsertByKeyword($record);
        }
        return $saved;
    }

    public static function saveBackup(array $record)
    {
        $dir = Config::dataDir() . '/keywordmonitor';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $ctxId = isset($record['ctx_id']) && $record['ctx_id'] !== '' ? $record['ctx_id'] : str_replace('.', '', uniqid(time(), true));
        file_put_contents($dir . '/' . $ctxId . '.json', isset($record['json']) ? $record['json'] : '');
        return $ctxId;
    }

    public static function export()
    {
        $lines = [];
        foreach (KeywordRepository::all() as $row) {
            $parts = [];
            foreach (self::EXPORT_COLUMNS as $column) {
                $parts[] = isset($row[$column]) ? (string)$row[$column] : '';
            }
            $lines[] = implode('|', $parts);
        }
        file_put_contents(Config::dataDir() . '/keyword_monitor_list.txt', implode(PHP_EOL, $lines));
    }
}
