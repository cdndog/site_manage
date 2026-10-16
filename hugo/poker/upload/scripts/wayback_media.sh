#!/bin/bash
set -euo pipefail

# ============================================================
# wayback_media.sh — Download all media files (images, videos,
# audio, ...) of a domain from the Wayback Machine archive.
# Usage:
#   ./wayback_media.sh <domain> <YYYYMMDD|YYYYMMDDHHMMSS>
#   ./wayback_media.sh www.retkcon.com 20150617123705
# Options:
#   --output <dir>      download dir root (default: ./static)
#   --window <days>     only snapshots within N days of the date (default: any)
#   --timeout <s>       per-request max seconds (default 60)
#   --retries <N>       max attempts per file (default 5)
#   --queue-timeout <s> hard cap for the whole batch (default 3600)
#   --workers <N>       parallel downloads (default 1; archive rate-limits)
#   --limit <N>         cap number of files to download (default: all)
#   --all-types         include css/js/zip/html too (default: media only)
#   --verbose           log each file to stderr
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

OUTDIR=""
WINDOW=0
WORKERS=1
LIMIT=0
ALL_TYPES=0
VERBOSE=0
TIMEOUT=60
RETRIES=5
QUEUE_TIMEOUT=3600

while [ $# -gt 0 ]; do
    case "$1" in
        --output) OUTDIR="${2:?need value}"; shift ;;
        --window) WINDOW="${2:?need value}"; shift ;;
        --workers) WORKERS="${2:?need value}"; shift ;;
        --limit) LIMIT="${2:?need value}"; shift ;;
        --timeout) TIMEOUT="${2:?need value}"; shift ;;
        --retries) RETRIES="${2:?need value}"; shift ;;
        --queue-timeout) QUEUE_TIMEOUT="${2:?need value}"; shift ;;
        --all-types) ALL_TYPES=1 ;;
        --verbose) VERBOSE=1 ;;
        -h|--help) usage ;;
        *) echo "[wayback] unknown option: $1" >&2; usage ;;
    esac
    shift
done

[ "$WORKERS" -ge 1 ] 2>/dev/null || WORKERS=1
[ "$WINDOW" -ge 0 ] 2>/dev/null || WINDOW=0
[ "$LIMIT" -ge 0 ] 2>/dev/null || LIMIT=0
[ "$TIMEOUT" -ge 5 ] 2>/dev/null || TIMEOUT=60
[ "$RETRIES" -ge 1 ] 2>/dev/null || RETRIES=5
[ "$QUEUE_TIMEOUT" -ge 1 ] 2>/dev/null || QUEUE_TIMEOUT=3600
: "${OUTDIR:=./static}"

echo "[wayback] Fetching all CDX snapshots for $DOMAIN ..." >&2
curl -sL --fail "$CDX?url=$DOMAIN&matchType=domain&limit=200000&fl=timestamp,original,statuscode,mimetype&filter=statuscode:200" \
    -o /tmp/wb_media_$$.txt

total=$(wc -l < /tmp/wb_media_$$.txt | tr -d ' ')
echo "[wayback] $total archived (HTTP 200) resources found." >&2
if [ "$total" -eq 0 ]; then
    echo "[wayback] No archives found for $DOMAIN."
    rm -f /tmp/wb_media_$$.txt
    exit 0
fi

# Media extensions (images / video / audio / docs).
# When --all-types is set, everything (incl. css/js/html) is kept.
MEDIA_RE='\.(jpe?g|png|gif|svg|webp|bmp|ico|avif|tiff?|mp4|webm|m4v|mov|avi|mkv|flv|wmv|mp3|ogg|oga|wav|flac|aac|m4a|wma|pdf|epub|zip|tar|gz|7z|rar)$'

DATE_NORM=$(printf '%-14s' "$DATE" | tr ' ' '0')

# Pick closest snapshot per unique URL, filter by media type & window.
awk -v date="$DATE_NORM" -v win="$WINDOW" -v all="$ALL_TYPES" -v media="$MEDIA_RE" '
function abs(x) { return x < 0 ? -x : x }
{
    ts=$1; url=$2;
    if (win > 0 && abs(ts - date) > win * 100000000) next;
    if (!all && url !~ media) next;
    key=url;
    d=abs(ts - date);
    if (!(key in best) || d < best[key]) { best[key]=d; sel[key]=ts" "url }
}
END { for (k in sel) print sel[k] }
' /tmp/wb_media_$$.txt > /tmp/wb_media_sel_$$.txt

