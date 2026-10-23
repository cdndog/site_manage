<?php

namespace App\Services;

use App\Config;
use App\Database;
use App\Repositories\TopicRepository;
use App\Support\Cache;

class RewriteService
{
    const STATUS = 'rewrite';

    const FIELDS = ['git_name', 'domain', 'keyword', 'pubdir', 'status', 'lang', 'geo', 'lasttask'];

    const SORTABLE = ['id', 'ctx_id', 'keyword', 'status', 'git_name', 'domain', 'pubdir', 'lang', 'geo', 'lasttask', 'time'];

    private static $sourceContentCache = null;

    public static function dataDir()
    {
        return Config::dataDir() . '/rewritedatas';
    }

    public static function jsonDir()
    {
        return self::dataDir() . '/json';
    }

    public static function defaultForm()
    {
        return [
            'ctx_id' => uuid(),
            'post_keyword' => '',
            'post_gitname' => '',
            'post_domain' => '',
            'post_lang' => 'zh',
            'post_geo' => 'CN',
            'post_pubdir' => 'article',
            'post_status' => self::STATUS,
            'post_lasttask' => '',
            'content' => '',
        ];
    }

    public static function sanitizePost(array $post)
    {
        array_walk($post, function (&$item) {
            if (is_array($item)) {
                $item = array_map(function ($value) {
                    return str_replace('|', '', trim((string)$value));
                }, $item);
                return;
            }
            $item = str_replace('|', '', trim((string)$item));
        });
        return $post;
    }

    public static function formFromRecord(array $record)
    {
        return [
            'ctx_id' => isset($record['ctx_id']) ? (string)$record['ctx_id'] : '',
            'post_keyword' => isset($record['keyword']) ? (string)$record['keyword'] : '',
            'post_gitname' => isset($record['git_name']) ? (string)$record['git_name'] : '',
            'post_domain' => isset($record['domain']) ? (string)$record['domain'] : '',
            'post_lang' => isset($record['lang']) ? (string)$record['lang'] : '',
            'post_geo' => isset($record['geo']) ? (string)$record['geo'] : '',
            'post_pubdir' => isset($record['pubdir']) ? (string)$record['pubdir'] : '',
            'post_status' => self::STATUS,
            'post_lasttask' => isset($record['lasttask']) ? (string)$record['lasttask'] : '',
            'content' => isset($record['content']) ? (string)$record['content'] : '',
        ];
    }

