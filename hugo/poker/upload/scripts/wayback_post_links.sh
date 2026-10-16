#!/bin/bash
set -euo pipefail

# ============================================================
# wayback_post_links.sh — List archived article links + titles
# Usage:
#   ./wayback_post_links.sh <domain> <YYYYMMDD|YYYYMMDDHHMMSS>
#   ./wayback_post_links.sh suitpo.com 20180913
# Options:
#   --window <days>   only snapshots within N days of the date (default: any)
#   --workers <N>     parallel fetch (default 1; web.archive.org rate-limits)
#   --limit <N>       cap number of links to fetch (default: all)
#   --verbose         show full timestamp + status
# Output (per line):  date  URL  title
# ============================================================

CDX="https://web.archive.org/cdx/search/cdx"
ARCHIVE="https://web.archive.org/web"

usage() {
    grep '^#' "$0" | sed 's/^# \{0,1\}//'
    exit 1
}

[ $# -ge 2 ] || usage
DOMAIN="${1//[^a-zA-Z0-9.-]/}"
DATE="${2//[^0-9]/}"
shift 2
[ -n "$DOMAIN" ] || usage
[ ${#DATE} -ge 8 ] || usage

WINDOW=0
WORKERS=1
VERBOSE=0
LIMIT=0

while [ $# -gt 0 ]; do
    case "$1" in
        --window) WINDOW="${2:?need value}"; shift ;;
        --workers) WORKERS="${2:?need value}"; shift ;;
        --limit) LIMIT="${2:?need value}"; shift ;;
        --verbose) VERBOSE=1 ;;
        -h|--help) usage ;;
        *) echo "[wayback] unknown option: $1" >&2; usage ;;
    esac
    shift
done

[ "$WORKERS" -ge 1 ] 2>/dev/null || WORKERS=1
[ "$WINDOW" -ge 0 ] 2>/dev/null || WINDOW=0
[ "$LIMIT" -ge 0 ] 2>/dev/null || LIMIT=0

echo "[wayback] Fetching all CDX snapshots for $DOMAIN ..." >&2
curl -sL --fail "$CDX?url=$DOMAIN&matchType=domain&limit=200000&fl=timestamp,original,statuscode&filter=statuscode:200" \
    -o /tmp/wb_links_$$.txt

total=$(wc -l < /tmp/wb_links_$$.txt | tr -d ' ')
echo "[wayback] $total archived (HTTP 200) pages found." >&2
if [ "$total" -eq 0 ]; then
    echo "[wayback] No archives found for $DOMAIN."
    rm -f /tmp/wb_links_$$.txt
    exit 0
fi

# Normalize date to comparable integer (14-digit, padded with zeros)
DATE_NORM=$(printf '%-14s' "$DATE" | tr ' ' '0')

# For each unique URL pick the snapshot whose timestamp is closest to the date.
# Filter out static assets / non-article resources.
awk -v date="$DATE_NORM" -v win="$WINDOW" '
{
    ts=$1; url=$2;
    key=url;
    diff=ts-date; if (diff<0) diff=-diff;
    if (win>0 && diff > win*100000000) next;
    if (url ~ /\.(css|js|m?js|ico|png|jpe?g|gif|svg|webp|woff2?|ttf|eot|map|zip|gz|pdf|txt|xml|json|asp|aspx)(\?|$)/) next;
    if (url ~ /favicon|robots|sitemap|crossdomain|\.htaccess/) next;
    if (!(key in best) || diff < best[key]) { best[key]=diff; sel[key]=ts" "url }
}
END { for (k in sel) print sel[k] }
' /tmp/wb_links_$$.txt > /tmp/wb_links_sel_$$.txt

if [ "$LIMIT" -gt 0 ]; then
    head -n "$LIMIT" /tmp/wb_links_sel_$$.txt > /tmp/wb_links_sel_trim_$$.txt
    mv /tmp/wb_links_sel_trim_$$.txt /tmp/wb_links_sel_$$.txt
fi

sel=$(wc -l < /tmp/wb_links_sel_$$.txt | tr -d ' ')
echo "[wayback] Fetching titles for $sel article link(s) (workers=$WORKERS) ..." >&2

if [ "$sel" -eq 0 ]; then
    echo "[wayback] No article-like links found for $DOMAIN near $DATE."
    rm -f /tmp/wb_links_$$.txt /tmp/wb_links_sel_$$.txt
    exit 0
fi

# Worker script: exports don't survive `bash -c` on macOS bash 3.2,
# so write fetch logic to a temp file. Python reads html via argv (stdin
# is reserved for the heredoc). Retry with backoff against rate limits.
WORKER=/tmp/wb_links_worker_$$.sh
cat > "$WORKER" <<EOF
#!/bin/bash
ARCHIVE="$ARCHIVE"
VERBOSE=$VERBOSE
ts="\$1"; orig="\$2"
title="(empty response)"
for attempt in 1 2 3 4 5; do
    f=\$(mktemp)
    curl -sL --compressed --max-time 90 "\$ARCHIVE/\${ts}id_/\$orig" -o "\$f"
    size=\$(wc -c < "\$f" | tr -d ' ')
    if [ "\$size" -gt 100 ]; then
        title=\$(python3 - "\$f" <<'PYEOF'
import sys, re, html
raw = open(sys.argv[1], 'rb').read()
if not raw.strip():
    print('(empty response)')
    sys.exit(0)
for enc in ('utf-8', 'gb18030', 'gbk'):
    try:
        s = raw.decode(enc)
        break
    except Exception:
        s = None
if s is None:
    print('(undecodable)')
    sys.exit(0)
m = re.search(r'<title[^>]*>(.*?)</title>', s, re.S | re.I)
t = html.unescape(m.group(1)) if m else '(no title tag)'
print(re.sub(r'\s+', ' ', t).strip())
PYEOF
)
        rm -f "\$f"
        break
    fi
    rm -f "\$f"
    sleep \$((attempt * 2))
done
printf '%s  %s  %s\n' "\${ts:0:8}" "\$orig" "\$title"
EOF
chmod +x "$WORKER"

xargs -P "$WORKERS" -n 2 "$WORKER" < /tmp/wb_links_sel_$$.txt | sort

rm -f /tmp/wb_links_$$.txt /tmp/wb_links_sel_$$.txt "$WORKER"
