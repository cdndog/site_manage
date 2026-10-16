<?php
declare(strict_types=1);

// api/proxy.php — 脚本/命令行向 config/proxy.json 追加代理条目（迁移自 wbm/proxy.php，行为保持一致）
//
// 用法（token 必填，见下方说明）：
//   curl -X POST http://…/api/proxy.php \
//        -H 'X-API-Token: 你的token' \
//        -d 'proxy=user:pass@host:port'
//   或分开字段：
//        -d 'user=SUSER&pass=PASS&host=HOST&port=PORT&scheme=socks5h&name=可选名称'
//   或 JSON body（-H 'Content-Type: application/json' -d '{"proxy":"user:pass@host:port"}'）
//
// 响应：{"ok":true,"id":"..","name":"..","url":"socks5h://user:***@host:port"}
// 幂等：id 由 scheme://user@host:port 的 sha256 哈希生成（不含密码，凭据轮换 id 不变）。
//       同键条目已存在且 url 完全相同 → 原样返回，不写文件；url 不同 → 更新（凭据轮换），不重复追加。
// 安全：需在 config/proxy.json 顶层配置 "api_token": "值"，否则拒绝写入（文件缺失/损坏 → 500）。
// 数据：ArchiveConfigService::dir()/proxy.json（生产为项目根 config/proxy.json，与历史档配置页共用）。

require __DIR__ . '/../app/bootstrap.php';

use App\Services\ArchiveConfigService;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Token, X-Api-Token');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    if (!defined('SOPS_TESTING')) { exit; }
    return;
}

if (!function_exists('proxyApiMaskUrl')) {
    /** 代理地址打码显示：socks5h://user:pass@host → socks5h://user:***@host */
    function proxyApiMaskUrl(string $url): string
    {
        return (string)preg_replace('#(//[^:/@]+):[^@]+@#', '$1:***@', $url);
    }
}

if (!function_exists('proxyApiFail')) {
    /** 失败输出（返回 false 便于调用处 if (...) { return; }，测试环境下不 exit） */
    function proxyApiFail(int $http, string $msg): bool
    {
        http_response_code($http);
        echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return false;
    }
}

