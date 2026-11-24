<?php

declare(strict_types=1);

namespace App\Services;

/**
 * 图片关键词搜索服务。
 *
 * 引擎策略：
 *  - Google Images（原始口径，`tbm=isch` + tbs 筛选 + i1.wp.com 代理改写）。
 *    注意：Google 现对服务端请求一律返回 JS 引导页（enablejs 墙），换代理也绕不过，
 *    真实环境常常 0 条。
 *  - Bing Images：服务端直接渲染 `class="iusc"` 锚点的 `m="{...murl...}"`，无需 JS；
 *    对机房 IP 会间歇风控，故多端点重试。
 *  - 百度 / 360 图片 JSON 接口：Bing 无结果时的中文关键词兜底。
 *
 * 网络层：curl 优先、流上下文回退。代理自动检测与 `archive.php` 一致：`setProxy()`
 * 显式指定优先，否则读取 `APP_IMAGE_SEARCH_PROXY` / `WAYBACK_PROXY` / `HTTPS_PROXY` /
 * `HTTP_PROXY` / `ALL_PROXY` 等环境变量；检测到代理先走代理，失败再直连兜底。
 * `SOPS_TESTING` 下可用 `$GLOBALS['__imageSearchFixtureHtml']` 注入结果页 fixture，便于离线测试。
 */
class ImageSearchService
{
    const MAX_RESULTS = 20;

    /** 单次请求超时（秒）。需足够小以保证多引擎回退总耗时远低于 PHP max_execution_time */
    const TIMEOUT = 10;

    /** 整条搜索链的时间预算（秒）：多引擎回退不能把超时无限叠加，否则会触发 PHP 执行超时 */
    const TOTAL_BUDGET = 24;

    /** 有代理时分配给「代理链路」的预算比例，剩余预算留给直连兜底 */
    const PROXY_BUDGET_RATIO = 0.55;

