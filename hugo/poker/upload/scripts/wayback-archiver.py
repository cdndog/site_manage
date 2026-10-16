#!/usr/bin/env python3
"""
wayback-archiver.py — 从 Wayback Machine 下载归档页面，生成 Hugo 兼容的 Markdown 文件。

用法:
  # 归档单个页面（默认精确匹配 URL）
  python wayback-archiver.py https://web.archive.org/web/20161017051908/http://www.hd727.com/

  # 归档整个站点（--prefix 匹配该 URL 下的所有页面）
  python wayback-archiver.py https://web.archive.org/web/20161017051908/http://www.hd727.com/ --prefix

  # 全站爬取模式：CDX 发现全站 URL → 展示目录树 → 并发下载文章
  python wayback-archiver.py https://web.archive.org/web/20161017051908/http://www.hd727.com/ --crawl

  # 全站爬取 + 限定目录 + 控制并发数
  python wayback-archiver.py https://web.archive.org/web/20161017051908/http://www.hd727.com/dongman/ --crawl --workers 5

  # 指定日期范围
  python wayback-archiver.py https://example.com --from 20200101 --to 20230101

依赖:
  pip install requests beautifulsoup4
"""

import argparse
import json
import os
import re
import sys
import time
import traceback
from datetime import datetime, timezone
from urllib.parse import urlparse

try:
    import requests
    from bs4 import BeautifulSoup
except ImportError:
    print("缺少依赖，请安装: pip install requests beautifulsoup4", file=sys.stderr)
    sys.exit(1)

CDX_API = "https://web.archive.org/cdx/search/cdx"
USER_AGENT = "Mozilla/5.0 (compatible; wayback-archiver/1.0)"

import ssl
from urllib3.poolmanager import PoolManager
from requests.adapters import HTTPAdapter

_ssl_ctx = ssl.create_default_context()
_ssl_ctx.check_hostname = False
_ssl_ctx.verify_mode = ssl.CERT_NONE
_ssl_ctx.set_ciphers('DEFAULT:@SECLEVEL=0')

class _ArchiveAdapter(HTTPAdapter):
    def init_poolmanager(self, *args, **kwargs):
        kwargs['ssl_context'] = _ssl_ctx
        return super().init_poolmanager(*args, **kwargs)

_session = requests.Session()
_session.headers['User-Agent'] = USER_AGENT
_session.mount('https://', _ArchiveAdapter())


def extract_original_url(archive_url):
    m = re.search(r'/web/(\d+)/(https?://[^\s?]+)', archive_url)
    if m:
        return m.group(2)
    if archive_url.startswith('http'):
        return archive_url
    raise ValueError(f"无法从输入中提取原始 URL: {archive_url}")


def extract_timestamp(archive_url):
    """从 Wayback Machine URL 中提取时间戳 YYYYMMDDhhmmss，无则返回 None."""
    m = re.search(r'/web/(\d{14})/', archive_url)
    if m:
        return m.group(1)
    return None


def get_snapshots(original_url, prefix=False, from_date=None, to_date=None):
    params = {
        'url': original_url,
        'output': 'json',
        'fl': 'timestamp,original,statuscode,mimetype',
        'collapse': 'timestamp:8',
    }
    if prefix:
        params['matchType'] = 'prefix'
    if from_date:
        params['from'] = from_date
    if to_date:
        params['to'] = to_date

    print(f"[CDX] 查询: {original_url}  (prefix={prefix})")
    for attempt in range(3):
        try:
            resp = _session.get(CDX_API, params=params,
                                timeout=60)
            resp.raise_for_status()
            break
        except requests.RequestException as e:
            if attempt == 2:
                raise
            print(f"[CDX] 重试 {attempt+1}/3: {e}")
            time.sleep(3)
    rows = resp.json()
    results = []
    for row in rows[1:]:
        ts, orig = row[0], row[1]
        status_raw = row[2] if len(row) > 2 else '-'
        mime = row[3] if len(row) > 3 else ''
        try:
            status_code = int(status_raw)
        except ValueError:
            continue
        if status_code == 200:
            results.append((ts, orig, mime))
    print(f"[CDX] 找到 {len(results)} 个成功快照")
    return results