if (!function_exists('proxyApiMain')) {
    function proxyApiMain(): void
    {
        $allowedSchemes = ['http', 'https', 'socks4', 'socks4a', 'socks5', 'socks5h'];

        // 1. 读取 config/proxy.json（严格：缺失/损坏直接 500，与 wbm 一致）
        $cfgPath = ArchiveConfigService::dir() . '/proxy.json';
        $raw = @file_get_contents($cfgPath);
        $cfg = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($cfg)) {
            proxyApiFail(500, 'config/proxy.json 无法解析或不存在（先在【网站历史档】配置页保存代理并配置顶层 "api_token"）');
            return;
        }

        // 2. API Token 校验（X-API-Token 头 > POST token > GET token，须与 proxy.json 顶层 api_token 一致）
        $token = (string)($_SERVER['HTTP_X_API_TOKEN'] ?? ($_POST['token'] ?? ($_GET['token'] ?? '')));
        $expected = (string)($cfg['api_token'] ?? '');
        if ($token === '' && $expected !== '') {
            proxyApiFail(401, '缺少 API Token：请通过 X-API-Token 请求头或 token 参数传递');
            return;
        }
        if ($expected === '' || !hash_equals($expected, $token)) {
            proxyApiFail(401, '未配置或 token 不匹配：请在 config/proxy.json 顶层添加 "api_token": "值"');
            return;
        }

        // 3. 解析输入（支持 form-urlencoded / multipart / JSON body）
        $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
        if (stripos($contentType, 'application/json') !== false) {
            $input = json_decode((string)file_get_contents('php://input'), true);
            if (!is_array($input)) {
                proxyApiFail(400, 'JSON body 解析失败');
                return;
            }
        } else {
            $input = $_POST;
        }

        $rawProxy = trim((string)($input['proxy'] ?? ''));
        if ($rawProxy !== '') {
            // 组合写法：user:pass@host:port（无协议前缀时默认 socks5h）
            $url = strpos($rawProxy, '://') !== false ? $rawProxy : 'socks5h://' . $rawProxy;
        } else {
            // 分开字段写法
            $host = trim((string)($input['host'] ?? ''));
            $port = trim((string)($input['port'] ?? ''));
            if ($host === '' || $port === '') {
                proxyApiFail(400, '缺少 host/port 字段（或使用 proxy=user:pass@host:port 组合写法）');
                return;
            }
            $scheme = strtolower(trim((string)($input['scheme'] ?? 'socks5h')));
            $user = (string)($input['user'] ?? '');
            $pass = (string)($input['pass'] ?? '');
            $url = $user !== '' || $pass !== ''
                ? "{$scheme}://{$user}:{$pass}@{$host}:{$port}"
                : "{$scheme}://{$host}:{$port}";
        }

        // 4. 校验并规范化
        $parts = parse_url($url);
        if (!$parts || !isset($parts['host'], $parts['port'])) {
            proxyApiFail(400, '无法解析代理地址，应为 user:pass@host:port 或 scheme://user:pass@host:port');
            return;
        }
        if (strpos($parts['host'], '@') !== false || isset($parts['path'])) {
            proxyApiFail(400, '代理地址格式非法（host 含 @ 或存在多余路径）');
            return;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? 'socks5h'));
        if (!in_array($scheme, $allowedSchemes, true)) {
            proxyApiFail(400, '不支持的协议 scheme：' . $scheme . '（允许：' . implode('/', $allowedSchemes) . '）');
            return;
        }
        $port = (int)$parts['port'];
        if ($port < 1 || $port > 65535) {
            proxyApiFail(400, '端口非法：' . $parts['port']);
            return;
        }
        $host = trim((string)($parts['host'] ?? ''), '[]'); // parse_url 对 IPv6 可能带回方括号
        $user = (string)($parts['user'] ?? '');
        $pass = (string)($parts['pass'] ?? '');
        if ($user !== '' && (strpos($user, ' ') !== false || strpos($user, ':') !== false || strpos($user, '@') !== false)) {
            proxyApiFail(400, '用户名非法（不能含 空格/:@）');
            return;
        }
        if (strpos($pass, ' ') !== false) {
            proxyApiFail(400, '密码非法（不能含空格）');
            return;
        }
        // 重建规范 URL（IPv6 加回方括号）
        $hostPart = strpos($host, ':') !== false ? "[{$host}]" : $host;
        $url = $scheme . '://' . ($user !== '' ? "{$user}:{$pass}@" : '') . "{$hostPart}:{$port}";

        // 5. 幂等处理：id 由身份键 scheme://user@host:port 的 sha256 生成（不含密码，凭据轮换 id 不变）
        $list = isset($cfg['proxies']) && is_array($cfg['proxies']) ? $cfg['proxies'] : [];
        $key = "{$scheme}://{$user}@{$hostPart}:{$port}";
        $id = 'p-' . substr(hash('sha256', $key), 0, 12);
        $found = null;
        foreach ($list as $i => $e) {
            if (!is_array($e)) {
                continue;
            }
            $eParts = parse_url((string)($e['url'] ?? ''));
            $eHost = trim((string)($eParts['host'] ?? ''), '[]'); // 去括号后统一比较
            $eHostPart = strpos($eHost, ':') !== false ? "[{$eHost}]" : $eHost;
            $eKey = strtolower((string)($eParts['scheme'] ?? '')) . '://' . (string)($eParts['user'] ?? '') . "@{$eHostPart}:" . (int)($eParts['port'] ?? 0);
            if ($eKey === $key) {
                $found = $i;
                break;
            }
        }

        $changed = false;
        if ($found !== null) {
            $e = $list[$found];
            $sameUrl = (string)($e['url'] ?? '') === $url;
            if (!$sameUrl) {
                $newName = trim((string)($input['name'] ?? ''));
                $e['name'] = $newName !== '' ? $newName : (string)($e['name'] ?? '');
                $e['url'] = $url;
                $list[$found] = $e;
                $cfg['proxies'] = $list;
                $changed = true;
            }
            $result = ['ok' => true, 'updated' => !$sameUrl, 'id' => (string)($e['id'] ?? $id), 'name' => (string)($e['name'] ?? ''), 'url' => proxyApiMaskUrl($url)];
        } else {
            $name = trim((string)($input['name'] ?? ''));
            if ($name === '') {
                $name = "代理 {$hostPart}:{$port}";
            }
            $list[] = ['id' => $id, 'name' => $name, 'url' => $url];
            $cfg['proxies'] = $list;
            $changed = true;
            $result = ['ok' => true, 'updated' => false, 'id' => $id, 'name' => $name, 'url' => proxyApiMaskUrl($url)];
        }

        // 6. 有变更才原子写入（保留 api_token 等其它顶层键与既有条目）
        if ($changed && !ArchiveConfigService::saveJson('proxy.json', $cfg)) {
            proxyApiFail(500, '写入 config/proxy.json 失败（权限不足？）');
            return;
        }

        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

proxyApiMain();
