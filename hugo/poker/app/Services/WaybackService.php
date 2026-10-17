<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Wayback Machine（archive.org 时光机）查询服务。
 *
 * 依据官方 wayback-cdx-server API（https://github.com/internetarchive/wayback/tree/master/wayback-cdx-server）：
 *  - 完整快照抓取：matchType=domain + limit=150000 + fl + filter=statuscode:200
 *  - 分页统计：showNumPages=true&pageSize=1
 *  - 快照地址：web.archive.org/web/<timestamp>id_/<original>
 *
 * 代理：显式代理地址（配置预设/手工录入）优先；未指定时走「自动」模式取环境变量
 * （WAYBACK_PROXY / APP_WAYBACK_PROXY / HTTPS_PROXY / https_proxy / HTTP_PROXY / http_proxy）。
 * SOPS_TESTING 下可用 $GLOBALS['__waybackHttpStub']（url 前缀 => 响应体）离线注入，便于测试。
 */
class WaybackService
{
    const CDX_API = 'https://web.archive.org/cdx/search/cdx';
    const ARCHIVE = 'https://web.archive.org/web';
    const UA = 'Mozilla/5.0 (compatible; wayback-tools/1.0)';

    const FL = 'timestamp,original,statuscode,length,mimetype';

    /** 单次尝试默认超时（秒）；配合控制器 set_time_limit 使用 */
    const TIMEOUT = 15;
    const PAGES_TIMEOUT = 10;
    const SNAPSHOTS_TIMEOUT = 45;
    const TITLE_TIMEOUT = 25;
    const PROXY_TEST_TIMEOUT = 4;
    /** 代理连通性测试端点：轻量外网探测（返回 204 No Content），不依赖 archive.org 可用性 */
    const PROXY_TEST_URL = 'https://www.google.com/generate_204';

    /** 自动模式代理地址：'' 表示未配置（仅直连）。取环境变量。 */
    public static function proxy(): string
    {
        foreach (['WAYBACK_PROXY', 'APP_WAYBACK_PROXY', 'HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy'] as $c) {
            $v = trim((string)getenv($c));
            if ($v !== '') {
                return $v;
            }
        }
        return '';
    }