    const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64; rv:70.0) Gecko/20100101 Firefox/70.0';

    /** 结果页抓取用的现代浏览器 UA：旧 Firefox 70 容易被 Bing 判成爬虫并返回无结果页 */
    const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    /** 单个候选地址可访问性探测超时（秒） */
    const VALIDATE_TIMEOUT = 4;

    /** 可识别为图片的 Content-Type 扩展名回退清单 */
    const IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico', 'avif', 'tif', 'tiff'];

    /**
     * 版权/图库受限域名黑名单：这些站点的图片通常禁止外链或有版权限制，直接剔除。
     * 匹配规则：主域精确匹配、子域后缀匹配、或 URL 中出现该域名（含 CDN 路径嵌入）。
     */
    const COPYRIGHT_HOSTS = [
        'shutterstock.com', 'gettyimages.com', 'istockphoto.com', 'alamy.com', 'dreamstime.com',
        '123rf.com', 'depositphotos.com', 'adobestock.com', 'stock.adobe.com', 'canstockphoto.com',
        'vectorstock.com', 'bigstockphoto.com', 'agefotostock.com', 'picfair.com', 'zoonar.com',
        'imago-images.com', 'dissolve.com', 'pond5.com', 'storyblocks.com', 'envato.com',
        'elements.envato.com', 'freepik.com', 'vecteezy.com', 'stockfresh.com', 'colourbox.com',
        'fotolia.com', 'masterfile.com', 'stocksy.com', 'photodune.net', 'crestock.com',
    ];

    /** HTTP/SOCKS 代理；null=自动读取环境变量，''=显式禁用 */
    public static $proxy = null;

    /** 设置/清除代理（null 表示回退到环境变量自动检测） */
    public static function setProxy(?string $proxy): void
    {
        self::$proxy = $proxy;
    }

    /**
     * 当前生效的代理地址（无则空串）。
     * 自动模式与 archive.php 一致：读取本机代理环境变量，图片搜索专用变量优先，
     * 其次历史档/通用代理变量（HTTPS_PROXY/http_proxy 等）。
     */
    public static function proxy(): string
    {
        if (self::$proxy !== null) {
            return trim(self::$proxy);
        }
        foreach ([
            'APP_IMAGE_SEARCH_PROXY',
            'WAYBACK_PROXY',
            'APP_WAYBACK_PROXY',
            'HTTPS_PROXY', 'https_proxy',
            'HTTP_PROXY', 'http_proxy',
            'ALL_PROXY', 'all_proxy',
        ] as $name) {
            $v = trim((string)getenv($name));
            if ($v !== '') {
                return $v;
            }
        }
        return '';
    }

    /** 代理地址打码（日志用，避免泄露凭据） */
    private static function proxyDisplay(string $proxy): string
    {
        $scheme = strtolower((string)parse_url($proxy, PHP_URL_SCHEME));
        $host = (string)parse_url($proxy, PHP_URL_HOST);
        $port = parse_url($proxy, PHP_URL_PORT);
        if ($host !== '') {
            return ($scheme !== '' ? $scheme . '://' : '') . '***@' . $host . ($port ? ':' . $port : '');
        }
        return $proxy;
    }

    /** 把代理写入 curl 选项，并按 scheme 选择 HTTP/SOCKS 类型（socks5h 让 DNS 走代理） */
    private static function applyProxyToCurl(array &$options, string $proxy): void
    {
        $proxy = trim($proxy);
        if ($proxy === '') {
            return;
        }
        $options[CURLOPT_PROXY] = $proxy;
        $scheme = strtolower((string)parse_url($proxy, PHP_URL_SCHEME));
        if ($scheme === 'socks5h') {
            $options[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5_HOSTNAME;
        } elseif ($scheme === 'socks5') {
            $options[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5;
        } elseif ($scheme === 'socks4' || $scheme === 'socks4a') {
            $options[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS4;
        } else {
            $options[CURLOPT_PROXYTYPE] = CURLPROXY_HTTP;
        }
    }

    /** 构造 Google 图片搜索 URL（保持原函数的筛选参数口径） */
    public static function buildUrl(string $keyword): string
    {
        $safe_search_phrase = urlencode($keyword);

        $SERVER = 'https://www.google.com';
        $SAFE_SEARCH_QUERY = '&q=' . $safe_search_phrase;
        $SEARCH_TYPE = '&tbm=isch';       // search for images
        $SEARCH_LANGUAGE = '&hl=en';      // language
        $SEARCH_STYLE = '&site=imghp';    // result layout style
        $SEARCH_SIMILAR = '&filter=0';    // don't omit similar results
        $search_match_type = '&nfpr=1';

        $min_pixels_search = 'isz:lt,islt:xga';
        $aspect_ratio_search = 'iar:w';
        $image_type_search = 'itp:';
        $image_format_search = 'ift:';
        $usage_rights_search = 'sur:';
        $recent_search = 'qdr:';
        $image_colour_search = 'ic:';

        $advanced_search = '&tbs=' . implode(',', [
            $min_pixels_search,
            $aspect_ratio_search,
            $image_type_search,
            $image_format_search,
            $usage_rights_search,
            $recent_search,
            $image_colour_search,
        ]);

        $safesearch_flag = '&safe=active';

        return $SERVER . '/search?' . $SEARCH_TYPE . $search_match_type . $SEARCH_SIMILAR
            . $SAFE_SEARCH_QUERY . $SEARCH_LANGUAGE . $SEARCH_STYLE . $advanced_search . $safesearch_flag;
    }

    /** 构造 Bing 图片搜索 URL（兜底引擎） */
    public static function buildBingUrl(string $keyword): string
    {
        return 'https://www.bing.com/images/search?q=' . urlencode($keyword) . '&form=HDRSC2&first=1';
    }

    /**
     * Bing 多端点候选。Bing 对机房 IP 会间歇性返回无 `iusc` 的结果页/风控页，
     * 单次请求经常 0 条；换 async 端点、区域参数和 cn 域重试可显著提高命中率。
     */
    public static function buildBingUrls(string $keyword): array
    {
        $q = urlencode($keyword);
        return [
            self::buildBingUrl($keyword) . '&mkt=en-US&cc=US&setlang=en',
            'https://www.bing.com/images/async?q=' . $q . '&first=1&count=35&mkt=en-US&cc=US&setlang=en&async=content',
            'https://cn.bing.com/images/search?q=' . $q . '&form=HDRSC2&first=1',
        ];
    }

    /** 构造百度图片 JSON 接口 URL（中文关键词最稳的兜底引擎） */
    public static function buildBaiduUrl(string $keyword): string
    {
        return 'https://image.baidu.com/search/acjson?tn=resultjson_com&ipn=rj&word=' . urlencode($keyword) . '&pn=0&rn=30';
    }

    /** 构造 360 图片 JSON 接口 URL */
    public static function build360Url(string $keyword): string
    {
        return 'https://image.so.com/j?q=' . urlencode($keyword) . '&src=srp&sn=0&pn=30';
    }

    /** 当前引擎对应的结果页地址（用于接口返回 source 字段） */
    public static function buildSourceUrl(string $engine, string $keyword): string
    {
        if ($engine === 'bing') {
            return self::buildBingUrl($keyword);
        }
        if ($engine === 'baidu') {
            return self::buildBaiduUrl($keyword);
        }
        if ($engine === 'so360') {
            return self::build360Url($keyword);
        }
        return self::buildUrl($keyword);
    }

    /**
     * 从 Google 图片结果 HTML 中解析图片地址。
     * 目标形态：形如 ["https://host/path.jpg",宽,高]，并排除 .gstatic.com 缩略图。
     *
     * 说明：原始正则用 `(?:(?!.gstatic.com).)+` 做贪婪匹配，会跨越相邻条目
     * `",1,2]["` 边界把多张图拼成一个畸形 URL；此处改为按闭合引号截断逐条捕获，
     * 再在 PHP 侧过滤 gstatic，结果更准确且保持同样的排除语义。
     *
     * 重要：Google 内联数据里的 URL 是 JSON 字符串，斜杠/等号/& 等会被转义成
     * `https:\/\/host\/a.jpg?w=1\u0026h=2`。若不还原就直接改写代理，返回的是带
     * `\/`、`\u0026` 的畸形地址（浏览器打不开），必须先做 JSON 反转义。
     *
     * 再把 http/https 统一改写为 i1.wp.com 代理；最后按 $limit 截断。
     */
    public static function parseImageUrls(string $html, int $limit = self::MAX_RESULTS): array
    {
        if ($html === '') {
            return [];
        }
        preg_match_all('/\["(https:[^"]+)",([0-9]+),([0-9]+)\]/', $html, $match_image_urls);
        $matched = isset($match_image_urls[1]) ? (array)$match_image_urls[1] : [];
        // JSON 反转义（`\/`、`\u0026`、`\u003d` 等），否则返回的图片地址不可用
        $matched = array_map(function ($u) {
            return self::decodeJsonUrl((string)$u);
        }, $matched);
        // 排除 gstatic.com 缩略图（保持原口径）
        $matched = array_values(array_filter($matched, function ($u) {
            return stripos((string)$u, 'gstatic.com') === false;
        }));
        if ($matched === []) {
            return [];
        }
        $google_image_urls = preg_replace(
            ['#https://#', '#http://#'],
            ['https://i1.wp.com/', 'https://i1.wp.com/'],
            $matched
        );

        $outputs = [];
        $loop = 1;
        foreach ($google_image_urls as $gimage_src) {
            if ($loop > $limit) {
                break;
            }
            $outputs[] = $gimage_src;
            $loop++;
        }
        return $outputs;
    }

    /**
     * 从 Bing 图片结果 HTML 中解析真实图片地址。
     * 首选 `class="iusc"` 锚点的 `m="{...}"`（HTML 实体编码的 JSON，取 murl），
     * 回退先还原 HTML 实体再取 `"murl":"URL"`（保留 `&` 查询串）；去重后按 $limit 截断。
     */
    public static function parseBingImageUrls(string $html, int $limit = self::MAX_RESULTS): array
    {
        if ($html === '') {
            return [];
        }
        $urls = [];
        if (preg_match_all('/<a[^>]+class="iusc"[^>]+m="([^"]+)"/', $html, $mIusc)) {
            foreach ($mIusc[1] as $raw) {
                $decoded = html_entity_decode((string)$raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $data = json_decode($decoded, true);
                if (is_array($data) && !empty($data['murl']) && is_string($data['murl'])) {
                    // murl 偶有二次转义（\\u0026 / \\/），再还原一次
                    $urls[] = self::decodeJsonUrl($data['murl']);
                }
            }
        }
        if ($urls === []) {
            // 回退：先还原 HTML 实体（&quot; / &amp;），再按完整 JSON 字符串取值。
            // 旧正则用 [^&]+ 会在 murl 带查询参数（...?a=1&amp;b=2）时把地址截断。
            $decodedHtml = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match_all('/"murl"\s*:\s*"(https?:[^"]*)"/', $decodedHtml, $mEntity)) {
                foreach ($mEntity[1] as $raw) {
                    $urls[] = self::decodeJsonUrl((string)$raw);
                }
            }
        }
        $outputs = [];
        foreach ($urls as $u) {
            if (count($outputs) >= $limit) {
                break;
            }
            if (!is_string($u) || !preg_match('#^https?://#i', $u)) {
                continue;
            }
            if (in_array($u, $outputs, true)) {
                continue;
            }
            $outputs[] = $u;
        }
        return $outputs;
    }

    /**
     * 解析百度图片 JSON（`data[].middleURL/thumbURL/hoverURL`）。
     * 百度对机房 IP 的容忍度远高于 Bing，中文关键词几乎总能拿到结果。
     */
    public static function parseBaiduImageUrls(string $json, int $limit = self::MAX_RESULTS): array
    {
        if ($json === '') {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
            return [];
        }
        $urls = [];
        foreach ($data['data'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $u = '';
            foreach (['middleURL', 'thumbURL', 'hoverURL'] as $key) {
                if (!empty($row[$key]) && is_string($row[$key])) {
                    $u = $row[$key];
                    break;
                }
            }
            if ($u === '' && isset($row['replaceUrl'][0]['ObjURL']) && is_string($row['replaceUrl'][0]['ObjURL'])) {
                $u = $row['replaceUrl'][0]['ObjURL'];
            }
            if ($u !== '') {
                $urls[] = $u;
            }
        }
        return self::uniqueLimit($urls, $limit);
    }

    /** 解析 360 图片 JSON（`list[].thumb_bak/thumb/img`） */
    public static function parse360ImageUrls(string $json, int $limit = self::MAX_RESULTS): array
    {
        if ($json === '') {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['list']) || !is_array($data['list'])) {
            return [];
        }
        $urls = [];
        foreach ($data['list'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $u = '';
            foreach (['thumb_bak', 'thumb', 'img'] as $key) {
                if (!empty($row[$key]) && is_string($row[$key])) {
                    $u = $row[$key];
                    break;
                }
            }
            if ($u !== '') {
                $urls[] = $u;
            }
        }
        return self::uniqueLimit($urls, $limit);
    }

    /** 去重 + 只保留 http(s) + 按 $limit 截断 */
    private static function uniqueLimit(array $urls, int $limit): array
    {
        $outputs = [];
        foreach ($urls as $u) {
            if (count($outputs) >= $limit) {
                break;
            }
            if (!is_string($u) || !preg_match('#^https?://#i', $u)) {
                continue;
            }
            if (in_array($u, $outputs, true)) {
                continue;
            }
            $outputs[] = $u;
        }
        return $outputs;
    }

    /**
     * 还原 JSON 字符串里的 URL 转义：`\/` → `/`、`\u0026` → `&`、`\u003d` → `=`。
     * 解析 Google 内联数据时若不还原，返回的地址会带反斜杠而无法访问。
     */
    private static function decodeJsonUrl(string $url): string
    {
        if ($url === '' || strpos($url, '\\') === false) {
            return $url;
        }
        $decoded = json_decode('"' . $url . '"');
        if (is_string($decoded)) {
            return $decoded;
        }
        // 非法 JSON 转义时至少还原最常见的斜杠转义
        return str_replace('\\/', '/', $url);
    }

    /**
     * 抓取结果页 HTML：优先 curl（本环境 file_get_contents 会超时），回退流上下文；支持代理。
     * $options 可覆盖 user_agent / headers / referer / cookie / timeout（用于模拟真实浏览器，
     * 降低 Bing 对机房 IP 的风控概率）。
     */
    public static function fetchHtml(string $url, array $options = []): ?string
    {
        // 测试注入点：SOPS_TESTING 下可用 fixture 替换真实网络
        if (defined('SOPS_TESTING') && isset($GLOBALS['__imageSearchFixtureHtml'])) {
            return (string)$GLOBALS['__imageSearchFixtureHtml'];
        }

        // curl 可用时只走 curl：其超时有界、可靠；避免再叠加流上下文回退导致总耗时翻倍
        // （file_get_contents 在受限网络会挂满 timeout，曾触发 max_execution_time 致命错误）。
        // 仅在无 curl 扩展的环境才回退到流上下文。
        if (function_exists('curl_init')) {
            $body = self::fetchViaCurl($url, $options);
            return ($body === null || $body === '') ? null : $body;
        }
        $body = self::fetchViaStream($url, $options);
        return ($body === null || $body === '') ? null : $body;
    }

    /**
     * 根据整条链的 deadline 计算本次请求可用超时；预算不足返回 0，调用方应跳过该引擎。
     * 避免多引擎回退把各引擎超时简单叠加，最终撞上 PHP max_execution_time。
     */
    private static function timeoutFor(?float $deadline, int $cap): int
    {
        if ($deadline === null) {
            return $cap;
        }
        $remaining = (int)floor($deadline - microtime(true));
        if ($remaining < 2) {
            return 0;
        }
        return min($cap, $remaining);
    }

    /** 解析本次调用实际使用的代理：null=自动检测，''=强制直连 */
    private static function effectiveProxy(?string $proxy): string
    {
        return $proxy === null ? self::proxy() : trim($proxy);
    }

    /** 仅走 Google 引擎 */
    public static function searchGoogle(string $keyword, int $limit = self::MAX_RESULTS, ?float $deadline = null, ?string $proxy = null): array
    {
        $timeout = self::timeoutFor($deadline, 8);
        if ($timeout === 0) {
            return [];
        }
        $html = self::fetchHtml(self::buildUrl($keyword), [
            'timeout' => $timeout,
            'proxy' => self::effectiveProxy($proxy),
        ]);
        return $html === null ? [] : self::parseImageUrls($html, $limit);
    }

    /** 仅走 Bing 引擎（单次请求，返回真实图片地址） */
    public static function searchBing(string $keyword, int $limit = self::MAX_RESULTS, ?float $deadline = null, ?string $proxy = null): array
    {
        $timeout = self::timeoutFor($deadline, 8);
        if ($timeout === 0) {
            return [];
        }
        $html = self::fetchHtml(self::buildBingUrl($keyword), [
            'timeout' => $timeout,
            'proxy' => self::effectiveProxy($proxy),
        ]);
        return $html === null ? [] : self::parseBingImageUrls($html, $limit);
    }

    /**
     * Bing 多端点 + 重试：Bing 对机房 IP 会间歇性返回无 iusc 的风控页，
     * 单次请求经常 0 条；换 async/cn 端点并重试可显著提高命中率。
     */
    public static function searchBingRobust(string $keyword, int $limit = self::MAX_RESULTS, ?float $deadline = null, ?string $proxy = null): array
    {
        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9,zh-CN;q=0.8,zh;q=0.7',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: same-origin',
            'Upgrade-Insecure-Requests: 1',
        ];
        $effectiveProxy = self::effectiveProxy($proxy);
        foreach (self::buildBingUrls($keyword) as $url) {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $timeout = self::timeoutFor($deadline, 8);
                if ($timeout === 0) {
                    return [];
                }
                $html = self::fetchHtml($url, [
                    'user_agent' => self::BROWSER_UA,
                    'referer' => 'https://www.bing.com/',
                    'headers' => $headers,
                    'timeout' => $timeout,
                    'proxy' => $effectiveProxy,
                ]);
                if ($html !== null) {
                    $images = self::parseBingImageUrls($html, $limit);
                    if ($images !== []) {
                        return $images;
                    }
                }
                // 短暂退避后重试，避免连续请求被风控
                usleep(150000);
            }
        }
        return [];
    }

    /** 仅走百度图片 JSON 接口 */
    public static function searchBaidu(string $keyword, int $limit = self::MAX_RESULTS, ?float $deadline = null, ?string $proxy = null): array
    {
        $timeout = self::timeoutFor($deadline, 8);
        if ($timeout === 0) {
            return [];
        }
        $html = self::fetchHtml(self::buildBaiduUrl($keyword), [
            'user_agent' => self::BROWSER_UA,
            'referer' => 'https://image.baidu.com/',
            'headers' => [
                'Accept: application/json, text/plain, */*',
                'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
            ],
            'timeout' => $timeout,
            'proxy' => self::effectiveProxy($proxy),
        ]);
        return $html === null ? [] : self::parseBaiduImageUrls($html, $limit);
    }

    /** 仅走 360 图片 JSON 接口 */
    public static function search360(string $keyword, int $limit = self::MAX_RESULTS, ?float $deadline = null, ?string $proxy = null): array
    {
        $timeout = self::timeoutFor($deadline, 8);
        if ($timeout === 0) {
            return [];
        }
        $html = self::fetchHtml(self::build360Url($keyword), [
            'user_agent' => self::BROWSER_UA,
            'referer' => 'https://image.so.com/',
            'headers' => [
                'Accept: application/json, text/plain, */*',
                'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
            ],
            'timeout' => $timeout,
            'proxy' => self::effectiveProxy($proxy),
        ]);
        return $html === null ? [] : self::parse360ImageUrls($html, $limit);
    }

    /** 按固定网络路线跑完整引擎链（Google → Bing → 百度 → 360） */
    private static function runChain(string $keyword, int $limit, float $deadline, string $proxy): array
    {
        $images = self::searchGoogle($keyword, $limit, $deadline, $proxy);
        if ($images !== []) {
            return ['engine' => 'google', 'images' => $images];
        }
        $images = self::searchBingRobust($keyword, $limit, $deadline, $proxy);
        if ($images !== []) {
            return ['engine' => 'bing', 'images' => $images];
        }
        $images = self::searchBaidu($keyword, $limit, $deadline, $proxy);
        if ($images !== []) {
            return ['engine' => 'baidu', 'images' => $images];
        }
        $images = self::search360($keyword, $limit, $deadline, $proxy);
        if ($images !== []) {
            return ['engine' => 'so360', 'images' => $images];
        }
        return ['engine' => 'none', 'images' => []];
    }

    /**
     * 关键词搜索（带回溯引擎信息）：
     * 1) 本机检测到代理（环境变量，同 archive.php）→ 先用代理跑完整引擎链；
     * 2) 代理链路无结果 → 用剩余预算直连再跑一遍作为兜底。
     *
     * 返回 ['engine' => 'google'|'bing'|'baidu'|'so360'|'none', 'images' => array,
     *       'via' => 'proxy'|'direct'|'none', 'proxy' => 实际使用的代理地址（''=直连）]。
     */
    public static function searchWithMeta(string $keyword, int $limit = self::MAX_RESULTS): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return ['engine' => 'none', 'images' => [], 'via' => 'none', 'proxy' => ''];
        }
        $started = microtime(true);
        $autoProxy = self::proxy();

        // 有代理时优先走代理网络
        if ($autoProxy !== '') {
            $proxyDeadline = $started + self::TOTAL_BUDGET * self::PROXY_BUDGET_RATIO;
            $hit = self::runChain($keyword, $limit, $proxyDeadline, $autoProxy);
            if ($hit['images'] !== []) {
                return $hit + ['via' => 'proxy', 'proxy' => $autoProxy];
            }
            error_log('[ImageSearchService] proxy route empty for "' . $keyword . '", retry direct ('
                . self::proxyDisplay($autoProxy) . ')');
        }

        // 直连兜底（未配置代理时即唯一路线）
        $hit = self::runChain($keyword, $limit, $started + self::TOTAL_BUDGET, '');
        if ($hit['images'] !== []) {
            return $hit + ['via' => 'direct', 'proxy' => ''];
        }

        error_log('[ImageSearchService] no images for "' . $keyword . '" (google/bing/baidu/360 all empty)');
        return ['engine' => 'none', 'images' => [], 'via' => 'none', 'proxy' => ''];
    }

    /** 关键词搜索：返回图片地址数组（失败/空结果返回空数组） */
    public static function search(string $keyword, int $limit = self::MAX_RESULTS): array
    {
        return self::searchWithMeta($keyword, $limit)['images'];
    }

    /**
     * 可访问性 + 图片类型 + 版权过滤。
     * 返回 ['checked' => 候选数, 'images' => 通过校验的可外链图片地址（≤ $limit）]。
     *
     *  - 版权/图库受限域名直接剔除（无需网络）。
     *  - 其余候选并发探测（HEAD 优先，失败回退 Range GET）：要求 2xx 且 Content-Type 为 image/*。
     *  - SOPS_TESTING：设置 $GLOBALS['__imageSearchValidateStub']（url => ['ok'=>bool,'type'=>string]）
     *    可离线注入探测结果；未设 stub 时跳过真实网络，仅做版权过滤（便于既有 fixture 测试）。
     *  - $proxy：null=自动检测，''=强制直连；应与搜索成功的网络路线一致，
     *    否则代理失败回退直连后，校验仍走坏代理会把结果全部丢弃。
     */
    public static function validate(array $urls, int $limit = self::MAX_RESULTS, ?string $proxy = null): array
    {
        $candidates = [];
        foreach ($urls as $u) {
            $u = trim((string)$u);
            if ($u === '' || in_array($u, $candidates, true)) {
                continue;
            }
            $candidates[] = $u;
        }
        return ['checked' => count($candidates), 'images' => self::validateUrls($candidates, $limit, self::effectiveProxy($proxy))];
    }

    private static function validateUrls(array $urls, int $limit, string $proxy = ''): array
    {
        if ($urls === []) {
            return [];
        }
        // 先做无需网络的版权过滤
        $candidates = [];
        foreach ($urls as $u) {
            if (!self::isCopyrightRestricted($u)) {
                $candidates[] = $u;
            }
        }
        if ($candidates === []) {
            return [];
        }

        // 测试注入
        if (defined('SOPS_TESTING')) {
            if (isset($GLOBALS['__imageSearchValidateStub']) && is_array($GLOBALS['__imageSearchValidateStub'])) {
                $stub = $GLOBALS['__imageSearchValidateStub'];
                $out = [];
                foreach ($candidates as $u) {
                    if (count($out) >= $limit) {
                        break;
                    }
                    $info = isset($stub[$u]) && is_array($stub[$u]) ? $stub[$u] : null;
                    if ($info !== null && !empty($info['ok']) && self::isImageType((string)($info['type'] ?? ''), $u)) {
                        $out[] = $u;
                    }
                }
                return $out;
            }
            // 无 stub：跳过真实网络探测，仅保留非版权受限地址
            $out = [];
            foreach ($candidates as $u) {
                if (count($out) >= $limit) {
                    break;
                }
                $out[] = $u;
            }
            return $out;
        }

        // 真实网络：第一轮并发 HEAD
        $results = self::probeBatch($candidates, 'head', $proxy);
        $needsFallback = [];
        $out = [];
        foreach ($candidates as $u) {
            if (count($out) >= $limit) {
                break;
            }
            $info = isset($results[$u]) ? $results[$u] : ['code' => 0, 'type' => ''];
            if ($info['code'] >= 200 && $info['code'] < 300 && self::isImageType($info['type'], $u)) {
                $out[] = $u;
            } else {
                $needsFallback[] = $u;
            }
        }
        // 第二轮：对未确认的候选并发 Range GET（取 1 字节判 Content-Type）
        if ($needsFallback !== [] && count($out) < $limit) {
            $fallback = self::probeBatch($needsFallback, 'get', $proxy);
            foreach ($needsFallback as $u) {
                if (count($out) >= $limit) {
                    break;
                }
                $info = isset($fallback[$u]) ? $fallback[$u] : ['code' => 0, 'type' => ''];
                if ($info['code'] >= 200 && $info['code'] < 300 && self::isImageType($info['type'], $u)) {
                    $out[] = $u;
                }
            }
        }
        return $out;
    }

    /** 版权/图库受限域名判定（主域/子域/URL 内嵌命中黑名单即视为受限） */
    public static function isCopyrightRestricted(string $url): bool
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);
        $lower = strtolower($url);
        foreach (self::COPYRIGHT_HOSTS as $blocked) {
            $blocked = strtolower($blocked);
            if ($host === $blocked || substr($host, -strlen('.' . $blocked)) === '.' . $blocked || strpos($lower, $blocked) !== false) {
                return true;
            }
        }
        return false;
    }

    /** Content-Type 是否为图片（无 Content-Type 时回退 URL 扩展名判断） */
    public static function isImageType(string $type, string $url): bool
    {
        $type = strtolower(trim($type));
        if ($type !== '') {
            return strpos($type, 'image/') === 0;
        }
        $path = (string)parse_url($url, PHP_URL_PATH);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, self::IMAGE_EXTS, true);
    }

    /** 并发探测一批 URL：$method = 'head'（NOBODY）或 'get'（Range 1 字节，读到 body 即中止） */
    private static function probeBatch(array $urls, string $method, string $proxy = ''): array
    {
        $results = [];
        if ($urls === []) {
            return $results;
        }
        if (!function_exists('curl_multi_init')) {
            foreach ($urls as $u) {
                $results[$u] = self::probeSingle($u, $method, $proxy);
            }
            return $results;
        }
        $mh = curl_multi_init();
        $handles = [];
        foreach ($urls as $u) {
            $ch = self::probeHandle($u, $method, $proxy);
            $handles[$u] = $ch;
            curl_multi_add_handle($mh, $ch);
        }
        do {
            $code = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 0.1);
            }
        } while ($running > 0 && $code === CURLM_OK);
        foreach ($handles as $u => $ch) {
            $results[$u] = [
                'code' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                'type' => (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
            ];
            curl_multi_remove_handle($mh, $ch);
            if (function_exists('curl_close')) {
                @curl_close($ch);
            }
        }
        curl_multi_close($mh);
        return $results;
    }

    /** 单个 URL 探测（无 curl_multi 时的退化路径） */
    private static function probeSingle(string $url, string $method, string $proxy = ''): array
    {
        if (!function_exists('curl_init')) {
            return ['code' => 0, 'type' => ''];
        }
        $ch = self::probeHandle($url, $method, $proxy);
        curl_exec($ch);
        $info = [
            'code' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'type' => (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
        ];
        if (function_exists('curl_close')) {
            @curl_close($ch);
        }
        return $info;
    }

    private static function probeHandle(string $url, string $method, string $proxy = '')
    {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => self::VALIDATE_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => ['Accept: image/*,*/*;q=0.8', 'Accept-Language: en-US,en;q=0.9'],
        ];
        if ($method === 'head') {
            $options[CURLOPT_NOBODY] = true;
        } else {
            $options[CURLOPT_RANGE] = 'bytes=0-0';
            // 仅取响应头：读到首个 body 字节即中止（返回 0 触发 CURLE_WRITE_ERROR，但头已解析）
            $options[CURLOPT_WRITEFUNCTION] = function () {
                return 0;
            };
        }
        self::applyProxyToCurl($options, $proxy);
        curl_setopt_array($ch, $options);
        return $ch;
    }

    private static function fetchViaCurl(string $url, array $options = []): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        $headers = isset($options['headers']) && is_array($options['headers']) && $options['headers'] !== []
            ? array_values($options['headers'])
            : [
                'Accept-Language: en-US,en;q=0.9',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ];
        $timeout = isset($options['timeout']) ? (int)$options['timeout'] : self::TIMEOUT;
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout > 0 ? $timeout : self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => isset($options['user_agent']) && is_string($options['user_agent']) && $options['user_agent'] !== ''
                ? $options['user_agent']
                : self::USER_AGENT,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if (isset($options['referer']) && is_string($options['referer']) && $options['referer'] !== '') {
            $curlOptions[CURLOPT_REFERER] = $options['referer'];
        }
        if (isset($options['cookie']) && is_string($options['cookie']) && $options['cookie'] !== '') {
            $curlOptions[CURLOPT_COOKIE] = $options['cookie'];
        }
        $proxy = array_key_exists('proxy', $options) ? trim((string)$options['proxy']) : self::proxy();
        self::applyProxyToCurl($curlOptions, $proxy);
        curl_setopt_array($ch, $curlOptions);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($body === false || $status >= 400 || $status === 0) {
            return null;
        }
        return (string)$body;
    }

    private static function fetchViaStream(string $url, array $options = []): ?string
    {
        $proxy = array_key_exists('proxy', $options) ? trim((string)$options['proxy']) : self::proxy();
        $proxyUri = '';
        $headerLines = [];
        $headerLines[] = 'Accept-Language: en-US,en;q=0.9';
        if (isset($options['headers']) && is_array($options['headers'])) {
            foreach ($options['headers'] as $line) {
                if (is_string($line) && $line !== '') {
                    $headerLines[] = $line;
                }
            }
        }
        if (isset($options['referer']) && is_string($options['referer']) && $options['referer'] !== '') {
            $headerLines[] = 'Referer: ' . $options['referer'];
        }
        if (isset($options['cookie']) && is_string($options['cookie']) && $options['cookie'] !== '') {
            $headerLines[] = 'Cookie: ' . $options['cookie'];
        }
        // 流上下文只支持 HTTP 代理（SOCKS 交给 curl 路径）
        if ($proxy !== '') {
            $scheme = strtolower((string)parse_url($proxy, PHP_URL_SCHEME));
            if ($scheme === '' || $scheme === 'http' || $scheme === 'https') {
                $proxyHost = (string)parse_url($proxy, PHP_URL_HOST);
                $proxyPort = parse_url($proxy, PHP_URL_PORT);
                if ($proxyHost !== '') {
                    $proxyUri = 'tcp://' . $proxyHost . ($proxyPort ? ':' . $proxyPort : '');
                    $proxyUser = (string)parse_url($proxy, PHP_URL_USER);
                    $proxyPass = (string)parse_url($proxy, PHP_URL_PASS);
                    if ($proxyUser !== '') {
                        $headerLines[] = 'Proxy-Authorization: Basic ' . base64_encode($proxyUser . ':' . $proxyPass);
                    }
                } else {
                    $proxyUri = 'tcp://' . preg_replace('#^.*@#', '', $proxy);
                }
            }
        }
        $timeout = isset($options['timeout']) ? (int)$options['timeout'] : self::TIMEOUT;
        $http = [
            'method' => 'GET',
            'user_agent' => isset($options['user_agent']) && is_string($options['user_agent']) && $options['user_agent'] !== ''
                ? $options['user_agent']
                : self::USER_AGENT,
            'timeout' => $timeout > 0 ? $timeout : self::TIMEOUT,
            'ignore_errors' => true,
            'header' => implode("\r\n", $headerLines) . "\r\n",
        ];
        if ($proxyUri !== '') {
            $http['proxy'] = $proxyUri;
            $http['request_fulluri'] = true;
        }
        $context = stream_context_create(['http' => $http]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return null;
        }
        return (string)$body;
    }
}