def decode_content(resp):
    raw = resp.content
    encoding = None
    ct = resp.headers.get('Content-Type', '')
    m = re.search(r'charset=([\w-]+)', ct, re.I)
    if m:
        encoding = m.group(1)
    else:
        m = re.search(b'<meta[^>]+charset=["\']?([\\w-]+)', raw, re.I)
        if m:
            encoding = m.group(1).decode().lower()
        else:
            m = re.search(b'content="[^"]*charset=([\\w-]+)', raw, re.I)
            if m:
                encoding = m.group(1).decode().lower()
    if encoding:
        try:
            return raw.decode(encoding)
        except (UnicodeDecodeError, LookupError):
            pass
    for enc in ('utf-8', 'gbk', 'gb2312', 'big5', 'shift_jis', 'euc-kr', 'latin-1'):
        try:
            return raw.decode(enc)
        except UnicodeDecodeError:
            continue
    return raw.decode('utf-8', errors='replace')


def fetch_content(timestamp, original_url):
    archive_url = f"https://web.archive.org/web/{timestamp}id_/{original_url}"
    print(f"[FETCH] {archive_url}")
    for attempt in range(3):
        try:
            resp = _session.get(archive_url, timeout=(10, 120))
            resp.raise_for_status()
            break
        except requests.RequestException as e:
            if attempt == 2:
                raise
            print(f"[FETCH] 重试 {attempt+1}/3: {e}")
            time.sleep(3)
    return decode_content(resp)


def extract_metadata(soup, url):
    title = ""
    for meta in [soup.find('meta', property='og:title'),
                 soup.find('meta', attrs={'name': 'twitter:title'}),
                 soup.find('title')]:
        if meta:
            content = meta.get('content') or meta.string
            if content:
                title = content.strip()
                break

    description = ""
    for meta in [soup.find('meta', property='og:description'),
                 soup.find('meta', attrs={'name': 'description'}),
                 soup.find('meta', attrs={'name': 'twitter:description'})]:
        if meta and meta.get('content'):
            description = meta['content'].strip()
            break

    cover = ""
    for meta in [soup.find('meta', property='og:image'),
                 soup.find('meta', attrs={'name': 'twitter:image'}),
                 soup.find('meta', attrs={'name': 'thumbnail'})]:
        if meta and meta.get('content'):
            cover = meta['content'].strip()
            break

    tags = []
    tag_meta = soup.find('meta', attrs={'name': 'keywords'})
    if tag_meta and tag_meta.get('content'):
        tags = [t.strip() for t in tag_meta['content'].split(',') if t.strip()]

    return title, description, cover, tags


