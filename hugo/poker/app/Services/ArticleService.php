<?php

namespace App\Services;

use App\Config;
use App\Repositories\ArticleRepository;

class ArticleService
{
    const RENEW_COLUMNS = [
        'ctx_id', 'url', 'title', 'keyword', 'tags', 'description',
        'static_thumbnail', 'iframesrc', 'lang', 'series', 'pubdir',
        'savename', 'globalpublish', 'pubdomain', 'translate_to_langs',
        'content', 'json', 'json_file',
    ];

    const SETUP_MARKER = 'ckeditorFormated';

    public static function jsonDir()
    {
        return Config::dataDir() . '/json';
    }

    public static function defaultForm()
    {
        return [
            'ctx_id' => uuid(),
            'url' => '',
            'title' => '',
            'static_thumbnail' => '',
            'iframesrc' => '',
            'tags' => '',
            'keyword' => '',
            'description' => '',
            'content' => '',
            'lang' => '',
            'series' => '',
            'pubdir' => '',
            'savename' => '',
            'pubdomain' => [],
            'globalpublish' => '',
            'translate_to_langs' => [],
        ];
    }

    public static function sanitizePost(array $post)
    {
        $clean = [];
        foreach ($post as $key => $value) {
            if (is_array($value)) {
                $clean[$key] = array_values(array_filter(array_map(function ($item) {
                    return str_replace('|', '', trim((string)$item));
                }, $value), function ($item) {
                    return $item !== '';
                }));
                continue;
            }
            $clean[$key] = str_replace('|', '', trim((string)$value));
        }
        return $clean;
    }

