<?php

/* ── 微信抓取登录态配置（独立于 global_config.php） ──
 * 本文件与 global_config.php 同目录。内容合并覆盖 global_config.php 的 wechat 段：
 *   - cookie：公众号登录 Cookie（浏览器登录 mp.weixin.qq.com 后复制，可避免风控验证页）
 *   - http_headers：整组替换（留空 [] 则保留默认浏览器请求头）
 *   - wechat_forwarded_for / proxy / connect_timeout / timeout / max_retries：按需覆盖
 */
return [
    'cookie' => '1111',
    'http_headers' => array (
  0 => 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36',
  1 => 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
  2 => 'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
  3 => 'Accept-Encoding: identity',
  4 => 'Cache-Control: no-cache',
  5 => 'Pragma: no-cache',
  6 => 'Upgrade-Insecure-Requests: 1',
  7 => 'Sec-Ch-Ua: "Not=A?Brand";v="99", "Google Chrome";v="151", "Chromium";v="151"',
  8 => 'Sec-Ch-Ua-Mobile: ?0',
  9 => 'Sec-Ch-Ua-Platform: "macOS"',
  10 => 'Sec-Fetch-Dest: document',
  11 => 'Sec-Fetch-Mode: navigate',
  12 => 'Sec-Fetch-Site: none',
  13 => 'Sec-Fetch-User: ?1',
),
    'wechat_forwarded_for' => '',
    'proxy' => '',
    'connect_timeout' => 15,
    'timeout' => 30,
    'max_retries' => 2,
];