def html_to_md(soup, source_url):
    for tag in soup(['script', 'style', 'nav', 'header', 'footer', 'iframe', 'noscript', 'form', 'aside']):
        tag.decompose()

    body = (soup.find('article') or soup.find('main') or
            soup.find('div', class_=re.compile(r'content|post|article|entry|main')) or
            soup.body)
    if not body:
        return ""

    lines = []

    def resolve_url(src):
        if not src or src.startswith('data:'):
            return src
        from urllib.parse import urljoin
        return urljoin(source_url, src)

    for el in body.find_all(['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'ul', 'ol',
                              'blockquote', 'pre', 'img', 'hr', 'table', 'figure',
                              'div', 'section']):
        if el.name == 'hr':
            lines.append('\n---\n')
            continue
        if el.name == 'img':
            src = resolve_url(el.get('src', ''))
            alt = el.get('alt', '')
            if src:
                lines.append(f'![{alt}]({src})\n\n')
            continue
        if el.name in ('figure', 'div', 'section'):
            imgs = el.find_all('img')
            for img in imgs:
                src = resolve_url(img.get('src', ''))
                alt = img.get('alt', '')
                if src:
                    lines.append(f'![{alt}]({src})\n\n')
            continue
        if el.name == 'table':
            rows = el.find_all('tr')
            md_table = []
            for i, row in enumerate(rows):
                cells = [cell.get_text(strip=True) for cell in row.find_all(['td', 'th'])]
                md_table.append('| ' + ' | '.join(cells) + ' |')
                if i == 0:
                    md_table.append('| ' + ' | '.join(['---'] * len(cells)) + ' |')
            lines.append('\n'.join(md_table) + '\n\n')
            continue

        if el.name in ('ul', 'ol'):
            for li in el.find_all('li', recursive=False):
                prefix = '-' if el.name == 'ul' else '1.'
                text = li.get_text(strip=True)
                if text:
                    sub_ul = li.find('ul') or li.find('ol')
                    if sub_ul:
                        lines.append(f'{prefix} {text}')
                        for sub_li in sub_ul.find_all('li'):
                            sub_text = sub_li.get_text(strip=True)
                            if sub_text:
                                lines.append(f'  - {sub_text}')
                        lines.append('')
                    else:
                        lines.append(f'{prefix} {text}')
            lines.append('')
            continue

        if el.name == 'blockquote':
            text = el.get_text(strip=True)
            if text:
                lines.append(f'> {text}\n\n')
            continue

        if el.name == 'pre':
            code = el.get_text()
            lang = ''
            code_cls = el.find('code')
            if code_cls and code_cls.get('class'):
                cls_list = code_cls['class']
                lang = cls_list[0].replace('language-', '') if cls_list else ''
            lines.append(f'```{lang}\n{code}\n```\n\n')
            continue

        if el.name in ('div', 'section'):
            continue

        text = el.get_text(strip=True)
        level = int(el.name[1]) if el.name.startswith('h') else 0
        if level:
            lines.append(f'{"#" * level} {text}\n\n')
        elif text:
            if el.find('a'):
                parts = []
                for child in el.children:
                    if child.name == 'a':
                        href = resolve_url(child.get('href', ''))
                        t = child.get_text(strip=True)
                        parts.append(f'[{t}]({href})' if href and t else t)
                    elif child.name == 'strong':
                        parts.append(f'**{child.get_text(strip=True)}**')
                    elif child.name == 'em':
                        parts.append(f'*{child.get_text(strip=True)}*')
                    elif child.name == 'br':
                        parts.append('\n')
                    elif child.name == 'img':
                        src = resolve_url(child.get('src', ''))
                        alt = child.get('alt', '')
                        if src:
                            parts.append(f'![{alt}]({src})')
                    elif isinstance(child, str) and child.strip():
                        parts.append(child.strip())
                line = ''.join(parts)
                if line:
                    lines.append(line + '\n\n')
            else:
                lines.append(f'{text}\n\n')

    return ''.join(lines)


def slugify(text):
    text = re.sub(r'[^\w\u4e00-\u9fff\s-]', '', text.lower())
    text = re.sub(r'[-\s]+', '-', text).strip('-')
    return text or 'untitled'


def safe_filename(text):
    return re.sub(r'[\\/:*?"<>|]', '_', text)[:200]


def safe_path_segments(path):
    return [safe_filename(s) for s in path.split('/') if s]


SKIP_EXTENSIONS = frozenset({
    '.jpg', '.jpeg', '.png', '.gif', '.bmp', '.webp', '.svg',
    '.css', '.js', '.json', '.xml', '.rss', '.atom',
    '.ico', '.woff', '.woff2', '.ttf', '.eot',
    '.mp4', '.avi', '.mkv', '.mov', '.flv', '.wmv',
    '.mp3', '.wav', '.flac', '.ogg',
    '.zip', '.rar', '.7z', '.tar', '.gz', '.bz2', '.xz',
    '.pdf', '.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx',
    '.torrent', '.exe', '.dmg', '.apk', '.iso',
    '.ts', '.m3u8', '.srt', '.ass',
})

SKIP_PATH_PATTERNS = (
    '/wp-', '/feed/', '/trackback/', '/comment/',
    '/cdn-cgi/', '/gen_', '/template/', '/images/',
)


def _same_domain(a, b):
    return a.replace('www.', '') == b.replace('www.', '')


def resolve_archive_href(href, base_url):
    """Resolve a Wayback-archived href to the original URL.
    Handles /web/{ts}/{url}  and  /web/{ts}im_/{url}  and  normal relative paths."""
    from urllib.parse import urljoin
    m = re.match(r'/web/\d{14}(?:im_)?/(https?://\S+)', href)
    if m:
        return m.group(1)
    return urljoin(base_url, href)


