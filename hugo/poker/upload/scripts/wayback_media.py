#!/usr/bin/env python3
"""
wayback_media.py — 并发下载域名的所有媒体文件（图片、视频、音频等）从 Wayback Machine。

用法:
  python wayback_media.py <域名> <YYYYMMDD|YYYYMMDDHHMMSS>
  python wayback_media.py www.retkcon.com 20150617123705

选项:
  --output <dir>      下载根目录 (默认 ./static)
  --window <天数>     只取日期±N天内的快照
  --timeout <s>       单请求超时秒数 (默认 60)
  --retries <N>       每文件最大尝试次数 (默认 5)
  --queue-timeout <s> 整批硬上限秒数 (默认 3600)
  --workers <N>       并发下载数 (默认 8)
  --limit <N>         限制下载文件数量
  --all-types         包含 css/js/html 等非媒体文件
  --verbose           打印每个文件日志到 stderr

输出: 日期  URL  保存路径

依赖:
  pip install requests
  若缺少 requests: bash setup_venv.sh && source venv/bin/activate
"""

import argparse
import concurrent.futures
import os
import re
import ssl
import sys
import time

try:
    import requests
    from urllib3.poolmanager import PoolManager
    from requests.adapters import HTTPAdapter
except ImportError:
    print("[wayback] 缺少依赖 requests，请安装: pip install requests", file=sys.stderr)
    sys.exit(1)

CDX_API = "https://web.archive.org/cdx/search/cdx"
ARCHIVE = "https://web.archive.org/web"
USER_AGENT = "Mozilla/5.0 (compatible; wayback_media/2.0)"

_ssl_ctx = ssl.create_default_context()
_ssl_ctx.check_hostname = False
_ssl_ctx.verify_mode = ssl.CERT_NONE
_ssl_ctx.set_ciphers("DEFAULT:@SECLEVEL=0")


class _ArchiveAdapter(HTTPAdapter):
    def init_poolmanager(self, *args, **kwargs):
        kwargs["ssl_context"] = _ssl_ctx
        return super().init_poolmanager(*args, **kwargs)


_session = requests.Session()
_session.headers["User-Agent"] = USER_AGENT
_session.mount("http://", _ArchiveAdapter())
_session.mount("https://", _ArchiveAdapter())

MEDIA_RE = re.compile(
    r"\.(jpe?g|png|gif|svg|webp|bmp|ico|avif|tiff?|mp4|webm|m4v|mov|avi|mkv|flv|wmv|"
    r"mp3|ogg|oga|wav|flac|aac|m4a|wma|pdf|epub|zip|tar|gz|7z|rar)$",
    re.I,
)

# HTML 错误页特征，命中即视为无效内容
ERROR_RE = re.compile(
    r"(<html|404 Not Found|Bad Gateway|Service Unavailable|Too Many Requests|"
    r"You have been blocked|Robot or human|The Wayback Machine has not archived)",
    re.I,
)


def fetch_cdx(domain):
    params = {
        "url": domain,
        "matchType": "domain",
        "limit": "200000",
        "fl": "timestamp,original,statuscode,mimetype",
        "filter": "statuscode:200",
    }
    r = _session.get(CDX_API, params=params, timeout=60)
    r.raise_for_status()
    rows = []
    for line in r.text.splitlines():
        parts = line.split()
        if len(parts) >= 2:
            rows.append((parts[0], parts[1], parts[2] if len(parts) > 2 else ""))
    return rows


def select_snapshots(rows, date_norm, window, all_types):
    """每个唯一 URL 选最接近指定日期的快照。"""
    best = {}
    for ts, url, _mime in rows:
        if window > 0 and abs(int(ts) - date_norm) > window * 100000000:
            continue
        if not all_types and not MEDIA_RE.search(url):
            continue
        d = abs(int(ts) - date_norm)
        if url not in best or d < best[url][0]:
            best[url] = (d, ts, url)
    return [v[1:] for v in best.values()]


def rel_path(url):
    """去掉 host，仅保留 URL 路径（保持目录层次结构）。"""
    m = re.search(r"https?://[^/]+(/[^?]*)?(\?.*)?", url)
    p = m.group(1) if m and m.group(1) else "/index"
    p = re.sub(r"[^a-zA-Z0-9._/\-]", "_", p)
    return p.lstrip("/") or "index"


