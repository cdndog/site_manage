<?php

/* ── 微信抓取登录态配置（独立于 global_config.php） ──
 * 本文件与 global_config.php 同目录。内容合并覆盖 global_config.php 的 wechat 段：
 *   - cookie：公众号登录 Cookie（浏览器登录 mp.weixin.qq.com 后复制，可避免风控验证页）
 *   - http_headers：整组替换（留空 [] 则保留默认浏览器请求头）
 *   - wechat_forwarded_for / proxy / connect_timeout / timeout / max_retries：按需覆盖
 */
return [
    'cookie' => '',
    'http_headers' => [],
    'wechat_forwarded_for' => '',
    'proxy' => '',
    'connect_timeout' => 15,
    'timeout' => 30,
    'max_retries' => 2,
];