    public static function buildJson(array $post, array $base = [])
    {
        if ($base === [] && isset($post['post_json']) && $post['post_json'] !== '') {
            $decoded = json_decode((string)$post['post_json'], true);
            if (is_array($decoded)) {
                $base = $decoded;
            }
        }

        $ctxId = isset($post['post_uuid']) && $post['post_uuid'] !== '' ? $post['post_uuid'] : uuid();
        $ctxId = str_replace('|', '', $ctxId);

        $uuidTs = substr($ctxId, 0, 10);
        if (!is_numeric($uuidTs) || $uuidTs < 0 || strlen($uuidTs) !== 10) {
            $uuidTs = time();
        }

        $url = isset($post['post_url']) && trim($post['post_url']) !== ''
            ? str_replace('|', '', trim($post['post_url']))
            : str_replace('|', '', isset($post['post_title']) ? trim($post['post_title']) : '');

        // 以原 JSON 为底，只覆盖表单可编辑字段；未在表单里的键保持原样
        $json = $base;
        $json['pubdir'] = isset($post['post_pubdir']) ? trim($post['post_pubdir']) : '';
        if (self::textFirst($json['createAt'] ?? null) === '') {
            $json['createAt'] = self::withText($json['createAt'] ?? null, (int)$uuidTs);
        }
        $json['update_date'] = date('Y-m-d\TH:i:s\Z');
        $json['title'] = self::withText($json['title'] ?? null, isset($post['post_title']) ? trim($post['post_title']) : '');
        $json['url'] = $url;
        $json['lang'] = isset($post['post_lang']) ? trim($post['post_lang']) : '';
        $json['static_thumbnail'] = self::withText($json['static_thumbnail'] ?? null, isset($post['post_static_thumbnail']) ? trim($post['post_static_thumbnail']) : '');
        $json['iframesrc'] = self::withTextValues($json['iframesrc'] ?? null, isset($post['post_iframesrc']) && trim($post['post_iframesrc']) !== ''
            ? array_values(array_filter(array_map('trim', explode(',', (string)$post['post_iframesrc'])), function ($item) {
                return $item !== '';
            }))
            : []);
        $json['series'] = isset($post['post_series']) ? trim($post['post_series']) : '';
        $json['tags'] = self::withTextValues($json['tags'] ?? null, isset($post['post_tag']) && trim($post['post_tag']) !== ''
            ? array_values(array_filter(array_map('trim', explode(',', (string)$post['post_tag'])), function ($item) {
                return $item !== '';
            }))
            : []);
        $json['keywords'] = self::withTextValues($json['keywords'] ?? null, isset($post['post_keyword']) && trim($post['post_keyword']) !== ''
            ? array_values(array_filter(array_map('trim', explode(',', (string)$post['post_keyword'])), function ($item) {
                return $item !== '';
            }))
            : []);
        $json['description'] = self::withText($json['description'] ?? null, isset($post['post_description']) ? trim($post['post_description']) : '');

        // post_slug：publish_edit（json_edit）模式，字段直接绑定 slug，仅写 slug，不动 savename
        if (array_key_exists('post_slug', $post)) {
            $slugVal = trim((string)$post['post_slug']);
            $json['slug'] = self::withText($json['slug'] ?? null, $slugVal);
        } else {
            // 保存名称与 slug 是同一项：字段值同步写入两处（article_new / wechat 导入旧流程）
            $savename = isset($post['post_savename']) ? trim((string)$post['post_savename']) : '';
            $baseSavename = self::textFirst($base['savename'] ?? null);
            $baseSlug = self::textFirst($base['slug'] ?? null);
            $json['savename'] = self::withText($json['savename'] ?? null, $savename);
            $syncSlug = !isset($base['slug'])
                || $baseSlug === ''
                || $baseSlug === $baseSavename
                || $savename !== $baseSavename;
            if ($syncSlug && ($savename !== '' || isset($base['slug']))) {
                $json['slug'] = self::withText($json['slug'] ?? null, $savename);
            }
        }

        // 发布/翻译开关：表单里没有该字段（JSON 编辑模式已隐藏）时保留原值，提交了才覆盖
        $json['globalpublish'] = array_key_exists('post_globalpublish', $post)
            ? (trim((string)$post['post_globalpublish']) !== '' ? trim((string)$post['post_globalpublish']) : 'no')
            : (isset($base['globalpublish']) && $base['globalpublish'] !== '' ? (string)$base['globalpublish'] : 'no');
        $json['pubdomain'] = isset($post['post_pubdomain']) && is_array($post['post_pubdomain'])
            ? array_values(array_filter($post['post_pubdomain'], function ($item) {
                return trim((string)$item) !== '';
            }))
            : [];
        $json['translate_to_langs'] = array_key_exists('post_translate_to_langs', $post)
            ? (isset($post['post_translate_to_langs']) && is_array($post['post_translate_to_langs'])
                ? array_values(array_filter($post['post_translate_to_langs'], function ($item) {
                    return trim((string)$item) !== '';
                }))
                : [])
            : (isset($base['translate_to_langs']) && is_array($base['translate_to_langs']) ? $base['translate_to_langs'] : []);
        $json['content'] = self::withHtml($json['content'] ?? null, isset($post['post_ckeditor_contents']) ? (string)$post['post_ckeditor_contents'] : '');
        $json['upload_site'] = trim((string)($base['upload_site'] ?? '')) !== ''
            ? $base['upload_site']
            : (string)Config::baseKey('siteConfigServerURL', 'https://wptg.wptdata.com');
        $json['post_uuid'] = $ctxId;
        return $json;
    }

    /**
     * 读取 text/html 节点首个值：兼容 {text:[...]}、{text:"..."}（字符串——PHP 字符串下标 [0] 会只取首字符，
     * 曾导致保存名称只显示首字母 value="c"）、整节点为标量、缺失等形态，绝不取首字符。
     */
    public static function textFirst($node)
    {
        if (is_array($node)) {
            $node = $node['text'] ?? null;
        }
        if (is_array($node)) {
            $node = $node[0] ?? null;
        }
        if (is_string($node)) {
            return $node;
        }
        if (is_int($node) || is_float($node)) {
            return (string)$node;
        }
        return '';
    }

    /**
     * 合并 content.html 的全部片段，跳过空段落；html 为空时回退 content.text。
     */
    public static function htmlFirst($node)
    {
        return self::htmlJoin($node);
    }