def extract_links_from_html(html, base_url):
    """Extract all same-domain links from archived HTML (original URLs)."""
    soup = BeautifulSoup(html, 'html.parser')
    base_parsed = urlparse(base_url)
    domain = base_parsed.netloc
    base_path = base_parsed.path.rstrip('/') or '/'

    links = set()
    for a in soup.find_all('a', href=True):
        href = a['href'].strip()
        if not href or href.startswith('#') or href.startswith('javascript:'):
            continue

        full = resolve_archive_href(href, base_url)
        parsed = urlparse(full)

        if not _same_domain(parsed.netloc, domain):
            continue
        p = parsed.path.rstrip('/') or '/'
        if not p.startswith(base_path):
            continue

        links.add(full)

    return links


def extract_cover_map_from_html(html, base_url, timestamp=None):
    soup = BeautifulSoup(html, 'html.parser')
    base_parsed = urlparse(base_url)
    domain = base_parsed.netloc

    def img_src(img):
        s = img.get('src') or img.get('loadsrc') or img.get('datasrc') or img.get('data-src') or ''
        return resolve_archive_href(s, base_url) if s else ''

    def to_archive_url(original):
        if original and timestamp:
            return f'https://web.archive.org/web/{timestamp}im_/{original}'
        return original

    cover_map = {}
    og_image = ""
    for meta in [soup.find('meta', property='og:image'),
                 soup.find('meta', attrs={'name': 'twitter:image'}),
                 soup.find('meta', attrs={'name': 'thumbnail'})]:
        if meta and meta.get('content'):
            og_image = meta['content'].strip()
            break
    if og_image:
        og_image = to_archive_url(og_image)

    for a in soup.find_all('a', href=True):
        href = a['href'].strip()
        if not href or href.startswith('#') or href.startswith('javascript:'):
            continue
        full = resolve_archive_href(href, base_url)
        parsed = urlparse(full)
        if not _same_domain(parsed.netloc, domain):
            continue
        if classify_url(full) == 'asset':
            continue
        if full in cover_map:
            continue
        src = ''
        img = a.find('img')
        if img:
            src = img_src(img)
        if not src and a.parent:
            for ancestor in [a.parent] + list(a.parents):
                if ancestor.name in ('div', 'li', 'td', 'section'):
                    img = ancestor.find('img', recursive=False) or ancestor.find('img')
                    if img:
                        src = img_src(img)
                    break
        src = to_archive_url(src)
        if src:
            cover_map[full] = src
        elif og_image:
            cover_map[full] = og_image

    return cover_map


def classify_url(url):
    """Classify URL as directory/article/asset.  asset URLs are skipped."""
    parsed = urlparse(url)
    path = parsed.path.rstrip('/')
    ext = os.path.splitext(path)[1].lower()

    if ext in SKIP_EXTENSIONS:
        return 'asset'
    for pat in SKIP_PATH_PATTERNS:
        if pat in path:
            return 'asset'
    if parsed.query:
        return 'asset'

    if url.endswith('/') or (not ext and not parsed.query):
        return 'directory'
    return 'article'


def get_all_urls_cdx(base_url, from_date, to_date):
    """CDX prefix match — returns {url: (latest_timestamp, mimetype)}."""
    params = {
        'url': base_url,
        'matchType': 'prefix',
        'output': 'json',
        'fl': 'timestamp,original,statuscode,mimetype',
        'collapse': 'timestamp:8',
        'showNumPages': 'false',
    }
    if from_date:
        params['from'] = from_date
    if to_date:
        params['to'] = to_date

    page = 0
    url_map = {}
    while True:
        p = params.copy()
        p['page'] = str(page)
        print(f"[CDX] page={page}  prefix={base_url}")
        rows_data = None
        for attempt in range(3):
            try:
                resp = _session.get(CDX_API, params=p, timeout=60)
                if resp.status_code == 400:
                    rows_data = []
                    break
                resp.raise_for_status()
                rows_data = resp.json()
                break
            except requests.RequestException as e:
                if attempt == 2:
                    print(f"[CDX] page={page} 请求失败，终止翻页: {e}")
                    rows_data = []
                    break
                print(f"[CDX] 重试 {attempt+1}/3: {e}")
                time.sleep(3)
        if not rows_data or len(rows_data) <= 1:
            break
        for row in rows_data[1:]:
            ts, orig = row[0], row[1]
            try:
                status = int(row[2])
            except ValueError:
                continue
            mime = row[3] if len(row) > 3 else ''
            if status != 200:
                continue
            if orig not in url_map or ts > url_map[orig][0]:
                url_map[orig] = (ts, mime)
        page += 1

    print(f"[CDX] 总计 {len(url_map)} 个唯一 URL")
    return url_map