    public static function isValidCtxId($ctxId)
    {
        return is_string($ctxId) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $ctxId) === 1;
    }

    public static function buildRecord(array $post, ?array $existing = null)
    {
        $keyword = isset($post['post_keyword']) ? trim((string)$post['post_keyword']) : '';
        $gitName = isset($post['post_gitname']) ? trim((string)$post['post_gitname']) : '';
        if ($keyword === '' || $gitName === '') {
            return null;
        }
        $ctxId = isset($post['post_uuid']) ? trim((string)$post['post_uuid']) : '';
        if ($ctxId === '') {
            $ctxId = uuid();
        }
        if (!self::isValidCtxId($ctxId)) {
            return null;
        }
        $lasttask = isset($post['post_lasttask']) ? trim((string)$post['post_lasttask']) : '';
        if ($lasttask === '' && $existing !== null && isset($existing['lasttask'])) {
            $lasttask = (string)$existing['lasttask'];
        }
        return [
            'ctx_id' => $ctxId,
            'git_name' => $gitName,
            'domain' => isset($post['post_domain']) ? trim((string)$post['post_domain']) : '',
            'keyword' => $keyword,
            'pubdir' => isset($post['post_pubdir']) ? trim((string)$post['post_pubdir']) : '',
            'status' => self::STATUS,
            'lang' => isset($post['post_lang']) ? trim((string)$post['post_lang']) : '',
            'geo' => isset($post['post_geo']) ? trim((string)$post['post_geo']) : '',
            'lasttask' => $lasttask,
            'content' => array_key_exists('post_ckeditor_contents', $post)
                ? (string)$post['post_ckeditor_contents']
                : ($existing !== null && isset($existing['content']) ? (string)$existing['content'] : ''),
        ];
    }

    public static function recordFromData($ctxId, array $data)
    {
        $record = ['ctx_id' => (string)$ctxId];
        foreach (self::FIELDS as $field) {
            $record[$field] = isset($data[$field]) ? (string)$data[$field] : '';
        }
        $record['content'] = self::contentFromData($data);
        return $record;
    }

    public static function byCtxId($ctxId, ?array $sourceFiles = null)
    {
        if (!self::isValidCtxId($ctxId)) {
            return null;
        }
        TopicRepository::ensureTable();
        $row = TopicRepository::byCtxId($ctxId);
        if ($row === null || (string)$row['status'] !== self::STATUS) {
            return null;
        }
        $record = self::recordFromData($ctxId, $row);
        if (trim((string)$record['content']) === '') {
            $record['content'] = self::contentFromSource($ctxId, $sourceFiles);
        }
        return $record;
    }

    public static function search($search, $page, $perPage, $sort = 'ctx_id', $order = 'desc')
    {
        self::migrateLegacyFiles();
        TopicRepository::ensureTable();
        $search = trim((string)$search);
        $where = ' WHERE "status" = :status';
        $params = ['status' => self::STATUS];
        if ($search !== '') {
            $where .= ' AND ("keyword" LIKE :kw OR "domain" LIKE :kw OR "git_name" LIKE :kw OR "pubdir" LIKE :kw'
                . ' OR "lang" LIKE :kw OR "geo" LIKE :kw OR "lasttask" LIKE :kw OR "ctx_id" LIKE :kw)';
            $params['kw'] = '%' . $search . '%';
        }
        $orderBy = ' ORDER BY "id" DESC';
        if (in_array($sort, self::SORTABLE, true)) {
            $direction = strtolower((string)$order) === 'asc' ? 'ASC' : 'DESC';
            $orderBy = ' ORDER BY "' . $sort . '" ' . $direction . ', "id" DESC';
        }
        $page = max(1, (int)$page);
        $perPage = max(1, (int)$perPage);
        $offset = ($page - 1) * $perPage;
        $total = Database::fetchOne('SELECT COUNT(*) AS "c" FROM "sitetopic"' . $where, $params);
        $rows = Database::fetchAll(
            'SELECT "id", "ctx_id", "git_name", "domain", "keyword", "pubdir", "status", "lang", "geo", "lasttask", "time"'
            . ' FROM "sitetopic"' . $where . $orderBy . ' LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );
        return [
            'total' => isset($total['c']) ? (int)$total['c'] : 0,
            'rows' => $rows,
        ];
    }

    public static function all()
    {
        self::migrateLegacyFiles();
        TopicRepository::ensureTable();
        $rows = Database::fetchAll(
            'SELECT "id", "ctx_id", "git_name", "domain", "keyword", "pubdir", "status", "lang", "geo", "lasttask", "json", "time"'
            . ' FROM "sitetopic" WHERE "status" = :status ORDER BY "id" DESC',
            ['status' => self::STATUS]
        );
        foreach ($rows as &$row) {
            $row['content'] = self::contentFromData($row);
            if (trim((string)$row['content']) === '') {
                $row['content'] = self::contentFromSource((string)$row['ctx_id']);
            }
        }
        unset($row);
        return $rows;
    }

    public static function nextTask($query)
    {
        $query = trim((string)$query);
        if ($query === '') {
            return null;
        }
        $today = date('Ymd');
        $isAll = $query === 'all';
        $candidates = [];
        foreach (self::all() as $record) {
            if ((string)$record['status'] !== self::STATUS) {
                continue;
            }
            if ($isAll) {
                if ((string)$record['keyword'] === '') {
                    continue;
                }
            } elseif ((string)$record['lasttask'] === $today || !self::matchesTask($record, $query)) {
                continue;
            }
            $candidates[] = $record;
        }
        if (count($candidates) === 0) {
            return null;
        }
        return $candidates[array_rand($candidates)];
    }

    private static function matchesTask(array $record, string $needle): bool
    {
        if ((string)$record['ctx_id'] === $needle) {
            return true;
        }
        foreach (self::FIELDS as $field) {
            if ((string)$record[$field] === $needle) {
                return true;
            }
        }
        return stripos(self::jsonFor($record), $needle) !== false;
    }

    public static function saveRecord(array $record, $path = null)
    {
        TopicRepository::ensureTable();
        $ctxId = isset($record['ctx_id']) ? (string)$record['ctx_id'] : '';
        if (!self::isValidCtxId($ctxId)) {
            throw new \InvalidArgumentException('invalid ctx_id');
        }
        $data = [
            'git_name' => isset($record['git_name']) ? (string)$record['git_name'] : '',
            'domain' => isset($record['domain']) ? (string)$record['domain'] : '',
            'keyword' => isset($record['keyword']) ? (string)$record['keyword'] : '',
            'pubdir' => isset($record['pubdir']) ? (string)$record['pubdir'] : '',
            'status' => self::STATUS,
            'lang' => isset($record['lang']) ? (string)$record['lang'] : '',
            'geo' => isset($record['geo']) ? (string)$record['geo'] : '',
            'lasttask' => isset($record['lasttask']) ? (string)$record['lasttask'] : '',
        ];
        $content = isset($record['content']) ? (string)$record['content'] : '';
        $data['json'] = self::jsonFor($data + ['content' => $content]);
        $data['time'] = date('Y-m-d H:i:s');
        $existing = Database::fetchOne('SELECT "ctx_id" FROM "sitetopic" WHERE "ctx_id" = :ctx_id', ['ctx_id' => $ctxId]);
        if ($existing !== null) {
            $sets = [];
            foreach (array_keys($data) as $column) {
                $sets[] = '"' . $column . '" = :' . $column;
            }
            $data['ctx_id'] = $ctxId;
            Database::execute('UPDATE "sitetopic" SET ' . implode(', ', $sets) . ' WHERE "ctx_id" = :ctx_id', $data);
        } else {
            $columns = array_merge(['ctx_id'], array_keys($data));
            $placeholders = [];
            foreach ($columns as $column) {
                $placeholders[] = ':' . $column;
            }
            $data['ctx_id'] = $ctxId;
            Database::execute(
                'INSERT INTO "sitetopic" ("' . implode('", "', $columns) . '") VALUES (' . implode(', ', $placeholders) . ')',
                $data
            );
        }
        Cache::forget('topic:summarize');
        return $data + ['ctx_id' => $ctxId, 'content' => $content];
    }

    public static function createTask(array $post, array $article = [])
    {
        TopicRepository::ensureTable();
        if (isset($article[0]) && is_array($article[0])) {
            $article = $article[0];
        }
        $ctxId = self::firstNonEmpty([
            isset($post['post_uuid']) ? $post['post_uuid'] : '',
            isset($post['ctx_id']) ? $post['ctx_id'] : '',
            isset($article['post_uuid']) ? $article['post_uuid'] : '',
            isset($article['ctx_id']) ? $article['ctx_id'] : '',
        ]);
        if ($ctxId === '') {
            $ctxId = str_replace('.', '', uniqid((string)time(), true));
        }
        if (!self::isValidCtxId($ctxId)) {
            throw new \InvalidArgumentException('invalid ctx_id');
        }
        foreach (array_keys($article) as $key) {
            if (($key !== 'post_uuid' && strpos((string)$key, 'post_') === 0) || in_array($key, ['status', 'csrf_token', 'api_key', 'token', 'json', 'article', 'data', 'bulk'], true)) {
                unset($article[$key]);
            }
        }

        $title = self::firstText(isset($article['title']) ? $article['title'] : null);
        $keyword = self::firstNonEmpty([
            isset($post['post_keyword']) ? $post['post_keyword'] : '',
            isset($post['keyword']) ? $post['keyword'] : '',
            $title,
            isset($article['keyword']) ? $article['keyword'] : '',
            isset($article['url']) ? $article['url'] : '',
            $ctxId,
        ]);
        $gitName = self::firstNonEmpty([
            isset($post['post_gitname']) ? $post['post_gitname'] : '',
            isset($post['git_name']) ? $post['git_name'] : '',
            isset($article['git_name']) ? $article['git_name'] : '',
        ]);
        $domain = self::firstNonEmpty([
            isset($post['post_domain']) ? $post['post_domain'] : '',
            isset($post['domain']) ? $post['domain'] : '',
            isset($article['domain']) ? $article['domain'] : '',
        ]);
        $pubdir = self::firstNonEmpty([
            isset($post['post_pubdir']) ? $post['post_pubdir'] : '',
            isset($post['pubdir']) ? $post['pubdir'] : '',
            isset($article['pubdir']) ? $article['pubdir'] : '',
            'article',
        ]);
        $lang = self::firstNonEmpty([
            isset($post['post_lang']) ? $post['post_lang'] : '',
            isset($post['lang']) ? $post['lang'] : '',
            isset($article['lang']) ? $article['lang'] : '',
        ]);
        $geo = self::firstNonEmpty([
            isset($post['post_geo']) ? $post['post_geo'] : '',
            isset($post['geo']) ? $post['geo'] : '',
            isset($article['geo']) ? $article['geo'] : '',
        ]);
        if ($geo === '' && $lang !== '') {
            $geo = self::geoForLang($lang);
        }

        $articleContent = ArticleService::htmlFirst(isset($article['content']) ? $article['content'] : null);
        $content = '';
        foreach (['post_ckeditor_contents', 'content'] as $key) {
            if (array_key_exists($key, $post)) {
                $content = is_array($post[$key])
                    ? ArticleService::htmlFirst($post[$key])
                    : (string)$post[$key];
                if ($content !== '') {
                    break;
                }
            }
        }
        if ($content === '') {
            $content = $articleContent;
        }

        $existing = TopicRepository::byCtxId($ctxId);
        if ($content === '' && is_array($existing)) {
            $existingJson = json_decode((string)$existing['json'], true);
            if (is_array($existingJson)) {
                $content = ArticleService::htmlFirst(isset($existingJson['content']) ? $existingJson['content'] : null);
            }
        }

        $lasttask = self::firstNonEmpty([
            isset($post['post_lasttask']) ? $post['post_lasttask'] : '',
            isset($post['lasttask']) ? $post['lasttask'] : '',
            isset($article['lasttask']) ? $article['lasttask'] : '',
            is_array($existing) && isset($existing['lasttask']) ? $existing['lasttask'] : '',
        ]);

        if ($content !== '' && $articleContent === '') {
            $article['content'] = ['html' => [$content]];
        }
        if ($article === [] && $content !== '') {
            $article = [
                'post_uuid' => $ctxId,
                'title' => ['text' => [$keyword]],
                'content' => ['html' => [$content]],
            ];
        }

        $seoFile = '';
        if ($article !== []) {
            $article['post_uuid'] = $ctxId;
            if (ArticleService::saveSeoJsonFile($article) !== null) {
                $seoFile = 'seodata/json/' . $ctxId . '.json';
            }
        }

        $record = self::saveRecord([
            'ctx_id' => $ctxId,
            'git_name' => $gitName,
            'domain' => $domain,
            'keyword' => $keyword,
            'pubdir' => $pubdir,
            'lang' => $lang,
            'geo' => $geo,
            'lasttask' => $lasttask,
            'content' => $content,
        ]);

        return [
            'action' => $existing === null ? 'created' : 'updated',
            'seo_file' => $seoFile,
            'record' => $record,
        ];
    }

    public static function deleteByCtxId($ctxId)
    {
        if (!self::isValidCtxId($ctxId)) {
            return false;
        }
        TopicRepository::ensureTable();
        $deleted = Database::execute(
            'DELETE FROM "sitetopic" WHERE "ctx_id" = :ctx_id AND "status" = :status',
            ['ctx_id' => $ctxId, 'status' => self::STATUS]
        ) > 0;
        if ($deleted) {
            Cache::forget('topic:summarize');
        }
        return $deleted;
    }

    public static function markTaskDone(array $record, $path = null)
    {
        $ctxId = isset($record['ctx_id']) ? (string)$record['ctx_id'] : '';
        if (!self::isValidCtxId($ctxId)) {
            return false;
        }
        TopicRepository::ensureTable();
        $record['lasttask'] = date('Ymd');
        return Database::execute(
            'UPDATE "sitetopic" SET "lasttask" = :lasttask, "json" = :json, "time" = :time'
            . ' WHERE "ctx_id" = :ctx_id AND "status" = :status',
            [
                'lasttask' => $record['lasttask'],
                'json' => self::jsonFor($record),
                'time' => date('Y-m-d H:i:s'),
                'ctx_id' => $ctxId,
                'status' => self::STATUS,
            ]
        ) > 0;
    }

    public static function jsonFor(array $record)
    {
        $json = json_decode(TopicService::buildJson($record), true);
        if (!is_array($json)) {
            $json = [];
        }
        $json['content'] = ['html' => [isset($record['content']) ? (string)$record['content'] : '']];
        return json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function contentFromData(array $data)
    {
        if (array_key_exists('content', $data)) {
            return ArticleService::htmlFirst($data['content']);
        }
        if (isset($data['json'])) {
            $json = json_decode((string)$data['json'], true);
            if (is_array($json) && array_key_exists('content', $json)) {
                return ArticleService::htmlFirst($json['content']);
            }
        }
        return '';
    }

    /**
     * 已配置的改写文章来源列表（rewrite_files / rewrite_article_list）。
     * 旧记录在 content 支持上线前导入，json 中没有正文；编辑时从这里回填。
     */
    public static function sourceFiles()
    {
        $files = [];
        $configured = Config::baseKey('rewrite_files', []);
        if (is_string($configured)) {
            $configured = [$configured];
        }
        if (is_array($configured)) {
            foreach ($configured as $file) {
                $name = trim((string)$file);
                if ($name !== '') {
                    $files[] = $name;
                }
            }
        }
        $single = Config::baseKey('rewrite_article_list', '');
        if (is_string($single) && trim($single) !== '') {
            $files[] = trim($single);
        }
        return array_values(array_unique($files));
    }

    /**
     * 从指定文章列表文件读取某个 ctx_id 的 content.html。
     */
    public static function contentFromArticleFile($ctxId, $path)
    {
        if (!self::isValidCtxId($ctxId)) {
            return '';
        }
        $resolved = self::resolveImportPath($path);
        if ($resolved === null) {
            return '';
        }
        $handle = @fopen($resolved, 'rb');
        if ($handle === false) {
            return '';
        }
        try {
            while (($line = fgets($handle)) !== false) {
                $parsed = self::contentFromSourceLine($line);
                if ($parsed !== null && $parsed[0] === (string)$ctxId && $parsed[1] !== '') {
                    return $parsed[1];
                }
            }
        } finally {
            fclose($handle);
        }
        return '';
    }

    /**
     * 从配置的文章列表回填缺失正文。结果按请求缓存，避免逐条重复扫描文件。
     */
    public static function contentFromSource($ctxId, ?array $files = null)
    {
        if (!self::isValidCtxId($ctxId)) {
            return '';
        }
        $map = self::sourceContentMap($files);
        return isset($map[(string)$ctxId]) ? $map[(string)$ctxId] : '';
    }

    private static function sourceContentMap(?array $files = null)
    {
        if ($files === null && self::$sourceContentCache !== null) {
            return self::$sourceContentCache;
        }
        $map = [];
        $candidates = $files === null ? self::sourceFiles() : $files;
        foreach ($candidates as $file) {
            $resolved = self::resolveImportPath($file);
            if ($resolved === null) {
                continue;
            }
            $handle = @fopen($resolved, 'rb');
            if ($handle === false) {
                continue;
            }
            try {
                while (($line = fgets($handle)) !== false) {
                    $parsed = self::contentFromSourceLine($line);
                    if ($parsed === null || $parsed[1] === '' || isset($map[$parsed[0]])) {
                        continue;
                    }
                    $map[$parsed[0]] = $parsed[1];
                }
            } finally {
                fclose($handle);
            }
        }
        if ($files === null) {
            self::$sourceContentCache = $map;
        }
        return $map;
    }

    private static function contentFromSourceLine($line)
    {
        $line = trim((string)$line);
        if ($line === '') {
            return null;
        }
        $parts = explode('|', $line, 3);
        if (count($parts) !== 3) {
            return null;
        }
        $ctxId = self::clean($parts[0]);
        if (!self::isValidCtxId($ctxId)) {
            return null;
        }
        $third = trim($parts[2]);
        $json = null;
        if ($third !== '' && $third[0] === '{') {
            $json = json_decode($third, true);
        } elseif ($third !== '') {
            $jsonFile = self::resolveImportPath($third);
            if ($jsonFile !== null) {
                $json = json_decode((string)@file_get_contents($jsonFile), true);
            }
        }
        if (!is_array($json) || !array_key_exists('content', $json)) {
            return null;
        }
        return [$ctxId, (string)ArticleService::htmlFirst($json['content'])];
    }

    public static function resolveImportPath($path)
    {
        $path = trim((string)$path, " \t\n\r\0\x0B\"'");
        if ($path === '') {
            return null;
        }
        $candidates = [$path, APP_PATH . '/' . ltrim($path, '/')];
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private static function firstNonEmpty(array $values)
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                $value = self::firstText($value);
            }
            $value = trim((string)$value);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private static function firstText($value)
    {
        if (is_string($value) || is_numeric($value)) {
            return trim((string)$value);
        }
        if (!is_array($value)) {
            return '';
        }
        if (isset($value['text'])) {
            return self::firstText($value['text']);
        }
        if (isset($value['html'])) {
            return self::firstText($value['html']);
        }
        foreach ($value as $item) {
            $text = self::firstText($item);
            if ($text !== '') {
                return $text;
            }
        }
        return '';
    }

    private static function clean($value)
    {
        return trim(str_replace(['|', "\r", "\n"], ['', ' ', ' '], (string)$value));
    }

    private static function geoForLang($lang)
    {
        $map = [
            'en' => 'US', 'ja' => 'JP', 'zh' => 'CN', 'tw' => 'TW', 'hk' => 'HK',
            'es' => 'MX', 'ko' => 'KR', 'ar' => 'AR', 'ru' => 'RU', 'fr' => 'FR',
            'pt' => 'BR', 'bn' => 'BD', 'ur' => 'PK', 'de' => 'DE', 'sv' => 'SE',
            'vi' => 'VN', 'tr' => 'TR',
        ];
        $key = strtolower(trim((string)$lang));
        if (isset($map[$key])) {
            return $map[$key];
        }
        if (preg_match('/[-_]([a-z]{2})$/', $key, $m) === 1) {
            return strtoupper($m[1]);
        }
        return strtoupper($key);
    }

    private static function matchSiteGitName($domain, array $sites)
    {
        $host = strtolower((string)$domain);
        $bare = preg_replace('/^www\./', '', $host);
        foreach ($sites as $site) {
            $siteDomain = strtolower((string)(isset($site['domain']) ? $site['domain'] : ''));
            $siteBare = preg_replace('/^www\./', '', $siteDomain);
            if ($siteDomain !== '' && ($siteDomain === $host || $siteBare === $bare)) {
                return isset($site['git_name']) ? (string)$site['git_name'] : '';
            }
        }
        return '';
    }

    public static function siteLanguageGeo(array $site)
    {
        $lang = self::clean(isset($site['languages']) ? $site['languages'] : '');
        if (strpos($lang, ',') !== false) {
            $lang = trim((string)explode(',', $lang, 2)[0]);
        }
        $geo = self::clean(isset($site['geo']) ? $site['geo'] : '');
        if ($geo === '' && isset($site['json'])) {
            $siteJson = json_decode((string)$site['json'], true);
            if (is_array($siteJson)) {
                $geo = self::clean(isset($siteJson['geo']) ? $siteJson['geo'] : (isset($siteJson['country']) ? $siteJson['country'] : ''));
            }
        }
        if ($geo === '' && $lang !== '') {
            $geo = self::geoForLang($lang);
        }
        return [$lang, $geo];
    }

    public static function importFromArticleList($path, array $sites = [], $publishDomain = '', $importLang = '', $importGeo = '')
    {
        self::$sourceContentCache = null;
        $importLang = self::clean($importLang);
        $importGeo = self::clean($importGeo);
        $result = [
            'file' => (string)$path,
            'total' => 0,
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ];
        $publishDomain = self::clean($publishDomain);
        $publishSite = null;
        if ($publishDomain !== '') {
            foreach ($sites as $site) {
                if (strcasecmp(self::clean(isset($site['domain']) ? $site['domain'] : ''), $publishDomain) === 0) {
                    $publishSite = $site;
                    break;
                }
            }
            if ($publishSite === null) {
                $result['error'] = '发布域名不存在于 siteops：' . $publishDomain;
                return $result;
            }
            $result['publish_domain'] = self::clean(isset($publishSite['domain']) ? $publishSite['domain'] : '');
            $result['publish_git_name'] = self::clean(isset($publishSite['git_name']) ? $publishSite['git_name'] : '');
            list($publishLang, $publishGeo) = self::siteLanguageGeo($publishSite);
        } else {
            $publishLang = '';
            $publishGeo = '';
        }
        $resolved = self::resolveImportPath($path);
        if ($resolved === null) {
            $result['error'] = '文件不存在或不可读：' . (string)$path;
            return $result;
        }
        $result['file'] = $resolved;
        $result['import_lang'] = $importLang;
        $result['import_geo'] = $importGeo;
        $lines = file($resolved, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            $result['error'] = '文件读取失败：' . $resolved;
            return $result;
        }
        $seen = [];
        foreach ($lines as $lineNo => $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }
            $result['total']++;
            $parts = explode('|', $line, 3);
            if (count($parts) !== 3) {
                $result['failed']++;
                $result['errors'][] = '第 ' . ($lineNo + 1) . ' 行不是 ctx_id|url|json 格式';
                continue;
            }
            $ctxId = self::clean($parts[0]);
            $url = self::clean($parts[1]);
            $third = trim($parts[2]);
            $json = null;
            if ($third !== '' && $third[0] === '{') {
                $json = json_decode($third, true);
            } elseif ($third !== '') {
                $jsonFile = self::resolveImportPath($third);
                if ($jsonFile !== null) {
                    $json = json_decode((string)@file_get_contents($jsonFile), true);
                }
            }
            if (!is_array($json)) {
                $result['failed']++;
                $result['errors'][] = '第 ' . ($lineNo + 1) . ' 行 JSON 解析失败';
                continue;
            }
            if ($ctxId === '') {
                $ctxId = self::clean(isset($json['post_uuid']) ? $json['post_uuid'] : '');
            }
            if ($ctxId === '') {
                $ctxId = uuid();
            }
            if (!self::isValidCtxId($ctxId)) {
                $result['failed']++;
                $result['errors'][] = '第 ' . ($lineNo + 1) . ' 行 ctx_id 非法';
                continue;
            }
            if (isset($seen[$ctxId])) {
                $result['skipped']++;
                continue;
            }
            $seen[$ctxId] = true;
            TopicRepository::ensureTable();
            $existing = TopicRepository::byCtxId($ctxId);
            if ($existing !== null && (string)$existing['status'] !== self::STATUS) {
                $result['skipped']++;
                continue;
            }

            $title = self::clean(self::firstText(isset($json['title']) ? $json['title'] : null));
            $keyword = $title !== '' ? $title : $url;
            if ($keyword === '') {
                $result['failed']++;
                $result['errors'][] = '第 ' . ($lineNo + 1) . ' 行缺少标题与 URL';
                continue;
            }
            if ($url === '') {
                $url = self::clean(isset($json['url']) ? $json['url'] : '');
            }
            $host = $url !== '' ? (string)parse_url($url, PHP_URL_HOST) : '';
            if ($host === '' && isset($json['pubdomain']) && is_array($json['pubdomain'])) {
                foreach ($json['pubdomain'] as $candidate) {
                    $candidate = self::clean($candidate);
                    if ($candidate !== '' && strtolower($candidate) !== 'global') {
                        $host = $candidate;
                        break;
                    }
                }
            }
            $lang = $importLang !== ''
                ? $importLang
                : self::clean(isset($json['lang']) ? $json['lang'] : '');
            if ($importGeo !== '') {
                $geo = $importGeo;
            } elseif ($importLang !== '') {
                $geo = self::geoForLang($importLang);
            } else {
                $geo = self::clean(isset($json['geo']) ? $json['geo'] : '');
            }
            if ($lang === '' && $publishLang !== '') {
                $lang = $publishLang;
            }
            if ($geo === '' && $publishGeo !== '') {
                $geo = $publishGeo;
            }
            if ($geo === '' && $lang !== '') {
                $geo = self::geoForLang($lang);
            }
            $lasttask = self::clean(isset($json['lasttask']) ? $json['lasttask'] : '');
            if ($lasttask === '' && $existing !== null) {
                $lasttask = self::clean(isset($existing['lasttask']) ? $existing['lasttask'] : '');
            }
            $content = null;
            if (array_key_exists('content', $json)) {
                $content = ArticleService::htmlFirst($json['content']);
            } elseif ($existing !== null) {
                $content = self::contentFromData($existing);
            }
            if ($content === null) {
                $content = '';
            }
            $record = [
                'ctx_id' => $ctxId,
                'git_name' => $publishSite !== null ? self::clean(isset($publishSite['git_name']) ? $publishSite['git_name'] : '') : self::matchSiteGitName($host, $sites),
                'domain' => $publishSite !== null ? self::clean(isset($publishSite['domain']) ? $publishSite['domain'] : '') : $host,
                'keyword' => $keyword,
                'pubdir' => self::clean(isset($json['pubdir']) ? $json['pubdir'] : ''),
                'status' => self::STATUS,
                'lang' => $lang,
                'geo' => $geo,
                'lasttask' => $lasttask,
                'content' => $content,
            ];
            try {
                self::saveRecord($record);
                if ($existing !== null) {
                    $result['updated']++;
                } else {
                    $result['imported']++;
                }
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['errors'][] = '第 ' . ($lineNo + 1) . ' 行保存失败：' . $e->getMessage();
            }
        }
        return $result;
    }

    public static function migrateLegacyFiles()
    {
        $dir = self::dataDir();
        if (!is_dir($dir)) {
            return;
        }
        $marker = $dir . '/.migrated-to-sitetopic';
        if (is_file($marker)) {
            return;
        }
        $dirs = [$dir, self::jsonDir()];
        foreach ($dirs as $current) {
            if (!is_dir($current)) {
                continue;
            }
            foreach (new \DirectoryIterator($current) as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'json') {
                    continue;
                }
                $ctxId = $file->getBasename('.json');
                if (!self::isValidCtxId($ctxId)) {
                    continue;
                }
                TopicRepository::ensureTable();
                if (TopicRepository::byCtxId($ctxId) !== null) {
                    continue;
                }
                $data = json_decode((string)@file_get_contents($file->getPathname()), true);
                if (!is_array($data)) {
                    continue;
                }
                $record = self::recordFromData($ctxId, $data);
                if ($record['keyword'] === '') {
                    continue;
                }
                try {
                    self::saveRecord($record);
                } catch (\Throwable $e) {
                }
            }
        }
        @file_put_contents($marker, date('c'));
    }
}
