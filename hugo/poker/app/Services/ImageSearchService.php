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
 *  - Bing Images 作为兜底：服务端直接渲染 `class="iusc"` 锚点的 `m="{...murl...}"`，
 *    无需 JS 即可拿到真实图片地址，保证关键词一定能返回真实图片。
 *
 * 网络层：curl 优先、流上下文回退；支持 HTTP 代理（`setProxy()` 或环境变量
 * `APP_IMAGE_SEARCH_PROXY`，如 `127.0.0.1:7890`），对直连受限环境必要。
 * `SOPS_TESTING` 下可用 `$GLOBALS['__imageSearchFixtureHtml']` 注入结果页 fixture，便于离线测试。
 */
class ImageSearchService
{
    const MAX_RESULTS = 20;

    /** 单次请求超时（秒）。需足够小以保证 Google+Bing 两次抓取总耗时远低于 PHP max_execution_time */
    const TIMEOUT = 10;

    const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64; rv:70.0) Gecko/20100101 Firefox/70.0';

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

    /** HTTP 代理；null=读环境变量，''=显式禁用 */
    public static $proxy = null;

    /** 设置/清除代理（null 表示回退到环境变量） */
    public static function setProxy(?string $proxy): void
    {
        self::$proxy = $proxy;
    }

    /** 当前生效的代理地址（无则空串） */
    public static function proxy(): string
    {
        if (self::$proxy !== null) {
            return trim(self::$proxy);
        }
        $env = getenv('APP_IMAGE_SEARCH_PROXY');
        return $env === false ? '' : trim((string)$env);
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
     * 从 Google 图片结果 HTML 中解析图片地址。
     * 目标形态：形如 ["https://host/path.jpg",宽,高]，并排除 .gstatic.com 缩略图。
     *
     * 说明：原始正则用 `(?:(?!.gstatic.com).)+` 做贪婪匹配，会跨越相邻条目
     * `",1,2]["` 边界把多张图拼成一个畸形 URL；此处改为按闭合引号截断逐条捕获，
     * 再在 PHP 侧过滤 gstatic，结果更准确且保持同样的排除语义。
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
     * 回退直接正则 `&quot;murl&quot;:&quot;URL&quot;`；去重后按 $limit 截断。
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
                    $urls[] = $data['murl'];
                }
            }
        }
        if ($urls === [] && preg_match_all('/&quot;murl&quot;:&quot;(https:[^&]+)&quot;/', $html, $mEntity)) {
            $urls = (array)$mEntity[1];
        }
        $outputs = [];
        foreach ($urls as $u) {
            if (count($outputs) >= $limit) {
                break;
            }
            if (in_array($u, $outputs, true)) {
                continue;
            }
            $outputs[] = $u;
        }
        return $outputs;
    }

    /** 抓取结果页 HTML：优先 curl（本环境 file_get_contents 会超时），回退流上下文；支持代理 */
    public static function fetchHtml(string $url): ?string
    {
        // 测试注入点：SOPS_TESTING 下可用 fixture 替换真实网络
        if (defined('SOPS_TESTING') && isset($GLOBALS['__imageSearchFixtureHtml'])) {
            return (string)$GLOBALS['__imageSearchFixtureHtml'];
        }

        // curl 可用时只走 curl：其超时有界、可靠；避免再叠加流上下文回退导致总耗时翻倍
        // （file_get_contents 在受限网络会挂满 timeout，曾触发 max_execution_time 致命错误）。
        // 仅在无 curl 扩展的环境才回退到流上下文。
        if (function_exists('curl_init')) {
            $body = self::fetchViaCurl($url);
            return ($body === null || $body === '') ? null : $body;
        }
        $body = self::fetchViaStream($url);
        return ($body === null || $body === '') ? null : $body;
    }

    /** 仅走 Google 引擎 */
    public static function searchGoogle(string $keyword, int $limit = self::MAX_RESULTS): array
    {
        $html = self::fetchHtml(self::buildUrl($keyword));
        return $html === null ? [] : self::parseImageUrls($html, $limit);
    }

    /** 仅走 Bing 引擎（兜底，返回真实图片地址） */
    public static function searchBing(string $keyword, int $limit = self::MAX_RESULTS): array
    {
        $html = self::fetchHtml(self::buildBingUrl($keyword));
        return $html === null ? [] : self::parseBingImageUrls($html, $limit);
    }

    /**
     * 关键词搜索（带回溯引擎信息）：先 Google，Google 无结果（JS 墙）再 Bing。
     * 返回 ['engine' => 'google'|'bing'|'none', 'images' => array]。
     */
    public static function searchWithMeta(string $keyword, int $limit = self::MAX_RESULTS): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return ['engine' => 'none', 'images' => []];
        }
        $images = self::searchGoogle($keyword, $limit);
        if ($images !== []) {
            return ['engine' => 'google', 'images' => $images];
        }
        $images = self::searchBing($keyword, $limit);
        if ($images !== []) {
            return ['engine' => 'bing', 'images' => $images];
        }
        return ['engine' => 'none', 'images' => []];
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
     */
    public static function validate(array $urls, int $limit = self::MAX_RESULTS): array
    {
        $candidates = [];
        foreach ($urls as $u) {
            $u = trim((string)$u);
            if ($u === '' || in_array($u, $candidates, true)) {
                continue;
            }
            $candidates[] = $u;
        }
        return ['checked' => count($candidates), 'images' => self::validateUrls($candidates, $limit)];
    }

    private static function validateUrls(array $urls, int $limit): array
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
        $results = self::probeBatch($candidates, 'head');
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
            $fallback = self::probeBatch($needsFallback, 'get');
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
    private static function probeBatch(array $urls, string $method): array
    {
        $results = [];
        if ($urls === []) {
            return $results;
        }
        if (!function_exists('curl_multi_init')) {
            foreach ($urls as $u) {
                $results[$u] = self::probeSingle($u, $method);
            }
            return $results;
        }
        $mh = curl_multi_init();
        $handles = [];
        foreach ($urls as $u) {
            $ch = self::probeHandle($u, $method);
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
    private static function probeSingle(string $url, string $method): array
    {
        if (!function_exists('curl_init')) {
            return ['code' => 0, 'type' => ''];
        }
        $ch = self::probeHandle($url, $method);
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

    private static function probeHandle(string $url, string $method)
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
        $proxy = self::proxy();
        if ($proxy !== '') {
            $options[CURLOPT_PROXY] = $proxy;
            $options[CURLOPT_PROXYTYPE] = CURLPROXY_HTTP;
        }
        curl_setopt_array($ch, $options);
        return $ch;
    }

    private static function fetchViaCurl(string $url): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => [
                'Accept-Language: en-US,en;q=0.9',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ],
        ];
        $proxy = self::proxy();
        if ($proxy !== '') {
            $options[CURLOPT_PROXY] = $proxy;
            $options[CURLOPT_PROXYTYPE] = CURLPROXY_HTTP;
        }
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($body === false || $status >= 400 || $status === 0) {
            return null;
        }
        return (string)$body;
    }

    private static function fetchViaStream(string $url): ?string
    {
        $http = [
            'method' => 'GET',
            'user_agent' => self::USER_AGENT,
            'timeout' => self::TIMEOUT,
            'ignore_errors' => true,
            'header' => "Accept-Language: en-US,en;q=0.9\r\n",
        ];
        $proxy = self::proxy();
        if ($proxy !== '') {
            $http['proxy'] = 'tcp://' . $proxy;
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