def build_tree_from_urls(url_map):
    """Build nested tree from URL map.

    Returns::
        tree = {
            "/": {
                "type": "dir",
                "children": {
                    "movie": {
                        "type": "dir",
                        "children": {
                            "action": {
                                "type": "dir",
                                "children": {},
                                "articles": {"url1": ts1, "url2": ts2},
                            },
                        },
                        "articles": {},
                    },
                },
            },
        }
    """
    root = {"type": "dir", "children": {}, "articles": {}}

    for url, (ts, mime) in url_map.items():
        parsed = urlparse(url)
        path = parsed.path.strip('/')
        if not path:
            continue

        parts = path.split('/')
        leaf_name = parts[-1]
        parent_parts = parts[:-1]

        cls = classify_url(url)
        if cls == 'asset':
            continue

        # navigate to the parent directory node
        node = root
        for seg in parent_parts:
            node = node.setdefault("children", {}).setdefault(seg, {})
            node.setdefault("type", "dir")
            node.setdefault("children", {})
            node.setdefault("articles", {})

        if cls == 'directory':
            d = node.setdefault("children", {})
            d.setdefault(leaf_name, {"type": "dir", "children": {}, "articles": {}})
        else:
            node.setdefault("articles", {})[url] = ts

    return root


def render_tree_text(node, indent=0):
    """Render tree as indented text lines."""
    lines = []
    prefix = "  " * indent
    for name, child in node.get("children", {}).items():
        lines.append(f"{prefix}├── {name}/")
        lines.extend(render_tree_text(child, indent + 1))
    for url in node.get("articles", {}):
        p = urlparse(url).path
        lines.append(f"{prefix}├── {p}")
    return lines


def save_sitemap_json(tree, url_map, filepath, cover_map=None):
    """Save structure + URL list + cover map as JSON."""
    def prune(n):
        return {
            "type": n.get("type", "dir"),
            "children": {k: prune(v) for k, v in n.get("children", {}).items()},
            "article_count": len(n.get("articles", {})),
        }
    cm = cover_map or {}
    articles = []
    for u in url_map:
        ts = url_map[u][0] if isinstance(url_map[u], (list, tuple)) else ''
        link = f"https://web.archive.org/web/{ts}/{u}" if ts else u
        articles.append({"image": cm.get(u, ""), "link": link})
    payload = {
        "tree": prune(tree),
        "total_urls": len(url_map),
        "articles": articles,
    }
    if cm:
        payload["cover_map"] = cm
    with open(filepath, 'w', encoding='utf-8') as f:
        json.dump(payload, f, ensure_ascii=False, indent=2)
    return filepath


def process_article(snap_url, timestamp, output_dir, cover_map=None):
    """Download, extract, write MD for a single article URL.
    Returns (filepath, cover_url) so caller can update cover_map."""
    raw = fetch_content(timestamp, snap_url)
    soup = BeautifulSoup(raw, 'html.parser')
    title, description, cover, tags = extract_metadata(soup, snap_url)
    body = html_to_md(soup, snap_url)
    fp = write_markdown(output_dir, snap_url, timestamp,
                        title or snap_url, description, cover,
                        tags, body)
    return fp, cover


def discover_articles_via_html(base_url, timestamp, depth=2, delay=1.5):
    """Crawl HTML pages to discover article links under base_url.

    Starts from base_url, extracts links, follows directories recursively.
    Returns set of discovered article URLs.
    """
    from collections import deque

    discovered = set()
    visited_dirs = set()
    q = deque([base_url])

    while q:
        url = q.popleft()
        if url in visited_dirs:
            continue
        visited_dirs.add(url)

        print(f'[HTML-CRAWL] {url}')
        try:
            raw = fetch_content(timestamp, url)
        except Exception as e:
            print(f'[HTML-CRAWL] 跳过 {url}: {e}')
            continue

        links = extract_links_from_html(raw, url)
        for link in links:
            cls = classify_url(link)
            if cls == 'asset':
                continue
            if cls == 'directory':
                if depth > 0 and link not in visited_dirs:
                    q.append(link)
                    time.sleep(delay)
            else:
                discovered.add(link)

        depth -= 1
        if depth < 0:
            break

    return discovered