    /** 单次 curl 尝试（$proxy 非空走该代理）；返回 ['ok','body','code','errno','error'] */
    private static function curlOnce(string $url, int $timeout, string $proxy = ''): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'body' => '', 'code' => 0, 'errno' => -1, 'error' => 'curl 扩展不可用'];
        }
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($proxy !== '') {
            $options[CURLOPT_PROXY] = $proxy;
        }
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $info = [
            'ok' => $body !== false,
            'body' => (string)$body,
            'code' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'errno' => (int)curl_errno($ch),
            'error' => (string)curl_error($ch),
        ];
        return $info;
    }

    /**
     * HTTP GET：自动重试 + 代理回退。
     *  - $proxyOverride 非空：强制全部走该代理；
     *  - $force=true 且未指定代理：仅直连（不走环境代理）；
     *  - 默认自动：代理优先（本环境直连常被墙，代理优先降低等待）→ 直连兜底；无代理仅直连一次。
     */
    public static function httpGet(string $url, int $timeout = self::TIMEOUT, string $proxyOverride = '', bool $force = false): string
    {
        // 测试注入：SOPS_TESTING 下按 url 前缀命中 stub，直接返回响应体（不走网络）
        if (defined('SOPS_TESTING') && isset($GLOBALS['__waybackHttpStub']) && is_array($GLOBALS['__waybackHttpStub'])) {
            foreach ($GLOBALS['__waybackHttpStub'] as $prefix => $body) {
                if (strpos($url, (string)$prefix) === 0) {
                    return (string)$body;
                }
            }
            throw new \RuntimeException('wayback http stub 未覆盖该请求: ' . $url);
        }

        $effective = $proxyOverride !== '' ? $proxyOverride : self::proxy();
        if ($proxyOverride !== '') {
            $routes = ['proxy', 'proxy'];
        } elseif ($force) {
            $routes = ['direct', 'direct'];
        } else {
            $routes = $effective !== '' ? ['proxy', 'direct'] : ['direct'];
        }
        $lastErr = '';
        foreach ($routes as $i => $route) {
            $r = self::curlOnce($url, $timeout, $route === 'proxy' ? $effective : '');
            if ($r['ok'] && $r['code'] >= 200 && $r['code'] < 400) {
                return $r['body'];
            }
            $lastErr = $r['error'] !== '' ? $r['error'] : ('HTTP ' . $r['code']);
            if ($i < count($routes) - 1) {
                usleep(200_000 + random_int(0, 300_000));
            }
        }
        throw new \RuntimeException('HTTP GET 失败: ' . $lastErr);
    }

    /** 解析 CDX JSON 响应体为行数组（首行为列名）；非法返回空数组 */
    public static function parseCdx(string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data) || $data === []) {
            return [];
        }
        $header = array_shift($data);
        if (!is_array($header)) {
            return [];
        }
        $col = array_flip($header);
        $rows = [];
        foreach ($data as $r) {
            if (!is_array($r)) {
                continue;
            }
            $get = function ($k) use ($r, $col) {
                return isset($col[$k]) && isset($r[$col[$k]]) ? (string)$r[$col[$k]] : '';
            };
            $rows[] = [
                'timestamp' => $get('timestamp'),
                'original' => $get('original'),
                'statuscode' => $get('statuscode'),
                'length' => $get('length'),
                'mimetype' => $get('mimetype'),
            ];
        }
        return $rows;
    }

    /**
     * 完整抓取某域名全部 HTTP 200 快照（limit ≤ 服务端上限 150000）。
     * 返回 ['ok','rows','error']；rows 为 [timestamp, original, statuscode] 三元组列表。
     */
    public static function cdxSnapshots(string $domain, string $proxy = '', bool $force = false): array
    {
        $domain = trim($domain);
        if ($domain === '') {
            return ['ok' => false, 'rows' => [], 'error' => '域名不能为空'];
        }
        $q = self::CDX_API . '?url=' . rawurlencode($domain)
            . '&matchType=domain&limit=150000&fl=' . rawurlencode('timestamp,original,statuscode')
            . '&filter=' . rawurlencode('statuscode:200') . '&output=json&gzip=false';
        try {
            $body = self::httpGet($q, self::SNAPSHOTS_TIMEOUT, $proxy, $force);
        } catch (\Throwable $e) {
            return ['ok' => false, 'rows' => [], 'error' => $e->getMessage()];
        }
        $rows = self::parseCdxTriples($body);
        return ['ok' => true, 'rows' => $rows, 'error' => ''];
    }

    /** 解析 CDX 响应为 [ts,url,status] 三元组；JSON 失败时回退文本解析（首字段 14 位时间戳） */
    public static function parseCdxTriples(string $body): array
    {
        $rows = [];
        $data = json_decode($body, true);
        if (is_array($data) && $data) {
            $header = array_shift($data);
            $col = is_array($header) ? array_flip($header) : ['timestamp' => 0, 'original' => 1, 'statuscode' => 2];
            $iTs = $col['timestamp'] ?? 0;
            $iUrl = $col['original'] ?? 1;
            $iSt = $col['statuscode'] ?? 2;
            foreach ($data as $r) {
                if (!is_array($r) || count($r) < 2) {
                    continue;
                }
                $ts = (string)($r[$iTs] ?? '');
                $orig = (string)($r[$iUrl] ?? '');
                if ($ts === '' || $orig === '') {
                    continue;
                }
                $rows[] = [$ts, $orig, (string)($r[$iSt] ?? '')];
            }
            return $rows;
        }
        $text = trim($body);
        if ($text === '') {
            return $rows;
        }
        $firstLine = (string)preg_split('/\r?\n/', $text)[0];
        if (preg_match('/^\d{14}\s+\S/', $firstLine)) {
            foreach (preg_split('/\r?\n/', $text) as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) >= 2) {
                    $rows[] = [$parts[0], $parts[1], $parts[2] ?? ''];
                }
            }
        }
        return $rows;
    }

    /**
     * 选取首页快照（URL 以 / 结尾或无路径），默认每年一个，$all=true 时全部。
     * @param array<int,array> $rows [ts,url,status] 列表
     * @return array<int,array> 同结构子集
     */
    public static function selectHomepages(array $rows, bool $all): array
    {
        $selected = [];
        $seenYear = [];
        foreach ($rows as $r) {
            $ts = (string)($r[0] ?? '');
            $url = (string)($r[1] ?? '');
            if (!preg_match('#^https?://[^/]+/?$#', $url)) {
                continue; // 非首页
            }
            $year = substr($ts, 0, 4);
            if ($all || !isset($seenYear[$year])) {
                $seenYear[$year] = true;
                $selected[] = $r;
            }
        }
        return $selected;
    }

    /** 提取 <title>，兼容 utf-8 / gb18030 / gbk（先校验 UTF-8 合法性，避免 GBK 被误判） */
    public static function extractTitle(string $raw): string
    {
        $text = null;
        if (mb_check_encoding($raw, 'UTF-8')) {
            $text = $raw;
        } else {
            foreach (['GB18030', 'GBK'] as $enc) {
                $decoded = @mb_convert_encoding($raw, 'UTF-8', $enc);
                if ($decoded !== false && $decoded !== '' && mb_check_encoding($decoded, 'UTF-8') && strpos($decoded, "\xEF\xBF\xBD") === false) {
                    $text = $decoded;
                    break;
                }
            }
        }
        if ($text === null) {
            return '(undecodable)';
        }
        if (!preg_match('/<title[^>]*>(.*?)<\/title>/is', $text, $m)) {
            return '(no title tag)';
        }
        $t = preg_replace('/\s+/', ' ', trim($m[1]));
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $t === '' ? '(no title tag)' : $t;
    }

    /**
     * 并行抓取多个快照标题（curl_multi 并发 + 重试退避）。
     * @param array<int,array> $items [ts,url] 列表
     * @return array<int,string> 与 $items 同序的标题
     */
    public static function fetchTitlesMulti(array $items, int $workers = 5, int $deadline = 70, string $proxy = '', bool $force = false): array
    {
        $n = count($items);
        if ($n === 0) {
            return [];
        }
        // 测试注入：SOPS_TESTING 下走 stub（顺序 httpGet），不实际并发
        if (defined('SOPS_TESTING') && isset($GLOBALS['__waybackHttpStub']) && is_array($GLOBALS['__waybackHttpStub'])) {
            $results = [];
            foreach ($items as $it) {
                $ts = (string)($it[0] ?? '');
                $url = (string)($it[1] ?? '');
                $arc = self::ARCHIVE . '/' . $ts . 'id_/' . $url;
                try {
                    $body = self::httpGet($arc, self::TITLE_TIMEOUT, $proxy, $force);
                    $results[] = strlen($body) > 100 ? self::extractTitle($body) : '(empty response)';
                } catch (\Throwable $e) {
                    $results[] = '(empty response)';
                }
            }
            return $results;
        }

        if (!function_exists('curl_multi_init')) {
            // 无 curl_multi 扩展：退化为顺序抓取
            $results = [];
            foreach ($items as $it) {
                try {
                    $body = self::httpGet(self::ARCHIVE . '/' . $it[0] . 'id_/' . $it[1], self::TITLE_TIMEOUT, $proxy, $force);
                    $results[] = strlen($body) > 100 ? self::extractTitle($body) : '(empty response)';
                } catch (\Throwable $e) {
                    $results[] = '(empty response)';
                }
            }
            return $results;
        }

        $results = array_fill(0, $n, '(empty response)');
        $attempts = array_fill(0, $n, 1);
        $need = [];
        $handles = [];
        $effective = $proxy !== '' ? $proxy : self::proxy();
        $handleOpts = function (int $attempt) use ($proxy, $force, $effective) {
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => self::TITLE_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_USERAGENT => self::UA,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_ENCODING => '',
            ];
            $useProxy = $proxy !== '' ? true : ($force ? false : ($effective !== '' && $attempt % 2 === 0));
            if ($useProxy && $effective !== '') {
                $opts[CURLOPT_PROXY] = $effective;
            }
            return $opts;
        };

        $mh = curl_multi_init();
        $started = microtime(true);
        $running = 0;
        foreach ($items as $i => $it) {
            if (count($handles) >= $workers) {
                break;
            }
            $need[$i] = false;
            $handles[$i] = curl_init(self::ARCHIVE . '/' . $it[0] . 'id_/' . $it[1]);
            curl_setopt_array($handles[$i], $handleOpts($attempts[$i]));
            curl_multi_add_handle($mh, $handles[$i]);
        }

        do {
            if ($deadline > 0 && microtime(true) - $started > $deadline) {
                foreach ($handles as $h) {
                    curl_multi_remove_handle($mh, $h);
                }
                $handles = [];
                $need = [];
                break;
            }
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
            while (($info = curl_multi_info_read($mh)) !== false) {
                $i = array_search($info['handle'], $handles, true);
                if ($i === false) {
                    continue;
                }
                $code = curl_getinfo($handles[$i], CURLINFO_RESPONSE_CODE);
                curl_multi_remove_handle($mh, $handles[$i]);
                $content = curl_multi_getcontent($handles[$i]);
                unset($handles[$i]);
                if ($info['result'] === 0 && $code >= 200 && $code < 300 && strlen((string)$content) > 100) {
                    $results[$i] = self::extractTitle((string)$content);
                    unset($need[$i]);
                } else {
                    $attempts[$i]++;
                    if ($attempts[$i] > 3) {
                        unset($need[$i]);
                    } else {
                        $need[$i] = true;
                    }
                }
            }
            foreach ($need as $i => $v) {
                if ($v === false || count($handles) >= $workers) {
                    continue;
                }
                $need[$i] = false;
                $handles[$i] = curl_init(self::ARCHIVE . '/' . $items[$i][0] . 'id_/' . $items[$i][1]);
                curl_setopt_array($handles[$i], $handleOpts($attempts[$i]));
                curl_multi_add_handle($mh, $handles[$i]);
            }
        } while ($running > 0 || count($handles) > 0);

        if (PHP_VERSION_ID < 80000) {
            curl_multi_close($mh);
        }
        return $results;
    }

    /** 通过代理请求外网轻量端点，验证代理能否访问外部网络；$proxyAddr 为空时用环境代理/直连测试；返回 ['ok','msg','code','elapsed','bytes'] */
    public static function proxyTest(string $proxyAddr): array
    {
        $proxyAddr = trim($proxyAddr);
        $effective = $proxyAddr !== '' ? $proxyAddr : self::proxy();
        $label = $proxyAddr !== '' ? self::proxyDisplay($proxyAddr) : '自动' . ($effective !== '' ? '（' . self::proxyDisplay($effective) . '）' : '（直连）');
        $t0 = microtime(true);
        try {
            $body = self::httpGet(self::PROXY_TEST_URL, self::PROXY_TEST_TIMEOUT, $effective);
        } catch (\Throwable $e) {
            $elapsed = round(microtime(true) - $t0, 2);
            return ['ok' => false, 'msg' => '访问外网失败（' . $elapsed . 's）：' . $e->getMessage(), 'code' => 0, 'elapsed' => $elapsed, 'bytes' => 0];
        }
        $elapsed = round(microtime(true) - $t0, 2);
        $bytes = strlen((string)$body);
        return [
            'ok' => true,
            'msg' => sprintf('代理 %s 可用：耗时 %.2fs，返回 %d 字节', $label, $elapsed, $bytes),
            'code' => 200,
            'elapsed' => $elapsed,
            'bytes' => $bytes,
        ];
    }

    /** 代理地址打码显示：socks5h://user:pass@host → socks5h://user:***@host */
    public static function proxyDisplay(string $url): string
    {
        return (string)preg_replace('#(//[^:/@]+):[^@]+@#', '$1:***@', $url);
    }

    /**
     * 查询某域名的归档快照（matchType=domain，含全部子域，限量）。
     * 返回 ['ok','rows','error']；rows 为 [timestamp,original,statuscode,length,mimetype]。
     */
    public static function cdxQuery(string $domain, int $limit = 50, string $from = '', string $to = '', string $status = ''): array
    {
        $domain = trim($domain);
        if ($domain === '') {
            return ['ok' => false, 'rows' => [], 'error' => '域名不能为空'];
        }
        $limit = max(1, min(500, $limit));
        $q = self::CDX_API . '?url=' . rawurlencode($domain)
            . '&matchType=domain&fl=' . rawurlencode(self::FL)
            . '&output=json&gzip=false&limit=' . $limit;
        if ($from !== '') {
            $q .= '&from=' . rawurlencode($from);
        }
        if ($to !== '') {
            $q .= '&to=' . rawurlencode($to);
        }
        if ($status !== '' && preg_match('/^\d{3}$/', $status)) {
            $q .= '&filter=' . rawurlencode('statuscode:' . $status);
        }
        try {
            $body = self::httpGet($q, self::TIMEOUT);
        } catch (\Throwable $e) {
            return ['ok' => false, 'rows' => [], 'error' => $e->getMessage()];
        }
        $rows = self::parseCdx($body);
        return ['ok' => true, 'rows' => $rows, 'error' => ''];
    }

    /** 统计域名总快照页数（showNumPages；pageSize=1 时每页约 3000 条上限） */
    public static function numPages(string $domain): array
    {
        $domain = trim($domain);
        if ($domain === '') {
            return ['ok' => false, 'pages' => 0, 'error' => '域名不能为空'];
        }
        $q = self::CDX_API . '?url=' . rawurlencode($domain) . '&matchType=domain&showNumPages=true&pageSize=1';
        try {
            $body = self::httpGet($q, self::PAGES_TIMEOUT);
        } catch (\Throwable $e) {
            return ['ok' => false, 'pages' => 0, 'error' => $e->getMessage()];
        }
        $n = (int)trim($body);
        return ['ok' => $n >= 0, 'pages' => $n, 'error' => ''];
    }

    /** 生成 web.archive.org 快照回放地址（id_ 前缀返回原始内容、不注入工具条） */
    public static function snapshotUrl(string $timestamp, string $original): string
    {
        $timestamp = trim($timestamp);
        $original = trim($original);
        if ($timestamp === '' || $original === '') {
            return '';
        }
        return self::ARCHIVE . '/' . rawurlencode($timestamp) . 'id_/' . $original;
    }

    /** 生成 web.archive.org 快照回放地址（非 id_，注入时光机工具条，供「查看」链接用） */
    public static function replayUrl(string $timestamp, string $original): string
    {
        $timestamp = trim($timestamp);
        $original = trim($original);
        if ($timestamp === '' || $original === '') {
            return '';
        }
        return self::ARCHIVE . '/' . rawurlencode($timestamp) . '/' . $original;
    }

    /** 生成 CDX 结果页（calendar）地址 */
    public static function calendarUrl(string $domain): string
    {
        $domain = trim($domain);
        if ($domain === '') {
            return '';
        }
        return self::ARCHIVE . '/' . rawurlencode($domain);
    }

    /** 14 位时间戳格式化为可读时间（按显示时区渲染）；非法返回原值 */
    public static function formatTimestamp(string $timestamp): string
    {
        $timestamp = trim($timestamp);
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})$/', $timestamp, $m)) {
            return $timestamp;
        }
        $iso = sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);
        try {
            $dt = new \DateTimeImmutable($iso, new \DateTimeZone('UTC'));
            return $dt->setTimezone(new \DateTimeZone(\App\Config::displayTimezone()))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return $iso;
        }
    }

    /** 字节数人性化展示 */
    public static function formatLength($length): string
    {
        $n = (int)$length;
        if ($n <= 0) {
            return '-';
        }
        if ($n >= 1048576) {
            return round($n / 1048576, 1) . ' MB';
        }
        if ($n >= 1024) {
            return round($n / 1024, 1) . ' KB';
        }
        return $n . ' B';
    }
}
