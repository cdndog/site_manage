<?php

declare(strict_types=1);

namespace App\Services;

/**
 * GitHub API 服务：创建私有仓库、写入 README.md、上传本地目录到仓库根目录。
 * Token 由调用方传入（来自文件化配置 accounts.json 或手工录入），服务端不持久化。
 */
class GitHubService
{
    const API = 'https://api.github.com';
    const TIMEOUT = 60;
    const CONNECT_TIMEOUT = 30;

    /** 从归档 URL 提取域名：http://www.npe88.com:80/ → www.npe88.com */
    public static function domainFromArchiveUrl(string $url): string
    {
        $host = trim($url);
        if ($host === '') {
            return '';
        }
        $host = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $host);
        $parts = preg_split('~[/?#]~', $host);
        $host = $parts[0] ?? '';
        $host = preg_replace('#:\d+$#', '', $host);
        return trim($host);
    }

    /** 净化仓库名（小写，仅字母/数字/-/_/.，不以 ./- 开头结尾） */
    public static function sanitizeRepoName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9._-]+/', '-', $name);
        return trim((string)$name, '.-');
    }

    /** GitHub Token 格式校验（ghp_/gho_/ghu_/ghs_/ghr_/github_pat_ 前缀 + 长度） */
    public static function isValidToken(string $token): bool
    {
        return (bool)preg_match('/^(ghp_|gho_|ghu_|ghs_|ghr_|github_pat_)[A-Za-z0-9_]{15,}$/', trim($token));
    }

    /** 带鉴权的 JSON 请求（POST/PUT）；返回 [code, decoded]。
     *  SOPS_TESTING 下可用 $GLOBALS['__githubApiStub']（"METHOD url前缀" => ['code'=>int,'body'=>array]）离线注入。 */
    private static function request(string $method, string $url, array $payload, string $token): array
    {
        if (defined('SOPS_TESTING') && isset($GLOBALS['__githubApiStub']) && is_array($GLOBALS['__githubApiStub'])) {
            foreach ($GLOBALS['__githubApiStub'] as $key => $value) {
                $k = (string)$key;
                $prefix = $k;
                if (strpos($k, ' ') !== false) {
                    list($m, $prefix) = explode(' ', $k, 2);
                    if (strtoupper($m) !== strtoupper($method)) {
                        continue;
                    }
                }
                if (strpos($url, $prefix) === 0) {
                    if (is_array($value) && isset($value['code'], $value['body'])) {
                        return [(int)$value['code'], $value['body']];
                    }
                    return [200, $value];
                }
            }
            throw new \RuntimeException('github api stub 未覆盖该请求: ' . $method . ' ' . $url);
        }
        if (!function_exists('curl_init')) {
            return [0, ['message' => 'curl 扩展不可用']];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_USERAGENT => 'wayback-tools',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'Authorization: Bearer ' . $token,
                'X-GitHub-Api-Version: 2022-11-28',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return [$code, json_decode((string)$body, true)];
    }

    /** 创建私有仓库；返回 [ok, info|msg] */
    public static function createRepo(string $token, string $name, string $desc): array
    {
        [$code, $data] = self::request('POST', self::API . '/user/repos', [
            'name' => $name,
            'description' => $desc,
            'private' => true,
            'auto_init' => false,
        ], $token);
        if ($code >= 400 || !is_array($data)) {
            $msg = is_array($data) && isset($data['message']) ? $data['message'] : "HTTP $code";
            return [false, "创建仓库失败: $msg"];
        }
        $owner = (string)($data['owner']['login'] ?? '');
        $repo = (string)($data['name'] ?? $name);
        $html = (string)($data['html_url'] ?? '');
        return [true, ['owner' => $owner, 'repo' => $repo, 'html' => $html]];
    }

    /** 写入 README.md；返回 [ok, url|msg] */
    public static function createReadme(string $token, string $owner, string $repo, string $content): array
    {
        $url = self::API . '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/contents/README.md';
        [$code, $data] = self::request('PUT', $url, [
            'message' => 'docs: add README.md',
            'content' => base64_encode($content),
        ], $token);
        if ($code >= 400 || !is_array($data)) {
            $msg = is_array($data) && isset($data['message']) ? $data['message'] : "HTTP $code";
            return [false, "写入 README 失败: $msg"];
        }
        return [true, (string)($data['content']['html_url'] ?? '')];
    }

    /** 解析上传目录配置为 [label => [abs,prefix]]（路径相对项目根/绝对，防穿越） */
    public static function repoUploadDirs(): array
    {
        $config = ArchiveConfigService::uploads();
        $base = (string)($config['base'] ?? '');
        $dirs = [];
        foreach ((array)($config['upload_dirs'] ?? []) as $d) {
            $label = (string)($d['label'] ?? ($d['id'] ?? ''));
            $path = (string)($d['path'] ?? '');
            if ($path === '') {
                continue;
            }
            $abs = self::resolveUploadPath($base, $path);
            if ($abs === false) {
                continue;
            }
            $dirs[$label] = [
                'abs' => $abs,
                'prefix' => (string)($d['repo_prefix'] ?? $d['id'] ?? ''),
            ];
        }
        return $dirs;
    }

    /**
     * 解析单个上传目录绝对路径（与 wbm 一致：base+path 相对项目根，其次 path 相对项目根，
     * 再退 dataDir，最后兼容绝对路径；相对路径不按 CWD 解析）。
     */
    private static function resolveUploadPath(string $base, string $path)
    {
        $rel = [
            trim($base . '/' . $path, '/'),
            ltrim($path, '/'),
        ];
        $candidates = [];
        foreach ($rel as $r) {
            if ($r !== '') {
                $candidates[] = APP_PATH . '/' . $r;
                $candidates[] = \App\Config::dataDir() . '/' . $r;
            }
        }
        if ($path !== '' && ($path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path))) {
            $candidates[] = $path;
        }
        foreach ($candidates as $c) {
            $abs = realpath($c);
            if ($abs !== false && is_dir($abs)) {
                return $abs;
            }
        }
        return false;
    }

    /** 上传一个本地目录到仓库（递归，跳过 .DS_Store/.git）；返回 [ok, uploaded, failed, msg] */
    public static function uploadDirToRepo(string $token, string $owner, string $repo, string $localDir, string $repoPrefix): array
    {
        if (!is_dir($localDir)) {
            return [false, 0, 0, "本地目录不存在: $localDir"];
        }
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($localDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if ($file->getFilename() === '.DS_Store') {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($localDir) + 1));
            $files[] = $rel;
        }
        if (!$files) {
            return [false, 0, 0, "目录为空: $localDir"];
        }
        sort($files);
        $uploaded = 0;
        $failed = 0;
        $lastErr = '';
        foreach ($files as $rel) {
            $content = (string)@file_get_contents($localDir . '/' . $rel);
            $target = $repoPrefix !== '' ? $repoPrefix . '/' . $rel : $rel;
            $url = self::API . '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/contents/' . implode('/', array_map('rawurlencode', explode('/', $target)));
            [$code, $data] = self::request('PUT', $url, [
                'message' => "docs: add {$target}",
                'content' => base64_encode($content),
            ], $token);
            if ($code >= 400) {
                $failed++;
                $lastErr = is_array($data) && isset($data['message']) ? $data['message'] : "HTTP $code";
            } else {
                $uploaded++;
            }
        }
        $msg = $failed ? "{$uploaded} 个文件上传成功，{$failed} 个失败（{$lastErr}）" : "{$uploaded} 个文件全部上传成功";
        return [$failed === 0, $uploaded, $failed, $msg];
    }

    /** 上传全部配置目录到仓库根目录；返回 [ok, msg] */
    public static function uploadRepoDirs(string $token, string $owner, string $repo): array
    {
        $msgs = [];
        $allOk = true;
        foreach (self::repoUploadDirs() as $label => $d) {
            [$ok, $up, $fail, $msg] = self::uploadDirToRepo($token, $owner, $repo, $d['abs'], $d['prefix']);
            if (!$ok) {
                $allOk = false;
            }
            $prefix = $d['prefix'] !== '' ? $d['prefix'] : $label;
            $msgs[] = "{$prefix}/: {$msg}";
        }
        return [$allOk, implode("\n", $msgs)];
    }
}
