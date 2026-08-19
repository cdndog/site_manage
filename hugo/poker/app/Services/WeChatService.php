<?php

namespace App\Services;

use App\Config;

class WeChatService
{
    public static function extract($url)
    {
        $url = trim((string) $url);
        if ($url === '' || !self::isWeixinArticleUrl($url)) {
            return ['error' => '仅支持 mp.weixin.qq.com 域名下的文章链接。'];
        }
        $cfg = self::config();
        $t0 = microtime(true);
        $headers = self::buildHeaders($cfg);
        $res = self::curlGetContents(
            $url,
            $headers,
            isset($cfg['cookie']) ? (string) $cfg['cookie'] : '',
            isset($cfg['proxy']) ? (string) $cfg['proxy'] : '',
            isset($cfg['connect_timeout']) ? (int) $cfg['connect_timeout'] : 15,
            isset($cfg['timeout']) ? (int) $cfg['timeout'] : 30,
            isset($cfg['max_retries']) ? (int) $cfg['max_retries'] : 2
        );
        if (!$res['ok']) {
            return ['error' => $res['error']];
        }
        $data = self::parseArticleHtml($res['body'], $url, (int) $res['http_code']);
        if (isset($data['error'])) {
            return $data;
        }
        $data['fetch_ms'] = (int) round((microtime(true) - $t0) * 1000);
        $data['slug'] = self::slugify($data['title']);
        return $data;
    }

    public static function syncImage($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return ['ok' => false, 'error' => '图片地址为空。'];
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (str_contains($host, 'imgbb.com') || str_contains($host, 'ibb.co')) {
            return ['ok' => true, 'url' => $url, 'skipped' => true];
        }
        $cfg = self::config();
        $bytes = self::fetchImageBytes($url, $cfg);
        if ($bytes === '') {
            return ['ok' => false, 'error' => '图片获取失败（原地址与 i1.wp.com 代理均失败）。'];
        }
        $up = ImgBBService::uploadBytes($bytes, 'wx_thumb_' . substr(uniqid(), -8));
        if ($up['success'] !== true) {
            return ['ok' => false, 'error' => $up['error']['message']];
        }
        return ['ok' => true, 'url' => $up['data']['url']];
    }

    public static function syncImages(array $urls)
    {
        $results = [];
        $pending = [];
        foreach ($urls as $u) {
            $u = trim((string) $u);
            if ($u === '') {
                continue;
            }
            $host = strtolower((string) parse_url($u, PHP_URL_HOST));
            if (str_contains($host, 'imgbb.com') || str_contains($host, 'ibb.co')) {
                $results[$u] = ['ok' => true, 'url' => $u, 'skipped' => true];
            } else {
                $pending[$u] = true;
            }
        }
        if (!$pending) {
            return $results;
        }
        $cfg = self::config();
        $fetched = self::fetchImagesParallel(array_keys($pending), $cfg);
        $missing = array_diff(array_keys($pending), array_keys($fetched));
        if ($missing) {
            $proxyMap = [];
            foreach ($missing as $u) {
                $proxyMap['https://i1.wp.com/' . preg_replace('#^https?://#i', '', $u)] = $u;
            }
            $viaProxy = self::fetchImagesParallel(array_keys($proxyMap), $cfg);
            foreach ($viaProxy as $p => $bytes) {
                $fetched[$proxyMap[$p]] = $bytes;
            }
        }
        $ups = self::uploadBytesParallel($fetched);
        foreach (array_keys($pending) as $u) {
            if (isset($ups[$u])) {
                $results[$u] = $ups[$u];
            } else {
                $results[$u] = ['ok' => false, 'error' => '图片获取失败（原地址与 i1.wp.com 代理均失败）。'];
            }
        }
        return $results;
    }