def is_invalid_content(data):
    """检查内容是否为 HTML 错误页/空文件。"""
    if not data:
        return True
    return bool(ERROR_RE.search(data[:512].decode("latin-1")))


def download_one(item, args):
    ts, url = item
    dest = os.path.join(args.output, rel_path(url))
    os.makedirs(os.path.dirname(dest), exist_ok=True)
    arc = f"{ARCHIVE}/{ts}id_/{url}"

    for attempt in range(1, args.retries + 1):
        try:
            r = _session.get(
                arc,
                timeout=(15, args.timeout),
                stream=True,
            )
            if r.status_code != 200:
                raise RuntimeError(f"HTTP {r.status_code}")
            # 流式读取，校验前 512 字节
            chunk = r.raw.read(512, decode_content=True)
            if is_invalid_content(chunk):
                raise RuntimeError("error/empty content")
            with open(dest, "wb") as f:
                f.write(chunk)
                for data in r.iter_content(chunk_size=65536):
                    f.write(data)
            if args.verbose:
                print(f"[wayback] OK  {ts}  {url}  ->  {dest}", file=sys.stderr)
            return f"{ts}  {url}  {dest}"
        except Exception as e:
            if os.path.exists(dest):
                os.remove(dest)
            if attempt < args.retries:
                time.sleep(attempt * 2)
            elif args.verbose:
                print(f"[wayback] SKIP  {ts}  {url}  ({e})", file=sys.stderr)
    return None


def main():
    ap = argparse.ArgumentParser(description="并发下载域名媒体文件", add_help=False)
    ap.add_argument("domain")
    ap.add_argument("date")
    ap.add_argument("--output", default="")
    ap.add_argument("--window", type=int, default=0)
    ap.add_argument("--timeout", type=int, default=60)
    ap.add_argument("--retries", type=int, default=5)
    ap.add_argument("--queue-timeout", type=int, default=3600)
    ap.add_argument("--workers", type=int, default=8)
    ap.add_argument("--limit", type=int, default=0)
    ap.add_argument("--all-types", action="store_true")
    ap.add_argument("--verbose", action="store_true")
    ap.add_argument("-h", "--help", action="help", help="show this help and exit")
    args = ap.parse_args()

    domain = re.sub(r"[^a-zA-Z0-9.\-]", "", args.domain)
    date = re.sub(r"[^0-9]", "", args.date)
    if not domain or len(date) < 8:
        ap.print_usage()
        sys.exit(1)

    args.timeout = max(5, args.timeout)
    args.retries = max(1, args.retries)
    args.workers = max(1, args.workers)
    args.window = max(0, args.window)
    args.queue_timeout = max(1, args.queue_timeout)
    if not args.output:
        args.output = "./static"

    date_norm = int(date.ljust(14, "0"))

    print(f"[wayback] Fetching all CDX snapshots for {domain} ...", file=sys.stderr)
    rows = fetch_cdx(domain)
    print(f"[wayback] {len(rows)} archived (HTTP 200) resources found.", file=sys.stderr)
    if not rows:
        print(f"[wayback] No archives found for {domain}.", file=sys.stderr)
        sys.exit(0)

    items = select_snapshots(rows, date_norm, args.window, args.all_types)
    if args.limit > 0:
        items = items[: args.limit]

    print(f"[wayback] {len(items)} media file(s) selected for {date}.", file=sys.stderr)
    if not items:
        print(f"[wayback] No media files found for {domain} near {date}.", file=sys.stderr)
        sys.exit(0)

    os.makedirs(args.output, exist_ok=True)
    print(
        f"[wayback] Downloading to {args.output} "
        f"(workers={args.workers}, timeout={args.timeout}s, retries={args.retries}, "
        f"batch={args.queue_timeout}s) ...",
        file=sys.stderr,
    )

    ok = 0
    start = time.time()
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.workers) as pool:
        futs = [pool.submit(download_one, it, args) for it in items]
        for fut in concurrent.futures.as_completed(futs):
            result = fut.result()
            if result:
                ok += 1
                print(result)
            # 整批硬上限
            if time.time() - start > args.queue_timeout:
                print("[wayback] batch timeout reached, stopping.", file=sys.stderr)
                for f in futs:
                    f.cancel()
                break

    print(f"[wayback] Done. {ok}/{len(items)} files in {args.output}", file=sys.stderr)


if __name__ == "__main__":
    main()