if [ "$LIMIT" -gt 0 ]; then
    head -n "$LIMIT" /tmp/wb_media_sel_$$.txt > /tmp/wb_media_trim_$$.txt
    mv /tmp/wb_media_trim_$$.txt /tmp/wb_media_sel_$$.txt
fi

sel=$(wc -l < /tmp/wb_media_sel_$$.txt | tr -d ' ')
echo "[wayback] $sel media file(s) selected for $DATE." >&2
if [ "$sel" -eq 0 ]; then
    echo "[wayback] No media files found for $DOMAIN near $DATE."
    rm -f /tmp/wb_media_$$.txt /tmp/wb_media_sel_$$.txt
    exit 0
fi

mkdir -p "$OUTDIR"

# Worker script: download a media file via the id_ snapshot (original
# bytes, no archive banner), saved under its original URL directory
# structure. After download, validate the content — HTML error pages /
# archive 404s are discarded and retried/removed. Retries with backoff
# against web.archive.org rate limits.
WORKER=/tmp/wb_media_worker_$$.sh
cat > "$WORKER" <<EOF
#!/bin/bash
ARCHIVE="$ARCHIVE"
OUTDIR="$OUTDIR"
VERBOSE=$VERBOSE
TIMEOUT=$TIMEOUT
RETRIES=$RETRIES
ts="\$1"; orig="\$2"
# derive relative path from the URL path only, keep directory hierarchy
# e.g. http://sdwenkong.com/templets/images/ico4.gif -> templets/images/ico4.gif
rel=\$(printf '%s' "\$orig" | sed -E 's#https?://[^/]+(/[^?]*)?(\?.*)?#\1#')
[ -n "\$rel" ] || rel="/index"
rel=\${rel//[^a-zA-Z0-9._\/-]/_}
dest="\$OUTDIR/\${rel#/}"
mkdir -p "\$(dirname "\$dest")"
ok=0
for attempt in \$(seq 1 "\$RETRIES"); do
    rm -f "\$dest"
    # per-attempt hard cap: connect timeout + transfer timeout, guarded by `timeout`
    if timeout "\$((TIMEOUT + 10))" curl -sL --compressed --connect-timeout 15 --max-time "\$TIMEOUT" --fail \
        -o "\$dest" "\$ARCHIVE/\${ts}id_/\$orig"; then
        # validate content: reject HTML error pages (archive 404 / nginx error)
        if head -c 512 "\$dest" | grep -qiE '(<html|<html>|404 Not Found|Bad Gateway|Service Unavailable|Too Many Requests|You have been blocked|Robot or human)'; then
            rm -f "\$dest"
            sleep \$((attempt * 2))
            continue
        fi
        # ensure it is not an empty file
        if [ ! -s "\$dest" ]; then
            rm -f "\$dest"
            sleep \$((attempt * 2))
            continue
        fi
        ok=1
        break
    fi
    rm -f "\$dest"
    sleep \$((attempt * 2))
done
if [ "\$ok" -eq 1 ]; then
    if [ "\$VERBOSE" -eq 1 ]; then echo "[wayback] OK  \$ts  \$orig  ->  \$dest" >&2; fi
    printf '%s  %s  %s\n' "\${ts:0:8}" "\$orig" "\$dest"
else
    echo "[wayback] SKIP(not media/error) \$ts  \$orig" >&2
fi
EOF
chmod +x "$WORKER"

echo "[wayback] Downloading to $OUTDIR (workers=$WORKERS, timeout=${TIMEOUT}s, retries=$RETRIES, batch=${QUEUE_TIMEOUT}s) ..." >&2
# per-file hard cap = retries * (timeout + backoff), then whole-batch cap
PERFILE=$(( RETRIES * (TIMEOUT + RETRIES * 2 + 10) ))
timeout "$QUEUE_TIMEOUT" xargs -P "$WORKERS" -n 2 timeout "$PERFILE" bash "$WORKER" < /tmp/wb_media_sel_$$.txt \
    || true
echo "[wayback] Done. Files in: $OUTDIR" >&2
rm -f /tmp/wb_media_$$.txt /tmp/wb_media_sel_$$.txt "$WORKER"
