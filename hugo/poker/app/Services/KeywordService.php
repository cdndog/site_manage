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

    // 预留旧版布局：keywordmonitorlist 自 20240922 起一直是 7 列，
    // 暂无历史异构格式；后续若出现，在此登记即可被 parseMonitorLine 自动识别。
    const LEGACY_EXPORT_COLUMNS = [];

    /**
     * 解析 keyword_monitor_list.txt 的一行，按列数严格分派已知布局。
     * 列数不匹配或 json 非法一律返回 null，避免"放行后静默错位"。
     *
     * @return array|null 解析成功返回列=>值映射，失败返回 null
     */
    public static function parseMonitorLine(string $line)
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }
        $parts = explode('|', $line);
        $count = count($parts);
        $columns = null;
        if ($count === count(self::EXPORT_COLUMNS)) {
            $columns = self::EXPORT_COLUMNS;
        } elseif (self::LEGACY_EXPORT_COLUMNS !== [] && $count === count(self::LEGACY_EXPORT_COLUMNS)) {
            $columns = self::LEGACY_EXPORT_COLUMNS;
        }
        if ($columns === null) {
            return null;
        }
        $jsonRaw = $parts[$count - 1];
        $json = json_decode($jsonRaw, true);
        if (!is_array($json)) {
            return null;
        }
        $row = array_combine($columns, $parts);
        $row['ctx_id'] = trim($row['ctx_id']);
        if ($row['ctx_id'] === '') {
            return null;
        }
        // 现行布局无 geo / lasttask 列，从 json 回填
        if (!isset($row['geo'])) {
            $row['geo'] = (string)($json['geo'] ?? '');
        }
        if (!isset($row['lasttask'])) {
            $row['lasttask'] = (string)($json['lasttask'] ?? '');
        }
        $row['json'] = $jsonRaw;
        $row['decoded'] = $json;
        $row['legacy'] = ($columns !== self::EXPORT_COLUMNS);
        return $row;
    }

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

    /**
     * 由各列值生成 keywordmonitorlist.json 镜像字段。
     * 部分更新后需用它按合并结果重算，保证 json 列与各列不脱节。
     */
    public static function buildJson(array $fields)
    {
        return json_encode([
            'keyword' => isset($fields['keyword']) ? $fields['keyword'] : '',
            'git_name' => isset($fields['git_name']) ? $fields['git_name'] : '',
            'pubdir' => isset($fields['pubdir']) ? $fields['pubdir'] : '',
            'status' => isset($fields['status']) ? $fields['status'] : '',
            'lang' => isset($fields['lang']) ? $fields['lang'] : '',
            'geo' => isset($fields['geo']) ? $fields['geo'] : '',
            'lasttask' => isset($fields['lasttask']) ? $fields['lasttask'] : '',
        ]);
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
            $record['json'] = self::buildJson($record);
            $records[] = $record;
        }
        return $records;
    }

    /**
     * $providedColumns 为 null 时保持历史行为（整行覆盖）；
     * 传入列名数组时仅更新这些列，未传列沿用库中原值。
     */
    public static function saveAll(array $records, ?array $providedColumns = null)
    {
        $saved = [];
        foreach ($records as $record) {
            $saved[] = KeywordRepository::upsertByKeyword($record, $providedColumns);
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
        @file_put_contents(Config::dataDir() . '/keyword_monitor_list.txt', implode(PHP_EOL, $lines));
    }
}