def build_front_matter(title, description, cover, tags, categories, url, timestamp, arch_link):
    dt = datetime.strptime(timestamp, '%Y%m%d%H%M%S')
    date_str = dt.strftime('%Y-%m-%dT%H:%M:%SZ')

    parsed = urlparse(url)
    path_slug = parsed.path.rstrip('/')
    if not path_slug:
        path_slug = '/'

    fm = '+++\n'
    fm += f'title = "{title}"\n'
    fm += f'description = "{description}"\n'
    fm += f'date = {date_str}\n'
    fm += 'draft = false\n'
    fm += f'tags = {json.dumps(tags)}\n'
    fm += f'categories = {json.dumps(categories)}\n'
    fm += 'keywords = []\n'
    fm += 'toc = true\n'
    fm += f'static_thumbnail = "{cover}"\n'
    fm += f'arch_link = "{arch_link}"\n'
    fm += f'reflink = "{arch_link}"\n'
    fm += f'rank = ""\n'
    fm += 'weight = 0\n'
    fm += f'subtitle = "{description[:120]}"\n'
    fm += f'slug = "{path_slug}/"\n'
    fm += '+++\n\n'
    return fm


def write_markdown(output_dir, url, timestamp, title, description, cover, tags, body):
    parsed = urlparse(url)
    path = parsed.path.strip('/')
    cls = classify_url(url)

    if cls == 'directory':
        dir_name = path.rstrip('/').split('/')[-1] if path else 'root'
        out_tags = tags or [dir_name]
        out_categories = [dir_name]
        rel_dir = '/'.join(safe_path_segments(path)) if path else ''
        filename = '_index.md'
    else:
        out_tags = tags or ['archived']
        out_categories = ['archive']
        *parents, leaf = path.rsplit('/', 1)
        parents_dir = '/'.join(parents)
        name, _ = os.path.splitext(leaf)
        slug = slugify(name) or 'untitled'
        rel_dir = '/'.join(safe_path_segments(parents_dir)) if parents_dir else ''
        filename = f'{slug}.md'

    full_dir = os.path.join(output_dir, rel_dir)
    os.makedirs(full_dir, exist_ok=True)
    filepath = os.path.join(full_dir, filename)

    arch_link = f'https://web.archive.org/web/{timestamp}/{url}'

    fm = build_front_matter(title, description, cover, out_tags, out_categories,
                            url, timestamp, arch_link)
    banner = (
        f'> 原文: [{url}]({url})\n'
        f'> 归档时间: {timestamp}\n'
        f'> 来源: [Wayback Machine]({arch_link})\n\n'
    )

    content = fm + banner + '---\n\n' + body

    with open(filepath, 'w', encoding='utf-8') as f:
        f.write(content)

    return filepath


