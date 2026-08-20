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

    public static function buildJson(array $post)
    {
        $base = [];
        if (isset($post['post_json']) && $post['post_json'] !== '') {
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

        $json = $base;
        $json['pubdir'] = isset($post['post_pubdir']) ? trim($post['post_pubdir']) : '';
        $json['createAt']['text'][0] = (int)$uuidTs;
        $json['update_date'] = date('Y-m-d\TH:i:s\Z');
        $json['title']['text'][] = isset($post['post_title']) ? trim($post['post_title']) : '';
        $json['url'] = $url;
        $json['lang'] = isset($post['post_lang']) ? trim($post['post_lang']) : '';
        $json['static_thumbnail']['text'][] = isset($post['post_static_thumbnail']) ? trim($post['post_static_thumbnail']) : '';
        $json['iframesrc']['text'] = isset($post['post_iframesrc']) && trim($post['post_iframesrc']) !== ''
            ? array_values(array_filter(array_map('trim', explode(',', (string)$post['post_iframesrc'])), function ($item) {
                return $item !== '';
            }))
            : [];
        $json['series'] = isset($post['post_series']) ? trim($post['post_series']) : '';
        $json['tags']['text'] = isset($post['post_tag']) && trim($post['post_tag']) !== ''
            ? array_values(array_filter(array_map('trim', explode(',', (string)$post['post_tag'])), function ($item) {
                return $item !== '';
            }))
            : [];
        $json['keywords']['text'] = isset($post['post_keyword']) && trim($post['post_keyword']) !== ''
            ? array_values(array_filter(array_map('trim', explode(',', (string)$post['post_keyword'])), function ($item) {
                return $item !== '';
            }))
            : [];
        $json['description']['text'][] = isset($post['post_description']) ? trim($post['post_description']) : '';
        $json['savename']['text'][] = isset($post['post_savename']) ? trim($post['post_savename']) : '';
        $json['slug']['text'][] = isset($post['post_savename']) ? trim($post['post_savename']) : '';
        $json['globalpublish'] = isset($post['post_globalpublish']) && trim($post['post_globalpublish']) !== '' ? trim($post['post_globalpublish']) : 'no';
        $json['pubdomain'] = isset($post['post_pubdomain']) && is_array($post['post_pubdomain'])
            ? array_values(array_filter($post['post_pubdomain'], function ($item) {
                return trim((string)$item) !== '';
            }))
            : [];
        $json['translate_to_langs'] = isset($post['post_translate_to_langs']) && is_array($post['post_translate_to_langs'])
            ? array_values(array_filter($post['post_translate_to_langs'], function ($item) {
                return trim((string)$item) !== '';
            }))
            : [];
        $json['content']['html'][] = isset($post['post_ckeditor_contents']) ? (string)$post['post_ckeditor_contents'] : '';
        $json['upload_site'] = (string)Config::baseKey('siteConfigServerURL', 'https://wptg.wptdata.com');
        $json['post_uuid'] = $ctxId;
        return $json;
    }

    public static function jsonFileName($ctxId)
    {
        return 'json/' . $ctxId . '.json';
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
                    $result['rows'][] = ['ok' => false, 'missing' => true, 'message' => 'JSON 文件缺失，跳过', 'ctx_id' => $ctxId];
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
            if (ArticleRepository::byCtxId($ctxId) !== null) {
                $result['skipped']++;
                $result['rows'][] = ['ok' => false, 'skipped' => true, 'message' => '已存在，跳过', 'ctx_id' => $ctxId, 'title' => isset($json['title']['text'][0]) ? (string)$json['title']['text'][0] : ''];
                continue;
            }
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
            ArticleRepository::upsertByCtxId($record);
            self::saveSeoData($json);
            $result['imported']++;
            $result['rows'][] = ['ok' => true, 'message' => '已导入', 'ctx_id' => $ctxId, 'title' => $record['title']];
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
            'title' => isset($json['title']['text'][0]) ? (string)$json['title']['text'][0] : '',
            'keyword' => isset($json['keywords']['text']) ? implode(',', $json['keywords']['text']) : '',
            'tags' => isset($json['tags']['text']) ? implode(',', $json['tags']['text']) : '',
            'description' => isset($json['description']['text'][0]) ? (string)$json['description']['text'][0] : '',
            'static_thumbnail' => isset($json['static_thumbnail']['text'][0]) ? (string)$json['static_thumbnail']['text'][0] : '',
            'iframesrc' => isset($json['iframesrc']['text']) ? implode(',', $json['iframesrc']['text']) : '',
            'lang' => isset($json['lang']) ? (string)$json['lang'] : '',
            'series' => isset($json['series']) ? (string)$json['series'] : '',
            'pubdir' => isset($json['pubdir']) ? (string)$json['pubdir'] : '',
            'savename' => isset($json['savename']['text'][0]) ? (string)$json['savename']['text'][0] : '',
            'globalpublish' => isset($json['globalpublish']) ? (string)$json['globalpublish'] : 'no',
            'pubdomain' => isset($json['pubdomain']) && is_array($json['pubdomain']) ? implode(',', $json['pubdomain']) : '',
            'translate_to_langs' => isset($json['translate_to_langs']) && is_array($json['translate_to_langs']) ? implode(',', $json['translate_to_langs']) : '',
            'content' => isset($json['content']['html'][0]) ? (string)$json['content']['html'][0] : '',
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
        $output['keyword'] = isset($json['topic']) ? (string)$json['topic'] : '';
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
        $json['topic'] = isset($json['title']['text'][0]) ? (string)$json['title']['text'][0] : '';
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

    public static function saveSeoData(array $json)
    {
        $messages = [];
        $ctxId = isset($json['post_uuid']) ? (string)$json['post_uuid'] : '';
        if ($ctxId !== '') {
            $seoDataDir = Config::dataDir() . '/seodata';
            if (!is_dir($seoDataDir)) {
                @mkdir($seoDataDir, 0755, true);
            }
            $seoJsonDir = $seoDataDir . '/json';
            if (!is_dir($seoJsonDir)) {
                @mkdir($seoJsonDir, 0755, true);
            }
            $seoJsonFile = $seoJsonDir . '/' . $ctxId . '.json';
            file_put_contents($seoJsonFile, json_encode([$json], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $messages[] = 'seodata/json/' . $ctxId . '.json saved.';
        }

        if (!empty($json['pubdomain'])) {
            $indexFile = 'seodata/aigc_status.json';
            $res = self::localSaveAigcStatus($indexFile, [$json]);
            $messages[] = $indexFile . ' -> ' . implode(',', $json['pubdomain']) . ' (' . $res . ')';
        }
        return $messages;
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
