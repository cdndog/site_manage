#!/bin/bash
set -euo pipefail

# ============================================================
# wayback_site_titles.sh — List all archived page titles of a domain
# Usage:
#   ./wayback_site_titles.sh <domain>
#   ./wayback_site_titles.sh <domain> --all        # every snapshot, not 1/year
#   ./wayback_site_titles.sh <domain> --workers 4  # parallel fetch (default 1)
# Example:
#   ./wayback_site_titles.sh sdwenkong.com
# Output (per line):  date｜archive_url｜title
# ============================================================

CDX="https://web.archive.org/cdx/search/cdx"
ARCHIVE="https://web.archive.org/web"

usage() {
    grep '^#' "$0" | sed 's/^# \{0,1\}//'
    exit 1
}

[ $# -ge 1 ] || usage
DOMAIN="${1//[^a-zA-Z0-9.-]/}"; shift
[ -n "$DOMAIN" ] || usage

MODE=year
WORKERS=1

while [ $# -gt 0 ]; do
    case "$1" in
        --all)    MODE=all ;;
        --workers) WORKERS="${2:?need value}"; shift ;;
        -h|--help) usage ;;
        *) echo "[wayback] unknown option: $1" >&2; usage ;;
    esac
    shift
done

[ "$WORKERS" -ge 1 ] 2>/dev/null || WORKERS=1

echo "[wayback] Fetching CDX snapshot list for $DOMAIN ..." >&2
curl -sL --fail "$CDX?url=$DOMAIN&matchType=domain&limit=200000&fl=timestamp,original,statuscode&filter=statuscode:200" \
    -o /tmp/wb_titles_$$.txt

total=$(wc -l < /tmp/wb_titles_$$.txt | tr -d ' ')
echo "[wayback] $total archived (HTTP 200) pages found." >&2
if [ "$total" -eq 0 ]; then
    echo "[wayback] No archives found for $DOMAIN."
    rm -f /tmp/wb_titles_$$.txt
    exit 0
fi

# Pick homepage snapshots (path is empty or '/'), collapse to 1 per year unless --all
if [ "$MODE" = all ]; then
    awk '{print $1, $2}' /tmp/wb_titles_$$.txt > /tmp/wb_sel_$$.txt
else
    awk '$2 ~ /\/$/ {print $1, $2}' /tmp/wb_titles_$$.txt \
        | awk '!seen[substr($1,1,4)]++' > /tmp/wb_sel_$$.txt
fi

sel=$(wc -l < /tmp/wb_sel_$$.txt | tr -d ' ')
echo "[wayback] Fetching titles for $sel snapshot(s) (workers=$WORKERS) ..." >&2

# Worker script file: exported bash functions do not survive
# `bash -c` on macOS bash 3.2, so write fetch logic to a temp file.
# The python extractor reads the html file via sys.argv (not stdin),
# because stdin is reserved for the heredoc that supplies the python script.
WORKER=/tmp/wb_worker_$$.sh
cat > "$WORKER" <<EOF
#!/bin/bash
ARCHIVE="$ARCHIVE"
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
printf '%s｜%s｜%s\n' "\$ts" "\$ARCHIVE/\${ts}/\$orig" "\$title"
EOF
chmod +x "$WORKER"

xargs -P "$WORKERS" -n 2 "$WORKER" < /tmp/wb_sel_$$.txt | sort

rm -f /tmp/wb_titles_$$.txt /tmp/wb_sel_$$.txt "$WORKER"