def main():
    parser = argparse.ArgumentParser(
        description='从 Wayback Machine 下载归档页面并生成 Hugo Markdown',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=__doc__)
    parser.add_argument('url', help='Wayback Machine 归档链接或原始 URL')
    parser.add_argument('-o', '--output', default='./content',
                        help='输出目录 (default: ./content)')
    parser.add_argument('--prefix', action='store_true',
                        help='前缀匹配（下载该 URL 下的所有页面，不限单个 URL）')
    parser.add_argument('--crawl', action='store_true',
                        help='全站爬取：CDX 发现全站 URL → 展示目录树 → 并发下载文章')
    parser.add_argument('--workers', type=int, default=5,
                        help='并发下载线程数 (default: 5)')
    parser.add_argument('--from', dest='from_date',
                        help='起始日期 (YYYYMMDDHHMMSS)')
    parser.add_argument('--to', dest='to_date',
                        help='截止日期 (YYYYMMDDHHMMSS)，默认当前时刻')
    parser.add_argument('--timeout', type=int, default=300,
                        help='单篇文章下载超时秒数 (default: 300)')
    parser.add_argument('--delay', type=float, default=1.5,
                        help='请求间隔秒数 (default: 1.5)')
    parser.add_argument('--limit', type=int, default=0,
                        help='最大下载数 (0=不限)')

    args = parser.parse_args()
    original_url = extract_original_url(args.url)
    entry_ts = extract_timestamp(args.url)
    parsed = urlparse(original_url)
    site_dir = parsed.netloc + parsed.path.rstrip('/').replace('/', '_')
    output_dir = args.output
    os.makedirs(output_dir, exist_ok=True)

    if args.crawl and not args.to_date and entry_ts:
        to_date = entry_ts[:8]  # YYYYMMDDhhmmss → YYYYMMDD
        print(f'[AUTO] 使用入口时间戳作为截止日期: {to_date}')
    else:
        to_date = args.to_date or datetime.now(timezone.utc).strftime('%Y%m%d%H%M%S')

    # ── Crawl mode ────────────────────────────────────────────────────────────
    if args.crawl:
        print(f'\n{"=" * 60}')
        print(f'  全站爬取模式启动')
        print(f'  入口: {original_url}')
        print(f'  输出: {output_dir}')
        print(f'  workers: {args.workers}')
        print(f'{"=" * 60}\n')

        # Step 1: CDX discovery
        url_map = get_all_urls_cdx(original_url, from_date=args.from_date,
                                   to_date=to_date)

        # Step 2: Build tree
        tree = build_tree_from_urls(url_map)
        lines = render_tree_text(tree)
        print(f'\n{"=" * 60}')
        print(f'  站点目录结构')
        print(f'{"=" * 60}')
        for line in lines:
            print(f'  {line}')
        print(f'  总计 {len(url_map)} 个唯一 URL')

        # Step 3: Build cover_map from existing URL meta and save sitemap
        cover_map = {}
        sitemap_path = os.path.join(output_dir, 'sitemap.json')
        save_sitemap_json(tree, url_map, sitemap_path, cover_map)
        print(f'\n[树] 站点地图保存 → {sitemap_path}')

        # Step 4: Fetch homepage + top-level dirs to extract covers
        from urllib.parse import urljoin

        def fetch_covers(url, ts):
            try:
                html = fetch_content(ts, url)
                cm = extract_cover_map_from_html(html, url, ts)
                if cm:
                    cover_map.update(cm)
                    print(f'[COVER] {url} → {len(cm)} 张封面')
            except Exception as e:
                print(f'[COVER] 跳过 {url}: {e}')

        fetch_covers(original_url, entry_ts or to_date)

        # Extract covers from top-level dirs
        for name in list(tree.get("children", {})):
            dir_url = urljoin(original_url, name + '/')
            fetch_covers(dir_url, entry_ts or to_date)

        # Step 5: Enrich empty directories via HTML (1 level) + extract covers
        enriched = {}

        def enrich_dir(node, path_parts, depth=0):
            if depth > 1:
                return
            has_articles = bool(node.get("articles"))
            if not has_articles and path_parts:
                dir_url = urljoin(original_url, '/'.join(path_parts) + '/')
                ts = entry_ts or to_date
                try:
                    html = fetch_content(ts, dir_url)
                    links = extract_links_from_html(html, dir_url)
                    found = 0
                    for link in links:
                        if classify_url(link) == 'article':
                            enriched[link] = ts
                            found += 1
                    if found:
                        print(f'[ENRICH] {dir_url} → {found} 篇文章')
                    page_cover_map = extract_cover_map_from_html(html, dir_url, ts)
                    cover_map.update(page_cover_map)
                except Exception as e:
                    print(f'[ENRICH] 跳过 {dir_url}: {e}')
            for name, child in node.get("children", {}).items():
                enrich_dir(child, path_parts + [name], depth + 1)

        enrich_dir(tree, [])

        if enriched:
            print(f'[ENRICH] HTML 补充发现 {len(enriched)} 篇')
            for url, ts in enriched.items():
                if url not in url_map:
                    url_map[url] = (ts, 'text/html')
                    enode = tree
                    for seg in urlparse(url).path.strip('/').split('/'):
                        enode = enode.setdefault("children", {}).setdefault(seg, {})
                        enode.setdefault("type", "dir")
                        enode.setdefault("children", {})
                        enode.setdefault("articles", {})
                    enode["articles"][url] = ts

        # Re-save sitemap with covers
        if cover_map:
            save_sitemap_json(tree, url_map, sitemap_path, cover_map)
            print(f'[COVER] 已收录 {len(cover_map)} 张封面图')

        # Step 5: Extract articles from tree
        articles = []

        def collect_articles(node):
            for url in node.get("articles", {}):
                articles.append(url)
            for child in node.get("children", {}).values():
                collect_articles(child)

        collect_articles(tree)

        if args.limit > 0:
            articles = articles[:args.limit]

        if not articles:
            print('[WARN] 未发现可下载的文章页面')
            return

        # Step 5: Filter by HTML mime + look up timestamps
        article_items = []
        for url in articles:
            if url in url_map:
                ts, mime = url_map[url]
                if mime.startswith('text/html'):
                    article_items.append((url, ts))

        print(f'\n[文章] 共 {len(article_items)} 篇待下载\n')

        # Step 6: Concurrent download
        from concurrent.futures import ThreadPoolExecutor, as_completed
        success = 0

        def worker(item):
            url, ts = item
            return process_article(url, ts, output_dir, cover_map)

        with ThreadPoolExecutor(max_workers=args.workers) as pool:
            fut_map = {pool.submit(worker, it): it for it in article_items}
            done = 0
            n = len(fut_map)
            for fut in as_completed(fut_map):
                done += 1
                url, _ = fut_map[fut]
                try:
                    fp, cov = fut.result(timeout=args.timeout)
                    success += 1
                    print(f'[{done}/{n}] [OK] {fp}')
                    if cov and url not in cover_map:
                        cover_map[url] = cov
                except TimeoutError:
                    fut.cancel()
                    print(f'[{done}/{n}] [TIMEOUT] {url}  (>{args.timeout}s)')
                except Exception as e:
                    print(f'[{done}/{n}] [ERR] {url}  {e}')

        # Re-save sitemap with covers from downloaded articles
        if cover_map:
            save_sitemap_json(tree, url_map, sitemap_path, cover_map)
            print(f'\n[COVER] sitemap 收录 {len(cover_map)} 张封面图')

        print(f'\n[DONE] 成功: {success}/{len(article_items)}  | 输出: {output_dir}')
        return

    # ── Original mode (single / prefix) ───────────────────────────────────────
    snapshots = get_snapshots(original_url, prefix=args.prefix,
                              from_date=args.from_date, to_date=to_date)

    if args.limit > 0:
        snapshots = snapshots[:args.limit]

    success = 0
    cover_map = {}
    for i, (timestamp, snap_url, mime) in enumerate(snapshots, 1):
        if not mime.startswith('text/html'):
            continue

        print(f'\n[{i}/{len(snapshots)}] {snap_url}')
        print(f'    时间: {timestamp}')

        try:
            import signal

            def _timeout_handler(signum, frame):
                raise TimeoutError(f"下载超时 ({args.timeout}s)")

            signal.signal(signal.SIGALRM, _timeout_handler)
            signal.alarm(args.timeout)

            try:
                raw = fetch_content(timestamp, snap_url)
                soup = BeautifulSoup(raw, 'html.parser')

                title, description, cover, tags = extract_metadata(soup, snap_url)
                body = html_to_md(soup, snap_url)

                filepath = write_markdown(output_dir, snap_url, timestamp,
                                          title or snap_url, description, cover,
                                          tags, body)

                print(f'[SAVED] {filepath}')
                success += 1
                if cover:
                    cover_map[snap_url] = cover
            finally:
                signal.alarm(0)

            time.sleep(args.delay)

        except KeyboardInterrupt:
            print('\n[ABORT] 用户中断')
            break
        except TimeoutError as e:
            print(f'[{i}/{len(snapshots)}] [TIMEOUT] {snap_url}  {e}')
            continue
        except Exception as e:
            traceback.print_exc()
            continue

    if cover_map:
        sitemap_path = os.path.join(output_dir, 'sitemap.json')
        articles = [{"image": v, "link": k} for k, v in cover_map.items()]
        sitemap = {"tree": {}, "total_urls": len(cover_map), "articles": articles, "cover_map": cover_map}
        with open(sitemap_path, 'w', encoding='utf-8') as f:
            json.dump(sitemap, f, ensure_ascii=False, indent=2)
        print(f'\n[COVER] 已保存 {len(cover_map)} 张封面图到 sitemap')

    print(f'\n[DONE] 成功: {success} 个 | 输出目录: {output_dir}')


if __name__ == '__main__':
    main()