    private static function htmlJoin($node)
    {
        if (is_string($node)) {
            $trimmed = trim($node);
            if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    return self::htmlJoin($decoded);
                }
            }
            return self::isBlankHtml($node) ? '' : $node;
        }
        if (is_array($node)) {
            if (array_key_exists('html', $node)) {
                $html = self::htmlJoin($node['html']);
                if ($html !== '') {
                    return $html;
                }
            }
            if (array_key_exists('text', $node)) {
                $text = self::textFirst($node);
                if ($text !== '') {
                    return $text;
                }
            }
            $parts = [];
            foreach ($node as $item) {
                $part = self::htmlJoin($item);
                if ($part !== '') {
                    $parts[] = $part;
                }
            }
            return implode("\n", $parts);
        }
        if (is_int($node) || is_float($node)) {
            return (string)$node;
        }
        return '';
    }

    private static function isBlankHtml($html): bool
    {
        $html = (string)$html;
        if (trim($html) === '') {
            return true;
        }
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text);
        if (trim($text) !== '') {
            return false;
        }
        foreach (['<img', '<iframe', '<video', '<audio', '<canvas', '<svg'] as $tag) {
            if (stripos($html, $tag) !== false) {
                return false;
            }
        }
        return true;
    }

    /** 读取 text 节点全部值为数组（原为字符串时整体视为一项），供 implode/多值字段使用 */
    public static function textValues($node): array
    {
        if (is_array($node)) {
            $node = $node['text'] ?? null;
        }
        if (is_string($node)) {
            return $node === '' ? [] : [$node];
        }
        if (is_array($node)) {
            return array_values(array_map(function ($item) {
                if (is_string($item)) {
                    return $item;
                }
                if (is_int($item) || is_float($item)) {
                    return (string)$item;
                }
                return '';
            }, $node));
        }
        if (is_int($node) || is_float($node)) {
            return [(string)$node];
        }
        return [];
    }

    /**
     * 写回 text 节点并保持原有数据格式：
     * 原 text 为字符串 → 仍写字符串；原为数组/缺失 → 写数组；整节点原为标量 → 仍写标量。
     */
    private static function withText($node, $value)
    {
        if (is_string($node)) {
            return (string)$value;
        }
        $node = is_array($node) ? $node : [];
        if (isset($node['text']) && is_string($node['text'])) {
            $node['text'] = (string)$value;
        } else {
            $node['text'] = [$value];
        }
        return $node;
    }

    private static function withTextValues($node, array $values)
    {
        $values = array_map('strval', $values);
        if (is_string($node)) {
            return implode(',', $values);
        }
        $node = is_array($node) ? $node : [];
        if (isset($node['text']) && is_string($node['text'])) {
            $node['text'] = implode(',', $values);
        } else {
            $node['text'] = $values;
        }
        return $node;
    }

    private static function withHtml($node, $value)
    {
        if (is_string($node)) {
            return (string)$value;
        }
        $node = is_array($node) ? $node : [];
        if (isset($node['html']) && is_string($node['html'])) {
            $node['html'] = (string)$value;
        } else {
            $node['html'] = [$value];
        }
        return $node;
    }

    /**
     * 读取现有文章 JSON（优先 seodata/json，其次 json/），返回对象级数组或 null。
     * 文件里的 [{...}] 包裹格式会被解开，与页面 GET 逻辑一致。
     */
    public static function loadJsonForEdit($ctxId)
    {
        $ctxId = str_replace('|', '', trim((string)$ctxId));
        if ($ctxId === '') {
            return null;
        }
        $candidates = [
            Config::dataDir() . '/seodata/json/' . $ctxId . '.json',
            self::jsonDir() . '/' . $ctxId . '.json',
        ];
        foreach ($candidates as $file) {
            if (!is_file($file)) {
                continue;
            }
            $data = json_decode((string)file_get_contents($file), true);
            if (is_array($data) && isset($data[0]) && is_array($data[0])) {
                $data = $data[0];
            }
            if (is_array($data)) {
                return $data;
            }
        }
        return null;
    }

    public static function jsonFileName($ctxId)
    {
        return 'json/' . $ctxId . '.json';
    }

    /**
     * 日志文件路径解析：绝对路径原样使用，相对路径按项目根（APP_PATH）解析。
     * 之前一律 `APP_PATH . '/' . ltrim($path,'/')` 会把 /xxx/yyy 拼成 APP_PATH/xxx/yyy，导致绝对路径永远找不到文件。
     */
    public static function resolveLogPath($logFile)
    {
        $logFile = trim((string)$logFile);
        if ($logFile === '') {
            return '';
        }
        if ($logFile[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $logFile)) {
            return $logFile;
        }
        return APP_PATH . '/' . ltrim($logFile, '/');
    }

    public static function importFromLogFile($logFile)
    {
        $result = [
            'file' => (string)$logFile,
            'imported' => 0,
            'skipped' => 0,
            'missing' => 0,
            'failed' => 0,
            'rows' => [],
        ];
        if (!is_file($logFile) || !is_readable($logFile)) {
            $result['error'] = '日志文件不存在或不可读：' . $logFile;
            return $result;
        }
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $records = [];
        $seen = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode('|', $line, 3);
            if (count($parts) !== 3) {
                $result['failed']++;
                $result['rows'][] = ['ok' => false, 'message' => '无效行（非 ctx_id|url|json 格式）', 'ctx_id' => ''];
                continue;
            }
            $ctxId = trim($parts[0]);
            $lineUrl = trim($parts[1]);
            $third = trim($parts[2]);
            if ($ctxId === '' || isset($seen[$ctxId])) {
                $result['skipped']++;
                $result['rows'][] = [
                    'ok' => false,
                    'message' => $ctxId === '' ? '无效行（ctx_id 为空），跳过' : '重复行（同批已出现），跳过',
                    'ctx_id' => $ctxId,
                ];
                continue;
            }
            $seen[$ctxId] = true;
            $json = null;
            if ($third !== '' && $third[0] === '{') {
                $json = json_decode($third, true);
                if (!is_array($json)) {
                    $result['failed']++;
                    $result['rows'][] = ['ok' => false, 'message' => 'JSON 解析失败', 'ctx_id' => $ctxId];
                    continue;
                }
            } else {
                $file = self::resolveJsonFile($third);
                if ($file === null) {
                    $result['missing']++;
                    $result['rows'][] = ['ok' => false, 'missing' => true, 'message' => 'JSON 文件缺失，跳过：' . $third, 'ctx_id' => $ctxId];
                    continue;
                }
                $json = json_decode((string)file_get_contents($file), true);
                if (!is_array($json)) {
                    $result['failed']++;
                    $result['rows'][] = ['ok' => false, 'message' => 'JSON 文件解析失败', 'ctx_id' => $ctxId];
                    continue;
                }
            }
            if (empty($json['post_uuid'])) {
                $json['post_uuid'] = $ctxId;
            }
            $json['post_uuid'] = $ctxId;
            if ($lineUrl !== '') {
                $json['url'] = $lineUrl;
            }
            $json = self::restoreFromJsonFiles($ctxId, $json);
            $json['post_uuid'] = $ctxId;
            $record = self::toRecord([], $json);
            $localFile = self::jsonDir() . '/' . $ctxId . '.json';
            if (!is_file($localFile)) {
                $dir = self::jsonDir();
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                file_put_contents($localFile, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            $record['json_file'] = self::jsonFileName($ctxId);
            $record['_raw_json'] = $json;
            $records[] = $record;
        }
        // 批量导入
        if (count($records) > 0) {
            $batchResult = \App\Repositories\ArticleRepository::batchImport($records);
            $result['imported'] += $batchResult['imported'];
            $result['skipped'] += $batchResult['skipped'];
            $result['failed'] += $batchResult['failed'];
            foreach ($records as $record) {
                if (isset($record['_raw_json']) && is_array($record['_raw_json'])) {
                    self::saveSeoData($record['_raw_json']);
                }
            }
        }
        return $result;
    }

    private static function resolveJsonFile($path)
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }
        if (is_file($path)) {
            return $path;
        }
        $candidates = [
            APP_PATH . '/' . $path,
            APP_PATH . '/pokerjson/' . basename($path),
            self::jsonDir() . '/' . basename($path),
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private static function restoreFromJsonFiles($ctxId, array $json)
    {
        $candidates = [
            self::jsonDir() . '/' . $ctxId . '.json',
            Config::dataDir() . '/seodata/json/' . $ctxId . '.json',
        ];
        foreach ($candidates as $file) {
            if (!is_file($file)) {
                continue;
            }
            $disk = json_decode((string)file_get_contents($file), true);
            if (!is_array($disk)) {
                continue;
            }
            foreach ($json as $key => $value) {
                if (!isset($disk[$key]) || $disk[$key] === '' || $disk[$key] === [] || $disk[$key] === null) {
                    $disk[$key] = $value;
                }
            }
            if (empty($disk['post_uuid'])) {
                $disk['post_uuid'] = $ctxId;
            }
            return $disk;
        }
        return $json;
    }

    public static function saveJsonFile(array $json)
    {
        $ctxId = isset($json['post_uuid']) ? $json['post_uuid'] : '';
        if ($ctxId === '') {
            return null;
        }
        $dir = self::jsonDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . '/' . $ctxId . '.json';
        file_put_contents($file, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $file;
    }

    public static function toRecord(array $post, array $json)
    {
        $ctxId = isset($json['post_uuid']) ? $json['post_uuid'] : '';
        return [
            'ctx_id' => $ctxId,
            'url' => isset($json['url']) ? (string)$json['url'] : '',
            'title' => self::textFirst($json['title'] ?? null),
            'keyword' => implode(',', self::textValues($json['keywords'] ?? null)),
            'tags' => implode(',', self::textValues($json['tags'] ?? null)),
            'description' => self::textFirst($json['description'] ?? null),
            'static_thumbnail' => self::textFirst($json['static_thumbnail'] ?? null),
            'iframesrc' => implode(',', self::textValues($json['iframesrc'] ?? null)),
            'lang' => isset($json['lang']) ? (string)$json['lang'] : '',
            'series' => isset($json['series']) ? (string)$json['series'] : '',
            'pubdir' => isset($json['pubdir']) ? (string)$json['pubdir'] : '',
            'savename' => self::textFirst($json['savename'] ?? null),
            'globalpublish' => isset($json['globalpublish']) ? (string)$json['globalpublish'] : 'no',
            'pubdomain' => isset($json['pubdomain']) && is_array($json['pubdomain']) ? implode(',', $json['pubdomain']) : '',
            'translate_to_langs' => isset($json['translate_to_langs']) && is_array($json['translate_to_langs']) ? implode(',', $json['translate_to_langs']) : '',
            'content' => self::htmlFirst($json['content'] ?? null),
            'json' => json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'json_file' => self::jsonFileName($ctxId),
            'update_date' => isset($json['update_date']) ? (string)$json['update_date'] : '',
        ];
    }

    public static function datareportFormat(array $json)
    {
        if (empty($json['post_uuid'])) {
            return [];
        }
        $output['ctx_id'] = isset($json['post_uuid']) ? (string)$json['post_uuid'] : '';
        // keyword 优先取 topic（发布时由 publish() 写入），导入日志时 topic 为空则回退取 title
        $kw = isset($json['topic']) && trim((string)$json['topic']) !== '' ? trim((string)$json['topic']) : '';
        if ($kw === '') {
            $kw = trim(self::textFirst($json['title'] ?? null));
        }
        $output['keyword'] = $kw;
        $output['lang'] = isset($json['lang']) ? (string)$json['lang'] : '';
        $output['pubdomain'] = isset($json['pubdomain']) && is_array($json['pubdomain']) ? implode(',', $json['pubdomain']) : '';
        $ctxId = (string)$json['post_uuid'];
        $ts = substr($ctxId, 0, 10);
        $output['createAt'] = ctype_digit($ts) ? date('Y-m-d\TH:i:s\Z', (int)$ts) : '';
        $output['publishAt'] = date('Y-m-d\TH:i:s\Z');
        return $output;
    }

    public static function publish(array $json)
    {
        $messages = [];
        $json['topic'] = self::textFirst($json['title'] ?? null);
        $lang = isset($json['lang']) ? (string)$json['lang'] : '';
        $seoCommonFileName = (string)Config::baseKey('seoCommonFileName', 'seocommon_poker_article_original.json');
        $siteConfigServerURL = (string)Config::baseKey('siteConfigServerURL', 'https://wptg.wptdata.com');

        $messages = array_merge($messages, self::saveSeoData($json));

        // $server = rtrim($siteConfigServerURL, '/') . '/hugo/keywordpost.php';
        // $messages[] = $lang . '_' . $seoCommonFileName . ' -> yes';
        // $res = self::uploadKeywordData($server, $lang . '_' . $seoCommonFileName, json_encode([$json]), 'url');
        // $messages[] = (string)$res;

        return $messages;
    }

    public static function saveSeoJsonFile(array $json)
    {
        $ctxId = isset($json['post_uuid']) ? (string)$json['post_uuid'] : '';
        if ($ctxId === '') {
            return null;
        }
        $seoJsonDir = Config::dataDir() . '/seodata/json';
        if (!is_dir($seoJsonDir)) {
            @mkdir($seoJsonDir, 0755, true);
        }
        $seoJsonFile = $seoJsonDir . '/' . $ctxId . '.json';
        file_put_contents($seoJsonFile, json_encode([$json], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $seoJsonFile;
    }

    public static function saveSeoData(array $json)
    {
        $messages = [];
        $ctxId = isset($json['post_uuid']) ? (string)$json['post_uuid'] : '';
        if ($ctxId !== '') {
            $seoJsonFile = self::saveSeoJsonFile($json);
            if ($seoJsonFile !== null) {
                $messages[] = 'seodata/json/' . $ctxId . '.json saved.';
            }
        }

        if (!empty($json['pubdomain'])) {
            // 主存 DB 索引，O(log n) 替代全量 JSON R/W
            $rep = self::datareportFormat($json);
            if (!empty($rep)) {
                \App\Database::execute(
                    'INSERT OR REPLACE INTO "aigc_status" ("ctx_id","keyword","lang","pubdomain","createAt","publishAt") VALUES (:c,:k,:l,:p,:ca,:pa)',
                    [':c'=>$rep['ctx_id'],':k'=>$rep['keyword'],':l'=>$rep['lang'],':p'=>$rep['pubdomain'],':ca'=>$rep['createAt'],':pa'=>$rep['publishAt']]
                );
            }
            // 异步刷新缓存文件供旧读端兼容
            self::refreshAigcStatusCache();
            $messages[] = 'aigc_status DB upserted for ' . implode(',', (array)$json['pubdomain']);
        }
        return $messages;
    }

    private static function refreshAigcStatusCache()
    {
        try {
            if (function_exists('fastcgi_finish_request')) {
                // 让上传响应先返回，后台再刷缓存
                fastcgi_finish_request();
            }
            \App\Repositories\AigcStatusRepository::writeCacheFile(\App\Config::dataDir() . '/seodata/aigc_status.json');
        } catch (\Throwable $e) {
            error_log('[saveSeoData] refresh cache failed: ' . $e->getMessage());
        }
    }

    public static function uploadKeywordData($url, $name, $json, $uniqkey)
    {
        $postData = [
            'name' => $name,
            'json' => $json,
            'uniq' => $uniqkey,
        ];
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($curl, CURLOPT_TIMEOUT, 20);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        $response = curl_exec($curl);
        if ($response === false) {
            $response = 'keywordpost error: ' . curl_error($curl);
        }
        return $response;
    }

    private static function localSaveAigcStatus($name, array $jsonDataList)
    {
        $dataDir = Config::dataDir();
        $reportList = [];
        foreach ($jsonDataList as $jsonData) {
            $report = self::datareportFormat($jsonData);
            if (!empty($report)) {
                $reportList[] = $report;
            }
        }
        $localJson = $dataDir . '/' . $name;
        $dir = dirname($localJson);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (file_exists($localJson)) {
            $oldJson = json_decode((string)file_get_contents($localJson), true);
            if (is_array($oldJson)) {
                $merged = array_merge(array_column($oldJson, null, 'ctx_id'), array_column($reportList, null, 'ctx_id'));
                $reportList = array_values($merged);
            }
        }
        $reportList = self::makeArrayUnique($reportList, 'ctx_id');
        file_put_contents($localJson, json_encode($reportList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $name . ' saved.';
    }

    private static function makeArrayUnique(array $array, $key)
    {
        $result = [];
        $temp = [];
        $hasKey = false;
        foreach ($array as $item) {
            if (!empty($item[$key])) {
                $lowercaseValue = strtolower((string)$item[$key]);
                if (!in_array($lowercaseValue, $temp, true)) {
                    $temp[] = $lowercaseValue;
                }
                $result[md5((string)$item[$key])] = $item;
                $hasKey = true;
            }
        }
        return $hasKey ? array_values($result) : array_values($array);
    }
}
