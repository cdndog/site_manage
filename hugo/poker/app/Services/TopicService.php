<?php

namespace App\Services;

use App\Config;
use App\Repositories\TopicRepository;

class TopicService
{
    const RENEW_COLUMNS = [
        'ctx_id', 'git_name', 'domain', 'keyword', 'pubdir', 'status',
        'lang', 'geo', 'lasttask', 'json', 'time',
    ];

    const EXPORT_COLUMNS = [
        'ctx_id', 'keyword', 'status', 'git_name', 'domain', 'pubdir', 'lang', 'json',
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
            'ctx_id' => str_replace('.', '', uniqid(time(), true)),
            'post_keyword' => '',
            'post_gitname' => '',
            'post_domain' => '',
            'post_lang' => 'zh',
            'post_geo' => 'CN',
            'post_pubdir' => 'article',
            'post_status' => 'enable',
            'post_bulkkeyword' => 'enable',
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
            'git_name' => isset($post['post_gitname']) ? trim((string)$post['post_gitname']) : '',
            'domain' => isset($post['post_domain']) ? trim((string)$post['post_domain']) : '',
            'pubdir' => isset($post['post_pubdir']) ? trim((string)$post['post_pubdir']) : '',
            'status' => isset($post['post_status']) ? trim((string)$post['post_status']) : '',
            'lang' => isset($post['post_lang']) ? trim((string)$post['post_lang']) : '',
            'geo' => isset($post['post_geo']) ? trim((string)$post['post_geo']) : '',
            'lasttask' => isset($post['post_lasttask']) ? trim((string)$post['post_lasttask']) : '',
        ];
        $ctxId = isset($post['post_uuid']) ? trim((string)$post['post_uuid']) : '';
        foreach ($keywords as $keyword) {
            $keyword = trim($keyword);
            if ($keyword === '') {
                continue;
            }
            $record = $base;
            $record['keyword'] = $keyword;
            $record['ctx_id'] = $bulk ? str_replace('.', '', uniqid(time(), true)) : $ctxId;
            $record['json'] = json_encode([
                'git_name' => $base['git_name'],
                'domain' => $base['domain'],
                'keyword' => $keyword,
                'pubdir' => $base['pubdir'],
                'status' => $base['status'],
                'lang' => $base['lang'],
                'geo' => $base['geo'],
                'lasttask' => $base['lasttask'],
            ], JSON_UNESCAPED_UNICODE);
            $records[] = $record;
        }
        return $records;
    }

    public static function saveAll(array $records)
    {
        $saved = [];
        foreach ($records as $record) {
            $saved[] = TopicRepository::upsertByTopic($record);
        }
        return $saved;
    }

    public static function saveBackup(array $record)
    {
        $dir = Config::dataDir() . '/topicmonitor';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $ctxId = isset($record['ctx_id']) && $record['ctx_id'] !== '' ? $record['ctx_id'] : str_replace('.', '', uniqid(time(), true));
        file_put_contents($dir . '/' . $ctxId . '.json', isset($record['json']) ? $record['json'] : '');
        return $ctxId;
    }

    public static function export()
    {
        TopicRepository::export();
    }
}