    public static function slugify($title)
    {
        $s = trim((string) $title);
        if ($s === '') {
            return '';
        }
        if (class_exists('\Overtrue\Pinyin\Pinyin')) {
            try {
                $normalized = function_exists('normalizer_normalize') ? normalizer_normalize($s, \Normalizer::FORM_D) : false;
                if ($normalized === false) {
                    $normalized = $s;
                }
                $normalized = preg_replace('/[\x{0300}-\x{036f}]/u', '', (string) $normalized);
                $slug = (new \Overtrue\Pinyin\Pinyin())->permalink($normalized);
                if ($slug !== '') {
                    return $slug;
                }
            } catch (\Throwable $e) {
            }
        }
        return trim(strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $s)), '-');
    }

    private static function config()
    {
        return Config::wechatConfig();
    }

    public static function isWeixinArticleUrl($url)
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host)) {
            return false;
        }
        return $host === 'mp.weixin.qq.com' || str_ends_with($host, '.weixin.qq.com');
    }

    private static function buildHeaders(array $cfg)
    {
        $headers = isset($cfg['http_headers']) && is_array($cfg['http_headers']) ? $cfg['http_headers'] : [];
        if (!empty($cfg['wechat_forwarded_for'])) {
            $headers[] = 'Wechat-Forwarded-For: ' . $cfg['wechat_forwarded_for'];
            $headers[] = 'X-Forwarded-For: ' . $cfg['wechat_forwarded_for'];
        }
        return $headers;
    }

    private static function curlGetContents($url, array $headers = [], $cookie = '', $proxy = '', $connectTimeout = 15, $timeout = 30, $maxRetries = 2)
    {
        $attempt = 0;
        $curl_errno = 0;
        $curl_error = '';
        $http_code = 0;
        do {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_ENCODING, '');
            if (!empty($headers)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }
            if ($cookie !== '') {
                curl_setopt($ch, CURLOPT_COOKIE, $cookie);
            }
            if ($proxy !== '') {
                curl_setopt($ch, CURLOPT_PROXY, $proxy);
            }
            $data = curl_exec($ch);
            $curl_errno = curl_errno($ch);
            $curl_error = curl_error($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $attempt++;
            if ($curl_errno === 0 && $http_code < 400) {
                return ['ok' => true, 'body' => $data, 'http_code' => $http_code];
            }
        } while ($attempt < $maxRetries);

        if ($curl_errno !== 0) {
            return ['ok' => false, 'error' => "cURL 错误（{$attempt} 次尝试后）：{$curl_error}"];
        }
        return ['ok' => false, 'error' => 'HTTP 错误码：' . $http_code];
    }

    private static function fetchImageBytes($url, array $cfg)
    {
        $fetch = function ($uri) use ($cfg) {
            $headers = isset($cfg['http_headers']) && is_array($cfg['http_headers']) ? $cfg['http_headers'] : [];
            $headers[] = 'Referer: ' . $uri;
            return self::curlGetContents(
                $uri,
                $headers,
                isset($cfg['cookie']) ? (string) $cfg['cookie'] : '',
                isset($cfg['proxy']) ? (string) $cfg['proxy'] : '',
                15,
                45,
                1
            );
        };
        $res = $fetch($url);
        if (!$res['ok'] || $res['body'] === '' || $res['body'] === false) {
            $proxied = 'https://i1.wp.com/' . preg_replace('#^https?://#i', '', $url);
            $res = $fetch($proxied);
        }
        return ($res['ok'] && $res['body'] !== '' && $res['body'] !== false) ? (string) $res['body'] : '';
    }

    private static function fetchImagesParallel(array $urls, array $cfg)
    {
        $out = [];
        if (!$urls) {
            return $out;
        }
        $mh = curl_multi_init();
        $handles = [];
        foreach ($urls as $u) {
            $ch = curl_init($u);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
            curl_setopt($ch, CURLOPT_TIMEOUT, 45);
            curl_setopt($ch, CURLOPT_ENCODING, '');
            if (!empty($cfg['http_headers']) && is_array($cfg['http_headers'])) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($cfg['http_headers'], ['Referer: ' . $u]));
            }
            if (!empty($cfg['cookie'])) {
                curl_setopt($ch, CURLOPT_COOKIE, $cfg['cookie']);
            }
            if (!empty($cfg['proxy'])) {
                curl_setopt($ch, CURLOPT_PROXY, $cfg['proxy']);
            }
            curl_multi_add_handle($mh, $ch);
            $handles[$u] = $ch;
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 0.3);
            }
        } while ($running && $st === CURLM_OK);
        foreach ($handles as $u => $ch) {
            $body = curl_multi_getcontent($ch);
            $errno = curl_errno($ch);
            curl_multi_remove_handle($mh, $ch);
            if ($errno === 0 && $body !== '' && $body !== false) {
                $out[$u] = (string) $body;
            }
        }
        curl_multi_close($mh);
        return $out;
    }

    private static function uploadBytesParallel(array $bytesMap)
    {
        $keys = Config::imgbbKeys();
        if (!$keys || !$bytesMap) {
            return [];
        }
        $results = [];
        $queue = $bytesMap;
        for ($round = 0; $round < 3 && $queue; $round++) {
            $mh = curl_multi_init();
            $handles = [];
            $keyIdx = 0;
            foreach ($queue as $u => $bytes) {
                if (count($handles) >= count($keys)) {
                    break;
                }
                $key = $keys[$keyIdx % count($keys)];
                $keyIdx++;
                $ch = curl_init(Config::imgbbUploadUrl());
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, ['key' => $key, 'image' => base64_encode($bytes), 'name' => 'wx_thumb_' . substr(uniqid(), -8)]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 60);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_multi_add_handle($mh, $ch);
                $handles[$u] = $ch;
            }
            do {
                $st = curl_multi_exec($mh, $running);
                if ($running) {
                    curl_multi_select($mh, 0.3);
                }
            } while ($running && $st === CURLM_OK);
            $next = [];
            foreach ($handles as $u => $ch) {
                $body = curl_multi_getcontent($ch);
                $errno = curl_errno($ch);
                curl_multi_remove_handle($mh, $ch);
                $r = $errno === 0 ? json_decode((string) $body, true) : null;
                if (is_array($r) && !empty($r['success']) && isset($r['data']['url'])) {
                    $results[$u] = ['ok' => true, 'url' => (string) $r['data']['url']];
                } else {
                    $next[$u] = $queue[$u];
                }
            }
            curl_multi_close($mh);
            $queue = $next;
            if ($queue) {
                usleep($round === 0 ? 1100000 : 900000);
            }
        }
        foreach ($queue as $u => $bytes) {
            $results[$u] = ['ok' => false, 'error' => '上传失败（多次重试后）。'];
        }
        return $results;
    }

    private static function parseArticleHtml($html, $url, $httpCode = 200)
    {
        $diag = self::buildDiag($html, $httpCode);
        $marks = self::pageMarkers($html);

        if (in_array('风控验证页', $marks, true) || in_array('安全验证页', $marks, true) || in_array('验证码拦截', $marks, true) || in_array('频率限制', $marks, true)) {
            return ['error' => '公众号对当前服务器 IP 返回了风控验证页（' . implode('/', $marks) . '）。建议在 wechat.cookie.php 配置 cookie 后重试。', 'diag' => $diag];
        }
        if (in_array('需在微信内打开', $marks, true)) {
            return ['error' => '该文章需要登录微信后在客户端打开（未授权网页访问）。可尝试在 wechat.cookie.php 配置 cookie。', 'diag' => $diag];
        }
        if (in_array('文章已删除', $marks, true) || in_array('文章已撤回', $marks, true) || in_array('文章违规被封', $marks, true)) {
            return ['error' => '该文章不可访问：' . implode('/', $marks) . '。', 'diag' => $diag];
        }

        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $ok = $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        if (!$ok || !$doc->documentElement) {
            return ['error' => 'HTML 解析失败（页面不是标准文章页？）。', 'diag' => $diag];
        }
        $x = new \DOMXPath($doc);

        $title = self::xtext($x, '//h1[contains(@class,"rich_media_title")]');
        if ($title === '') {
            $title = self::xtext($x, '//*[@id="activity-name"]');
        }
        if ($title === '') {
            $title = self::xtext($x, '//meta[@property="og:title"]');
        }

        $author = self::xtext($x, '//*[@id="js_name"]');
        if ($author === '') {
            $author = self::xtext($x, '//*[contains(@class,"rich_media_meta_nickname")]');
        }

        $pubtime = self::xtext($x, '//*[@id="publish_time"]');
        if ($pubtime === '') {
            if (preg_match("/var createTime\s*=\s*'([^']+)'/", $html, $m)) {
                $pubtime = $m[1];
            } elseif (preg_match("/var createTimestamp\s*=\s*'?(\d{10})'?/", $html, $m2)) {
                $pubtime = date('Y-m-d H:i', (int) $m2[1]);
            }
        }

        $desc = self::xtext($x, '//meta[@name="description"]');
        if ($desc === '') {
            $desc = self::xtext($x, '//meta[@property="og:description"]');
        }

        $contentNode = self::findContentNode($x);
        if (!$contentNode) {
            return ['error' => '未找到正文节点（js_content）——' . $diag, 'diag' => $diag];
        }

        $images = [];
        self::cleanContentNode($contentNode, $images, $doc);
        $contentHtml = self::innerHtml($contentNode, $doc);
        $contentText = self::htmlToText($contentHtml);

        $uniq = [];
        foreach ($images as $img) {
            $uniq[$img['url']] = $img;
        }
        $images = array_values($uniq);

        $cover = self::xtext($x, '//meta[@property="og:image"]');
        $coverNodeImg = $x->query('//div[@id="js_cover_area"]//img|//img[@alt="cover_image"]|//img[contains(@class,"cover_img")]')->item(0);
        if (!is_object($coverNodeImg)) {
            $cover = $cover !== '' ? self::absUrl($cover) : ($images[0]['url'] ?? '');
        } else {
            $cover = self::absUrl($coverNodeImg->getAttribute('data-src') ?: $coverNodeImg->getAttribute('src'));
        }

        if ($desc === '') {
            $desc = mb_substr($contentText, 0, 100) . '…';
        }

        $id = str_replace('.', '', uniqid(time(), true));

        return [
            'id'           => $id,
            'url'          => $url,
            'title'        => $title,
            'author'       => $author,
            'publish_time' => $pubtime,
            'description'  => $desc,
            'cover'        => $cover,
            'word_count'   => mb_strlen(preg_replace('/\s+/u', '', $contentText)),
            'images_count' => count($images),
            'images'       => $images,
            'content_html' => $contentHtml,
            'content_text' => $contentText,
            'fetched_at'   => date('Y-m-d H:i:s'),
            'http_code'    => (int) $httpCode,
        ];
    }

    private static function absUrl($u)
    {
        $u = trim((string) $u);
        if ($u === '') {
            return '';
        }
        if (str_starts_with($u, '//')) {
            return 'https:' . $u;
        }
        if (str_starts_with($u, 'http://') || str_starts_with($u, 'https://')) {
            return $u;
        }
        return '';
    }

    private static function xtext(\DOMXPath $x, $expr)
    {
        $n = $x->query($expr)->item(0);
        if (!$n) {
            return '';
        }
        if ($n->nodeType === XML_ELEMENT_NODE && $n->nodeName === 'meta') {
            return trim(preg_replace('/\s+/u', ' ', $n->getAttribute('content')));
        }
        return trim(preg_replace('/\s+/u', ' ', $n->textContent));
    }

    private static function findContentNode(\DOMXPath $x)
    {
        $n = $x->query('//*[@id="js_content"]')->item(0);
        if ($n) {
            return $n;
        }
        return $x->query('//div[contains(@class,"rich_media_content")]')->item(0);
    }

    private static function cleanContentNode($node, array &$images, \DOMDocument $doc)
    {
        $xp = new \DOMXPath($doc);

        $removes = $xp->query('.//script|.//style|.//*[local-name()="mp-style-type"]', $node);
        foreach ($removes as $rNode) {
            if ($rNode->parentNode) {
                $rNode->parentNode->removeChild($rNode);
            }
        }

        $hidden = $xp->query('.//*[contains(@style,"display: none") or contains(@style,"display:none")]', $node);
        foreach ($hidden as $hNode) {
            if ($hNode->parentNode) {
                $hNode->parentNode->removeChild($hNode);
            }
        }

        $links = $xp->query('.//a[contains(@class,"mp_article_text_link")]', $node);
        foreach ($links as $a) {
            $span = $doc->createElement('span');
            while ($a->firstChild) {
                $span->appendChild($a->firstChild);
            }
            $a->parentNode->replaceChild($span, $a);
        }

        $imgs = $xp->query('.//img', $node);
        foreach ($imgs as $img) {
            $url = self::absUrl($img->getAttribute('data-src'));
            if ($url === '') {
                $src = (string) $img->getAttribute('src');
                if (str_starts_with($src, 'data:') || $src === '' || $src === 'about:blank') {
                    $url = self::absUrl($img->getAttribute('data-backsrc'));
                } else {
                    $url = self::absUrl($src);
                }
            }
            if ($url !== '') {
                $img->setAttribute('src', $url);
                $style = $img->getAttribute('style');
                $img->removeAttribute('data-src');
                $img->removeAttribute('data-backsrc');
                if (stripos($style, 'visibility') !== false) {
                    $img->setAttribute('style', preg_replace('/visibility\s*:\s*(hidden|inherit)\s*;?/i', '', $style));
                }
                $ext = '';
                if (preg_match('/wx_fmt=(\w+)/', $url, $m)) {
                    $ext = strtolower($m[1]);
                } elseif (preg_match('/\.(jpg|jpeg|png|gif|webp|bmp|svg)(?:$|\?)/i', $url, $m2)) {
                    $ext = strtolower($m2[1]);
                }
                $images[] = [
                    'url' => $url,
                    'alt' => trim((string) $img->getAttribute('alt')),
                    'w'   => (string) ($img->getAttribute('data-w') ?: $img->getAttribute('width')),
                    'h'   => (string) ($img->getAttribute('data-h') ?: $img->getAttribute('height')),
                    'ext' => $ext,
                ];
            } else {
                $img->parentNode->removeChild($img);
            }
            $img->removeAttribute('id');
        }

        $ids = $xp->query('.//*[@id]', $node);
        foreach ($ids as $iNode) {
            $iNode->removeAttribute('id');
        }
    }

    private static function innerHtml($node, \DOMDocument $doc)
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    private static function htmlToText($html)
    {
        $text = $html;
        $text = preg_replace('/<(br|hr)\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/(p|div|section|h[1-6]|li|blockquote|tr|ul|ol|table|figure|figcaption|pre)>/i', "\n", $text);
        $text = preg_replace('/<(p|div|section|h[1-6]|li|blockquote|tr|ul|ol|table|figure|figcaption|pre)[^>]*>/i', "\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[^\S\n]+/u', ' ', $text);
        $text = preg_replace('/[ \t]+\n/u', "\n", $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);
        return trim($text);
    }

    private static function pageMarkers($html)
    {
        $marks = [
            '环境异常'                => '风控验证页',
            '请在微信客户端打开链接'  => '需在微信内打开',
            '该内容已被发布者删除'    => '文章已删除',
            '此内容因违规无法查看'    => '文章违规被封',
            '抱歉，该文章已删除'      => '文章已删除',
            '该内容已被发布者撤回'    => '文章已撤回',
            '访问过于频繁'            => '频率限制',
            '安全验证'                => '安全验证页',
            '验证码'                  => '验证码拦截',
            '系统繁忙'                => '系统繁忙',
            '已被限制访问'            => '账号被限制',
            '收款方账户状态异常'      => '支付页异常',
        ];
        $found = [];
        foreach ($marks as $kw => $label) {
            if (str_contains($html, $kw)) {
                $found[] = $label;
            }
        }
        return $found;
    }

    private static function buildDiag($html, $httpCode)
    {
        preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m);
        $pageTitle = trim(preg_replace('/\s+/u', ' ', strip_tags($m[1] ?? '')));
        $diag = [
            'HTTP ' . (int) $httpCode,
            '大小 ' . (int) round(strlen($html) / 1024) . 'KB',
        ];
        if ($pageTitle !== '') {
            $diag[] = '页面<title>=' . mb_strimwidth($pageTitle, 0, 40, '…');
        }
        $diag[] = 'js_content_container=' . (str_contains($html, 'js_content_container') ? '有' : '无');
        $marks = self::pageMarkers($html);
        if ($marks) {
            $diag[] = '异常标记=' . implode('/', $marks);
        }
        return implode('；', $diag);
    }
}