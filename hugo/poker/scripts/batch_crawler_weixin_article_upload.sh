#!/usr/bin/env bash
set -euo pipefail

# 批量抓取 aigc.cpanice.com 的微信公众号文章，按 mp_id 映射发布站点，
# 生成标准文章 JSON，并以 topic 状态 aidone 上传。
#
# 数据源：
#   列表 https://aigc.cpanice.com/webhook/index.php?action=articles&limit=100&offset=0
#   详情 https://aigc.cpanice.com/webhook/index.php?action=article&id=<id>
#
# 配置 crawler_publish_config.txt，支持两种列顺序：
#   mp_id|git_name|site_dir|lang|geo|site_domain|pubdir[|series]
#   domain|(unused)|lang|geo|git_name|pubdir|mp_id[|series]（旧配置末尾补一列 mp_id）
# 第二种与 batch_rewrite_ops_upload.sh / topic_ops_upload.sh 的旧配置兼容。
#
# 上传：
#   1) POST <API_BASE>/api/uivision_upload.php（multipart uploadedFile）
#      把文章 JSON 写入服务端 seodata/json，并同步 aigc_status；
#   2) POST <API_BASE>/api/topic_ops.php，把 topic 状态置为 aidone。
#   用 -p 选择只执行其中一步，默认两步都执行。

ARTICLE_API="https://aigc.cpanice.com/webhook/index.php"
API_BASE="https://wpk.cpanice.com/v2"
API_TOKEN=""

API_TIMEOUT="120"
FETCH_TIMEOUT="60"
FETCH_RETRIES="3"

CONFIG_FILE="crawler_publish_config.txt"
UPLOADED_FILE="crawler_uploaded.list"
FAILED_FILE="crawler_failed.list"
JSON_DIR=""

MODE="today"
SINCE=""
ONLY_IDS=""
PAGE_LIMIT="100"
MAX_ARTICLES="0"
TZ_OFFSET="8"
UPLOAD_MODE="both"
DRY_RUN=0

WORK_TMP=""
IDS_FILE=""

cleanup() {
    if [[ -n "$WORK_TMP" ]]; then rm -rf "$WORK_TMP"; fi
}
trap cleanup EXIT

usage() {
    cat >&2 <<EOF
Usage: $0 [options]

  -c <file>   Site config file, default: crawler_publish_config.txt
              Accepted layouts:
                mp_id|git_name|site_dir|lang|geo|site_domain|pubdir[|series]
                domain|(unused)|lang|geo|git_name|pubdir|mp_id[|series]
              pubdir defaults to "article"; series defaults to git_name
              (the second layout is compatible with the older ops scripts).
  -a <url>    Article API endpoint, default: $ARTICLE_API
  -b <url>    Upload API base URL, default: https://wpk.cpanice.com/v2
  -k <token>  API token, sent as X-CSRF-Token (default: empty)
  -p <mode>   Upload mode: both (default) | json | topic
              both : upload article JSON, then set topic status to aidone
              json : only upload article JSON (seodata/json + aigc_status)
              topic: only set topic status to aidone
  -t <mode>   today (default): only articles crawled today (crawler timezone)
              all: every article returned by the article API
  -s <date>   Only articles crawled on/after YYYY-MM-DD (overrides -t)
  -i <ids>    Only process these article ids, comma separated (skips list API)
  -l <n>      Article list page size, default: 100
  -n <n>      Max article-site uploads to process, 0 = unlimited (default: 0)
  -m <secs>   Upload curl max time, default: 120
  -f <secs>   Fetch curl max time, default: 60
  -z <hours>  Crawler timezone offset from UTC, default: 8 (Asia/Shanghai)
  -u <file>   Uploaded record file, default: crawler_uploaded.list
  -x <file>   Failed record file, default: crawler_failed.list
  -j <dir>    Also save generated article JSON files to this directory
  -d          Dry run: fetch and build JSON, but do not upload
  -h          Show this help

Uploaded records are keyed by
<article_id>|<site_domain>|<lang>|<geo>|<git_name>|<pubdir>, so the same
article is uploaded once per distinct site config. Remove a line from the
uploaded file to retry it.

Examples:
  $0
  $0 -t all -n 20
  $0 -i 247 -d -j /tmp/crawler-json
  $0 -p topic -i 247,246
EOF
}

while getopts ":c:a:b:k:p:t:s:i:l:n:m:f:z:u:x:j:dh" opt; do
    case "$opt" in
        c) CONFIG_FILE="$OPTARG" ;;
        a) ARTICLE_API="$OPTARG" ;;
        b) API_BASE="$OPTARG" ;;
        k) API_TOKEN="$OPTARG" ;;
        p) UPLOAD_MODE="$OPTARG" ;;
        t) MODE="$OPTARG" ;;
        s) SINCE="$OPTARG" ;;
        i) ONLY_IDS="$OPTARG" ;;
        l) PAGE_LIMIT="$OPTARG" ;;
        n) MAX_ARTICLES="$OPTARG" ;;
        m) API_TIMEOUT="$OPTARG" ;;
        f) FETCH_TIMEOUT="$OPTARG" ;;
        z) TZ_OFFSET="$OPTARG" ;;
        u) UPLOADED_FILE="$OPTARG" ;;
        x) FAILED_FILE="$OPTARG" ;;
        j) JSON_DIR="$OPTARG" ;;
        d) DRY_RUN=1 ;;
        h) usage; exit 0 ;;
        \?)
            echo "ERROR: Unknown option: -$OPTARG" >&2
            usage
            exit 2
            ;;
        :)
            echo "ERROR: Option -$OPTARG requires an argument" >&2
            usage
            exit 2
            ;;
    esac
done

if [[ "$MODE" != "all" && "$MODE" != "today" ]]; then
    echo "ERROR: Invalid value for -t: $MODE (allowed: all, today)" >&2
    exit 2
fi

if [[ "$UPLOAD_MODE" != "both" && "$UPLOAD_MODE" != "json" && "$UPLOAD_MODE" != "topic" ]]; then
    echo "ERROR: Invalid value for -p: $UPLOAD_MODE (allowed: both, json, topic)" >&2
    exit 2
fi

if [[ ! "$API_TIMEOUT" =~ ^[0-9]+$ || "$API_TIMEOUT" -le 0 ]]; then
    echo "ERROR: Invalid value for -m: $API_TIMEOUT (must be a positive integer)" >&2
    exit 2
fi

if [[ ! "$FETCH_TIMEOUT" =~ ^[0-9]+$ || "$FETCH_TIMEOUT" -le 0 ]]; then
    echo "ERROR: Invalid value for -f: $FETCH_TIMEOUT (must be a positive integer)" >&2
    exit 2
fi

if [[ ! "$PAGE_LIMIT" =~ ^[0-9]+$ || "$PAGE_LIMIT" -le 0 ]]; then
    echo "ERROR: Invalid value for -l: $PAGE_LIMIT (must be a positive integer)" >&2
    exit 2
fi

if [[ ! "$MAX_ARTICLES" =~ ^[0-9]+$ ]]; then
    echo "ERROR: Invalid value for -n: $MAX_ARTICLES (must be a non-negative integer)" >&2
    exit 2
fi

if [[ ! "$TZ_OFFSET" =~ ^-?[0-9]+$ || "$TZ_OFFSET" -lt -12 || "$TZ_OFFSET" -gt 14 ]]; then
    echo "ERROR: Invalid value for -z: $TZ_OFFSET (must be an integer between -12 and 14)" >&2
    exit 2
fi

if [[ -n "$SINCE" && ! "$SINCE" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]]; then
    echo "ERROR: Invalid value for -s: $SINCE (expected YYYY-MM-DD)" >&2
    exit 2
fi

if [[ ! -f "$CONFIG_FILE" ]]; then
    echo "ERROR: Config file not found: $CONFIG_FILE" >&2
    exit 2
fi

if [[ ! -r "$CONFIG_FILE" ]]; then
    echo "ERROR: Config file is not readable: $CONFIG_FILE" >&2
    exit 2
fi

ARTICLE_API="${ARTICLE_API%/}"
API_BASE="${API_BASE%/}"

# upload_site 取上传 API 的站点根地址（去掉 /v2 之类的路径）
UPLOAD_SITE="$(printf '%s' "$API_BASE" | sed -E 's#^(https?://[^/]+).*#\1#')"

NOW_EPOCH="$(date -u +%s)"
UPDATE_DATE="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

# 抓取端的 created_at/publish_time 是北京时间等本地时间，不带时区。
# 按 -z 指定的时差换算 today，并用于把本地时间字符串转成 epoch。
TODAY="$(jq -nr --argjson now "$NOW_EPOCH" --argjson off "$((TZ_OFFSET * 3600))" \
    '$now + $off | strftime("%Y-%m-%d")')"

CUTOFF=""
if [[ -n "$SINCE" ]]; then
    CUTOFF="$SINCE"
elif [[ "$MODE" == "today" ]]; then
    CUTOFF="$TODAY"
fi

touch "$UPLOADED_FILE" "$FAILED_FILE"
if [[ -n "$JSON_DIR" ]]; then
    mkdir -p "$JSON_DIR"
fi

WORK_TMP="$(mktemp -d "${TMPDIR:-/tmp}/crawler_weixin.XXXXXX")"
IDS_FILE="$WORK_TMP/ids.txt"
DETAIL_FILE="$WORK_TMP/detail.json"
OBJ_FILE="$WORK_TMP/article.json"
ARRAY_FILE="$WORK_TMP/article_array.json"
PAYLOAD_FILE="$WORK_TMP/payload.json"

# 站点配置一次读入数组，避免在文章循环里反复读取/解析配置文件
CONFIG_MPIDS=()
CONFIG_GITS=()
CONFIG_SITEDIRS=()
CONFIG_LANGS=()
CONFIG_GEOS=()
CONFIG_DOMAINS=()
CONFIG_PUBDIRS=()
CONFIG_SERIES=()

while IFS= read -r config_line || [[ -n "$config_line" ]]; do
    config_line="${config_line%$'\r'}"
    [[ -z "$config_line" || "$config_line" == \#* ]] && continue

    # 两种列顺序：
    #   1) mp_id|git_name|site_dir|lang|geo|site_domain|pubdir[|series]
    #   2) domain|(unused)|lang|geo|git_name|pubdir|mp_id[|series]
    # 第 2 种与 batch_rewrite_ops_upload.sh / topic_ops_upload.sh 的
    # 旧配置兼容：在旧配置行末尾补一列 mp_id 即可共用同一份文件。
    IFS='|' read -r f1 f2 f3 f4 f5 f6 f7 f8 _rest <<< "$config_line"

    if [[ "$f1" == MP_* ]]; then
        mp_id="$f1"
        git_name="$f2"
        site_dir="$f3"
        lang="$f4"
        geo="$f5"
        site_domain="$f6"
        pubdir="$f7"
        series="$f8"
    elif [[ "$f7" == MP_* ]]; then
        site_domain="$f1"
        lang="$f3"
        geo="$f4"
        git_name="$f5"
        pubdir="$f6"
        mp_id="$f7"
        series="$f8"
        site_dir=""
    else
        echo "WARN: skip config line without mp_id (MP_...): $config_line" >&2
        continue
    fi

    pubdir="${pubdir:-article}"
    series="${series:-$git_name}"

    if [[ -z "$git_name" ]]; then
        echo "WARN: skip config line without git_name: $config_line" >&2
        continue
    fi

    CONFIG_MPIDS+=("$mp_id")
    CONFIG_GITS+=("$git_name")
    CONFIG_SITEDIRS+=("$site_dir")
    CONFIG_LANGS+=("$lang")
    CONFIG_GEOS+=("$geo")
    CONFIG_DOMAINS+=("$site_domain")
    CONFIG_PUBDIRS+=("$pubdir")
    CONFIG_SERIES+=("$series")
done < "$CONFIG_FILE"

if [[ ${#CONFIG_MPIDS[@]} -eq 0 ]]; then
    echo "ERROR: no valid site config in: $CONFIG_FILE" >&2
    echo "       expected one of:" >&2
    echo "         mp_id|git_name|site_dir|lang|geo|site_domain|pubdir[|series]" >&2
    echo "         domain|(unused)|lang|geo|git_name|pubdir|mp_id[|series]" >&2
    echo "       a line without an mp_id column cannot be matched to WeChat articles." >&2
    exit 2
fi

is_uploaded() {
    grep -Fqx -- "$1" "$UPLOADED_FILE" >/dev/null 2>&1
}

mark_uploaded() {
    printf '%s\n' "$1" >> "$UPLOADED_FILE"
}

mark_failed() {
    if ! grep -Fqx -- "$1" "$FAILED_FILE" >/dev/null 2>&1; then
        printf '%s\n' "$1" >> "$FAILED_FILE"
    fi
}

hash_hex() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum | awk '{print $1}'
    elif command -v shasum >/dev/null 2>&1; then
        shasum -a 256 | awk '{print $1}'
    elif command -v md5sum >/dev/null 2>&1; then
        md5sum | awk '{print $1}'
    else
        md5 -q
    fi
}

make_slug() {
    local title="$1" fallback="$2" slug=""

    # 优先用 PHP intl 把中文转拼音（与站点现有 WeChatService::slugify 风格一致）；
    # 未安装 intl 或没有 php CLI 时再退化为 ASCII slug。
    if command -v php >/dev/null 2>&1; then
        slug="$(printf '%s' "$title" | php -r '
            $s = stream_get_contents(STDIN);
            if (function_exists("transliterator_transliterate")) {
                $s = preg_replace(
                    "/(?<=[\\p{Han}])(?=[A-Za-z])|(?<=[A-Za-z])(?=[\\p{Han}])/u",
                    " ",
                    $s
                );
                $s = transliterator_transliterate("Any-Latin; Latin-ASCII", $s);
            }
            $s = preg_replace("/[^A-Za-z0-9]+/", "-", $s);
            echo trim($s, "-");
        ' 2>/dev/null)" || slug=""
    fi

    if [[ -z "$slug" ]]; then
        slug="$(printf '%s' "$title" | jq -Rr 'ascii_downcase | gsub("[^a-z0-9]+"; "-") | gsub("^-+|-+$"; "")')"
    fi

    if [[ -z "$slug" ]]; then
        # 无法转写时退化为微信 article_id 末段，保证 slug 唯一且 URL 安全
        slug="$(printf '%s' "$fallback" | tr '[:upper:]' '[:lower:]' \
            | sed -E 's/[^a-z0-9]+/-/g; s/^-+//; s/-+$//')"
    fi

    if [[ -z "$slug" ]]; then
        slug="article"
    fi
    printf '%s' "${slug:0:120}"
}

epoch_from_datetime() {
    local value="$1"
    jq -nr --arg t "$value" --argjson off "$TZ_OFFSET" '
        ($t | try (strptime("%Y-%m-%d %H:%M:%S") | mktime) catch null) as $e
        | if $e == null then "" else (($e - ($off * 3600)) | floor | tostring) end
    '
}

http_get() {
    local url="$1" tmp
    tmp="$(mktemp "${TMPDIR:-/tmp}/crawler_http.XXXXXX")"

    set +e
    HTTP_STATUS="$(curl -sS --max-time "$FETCH_TIMEOUT" -o "$tmp" -w '%{http_code}' "$url")"
    CURL_STATUS=$?
    set -e

    HTTP_BODY="$(cat "$tmp")"
    rm -f "$tmp"
}

http_get_retry() {
    local url="$1" attempt=1
    while :; do
        http_get "$url"
        if [[ "$CURL_STATUS" -eq 0 && "$HTTP_STATUS" == "200" ]]; then
            return 0
        fi
        if [[ $attempt -ge $FETCH_RETRIES ]]; then
            return 1
        fi
        attempt=$((attempt + 1))
        sleep 1
    done
}

fetch_article_ids() {
    local offset=0 page=0
    : > "$IDS_FILE"

    while :; do
        page=$((page + 1))
        local url count total has_more oldest
        url="${ARTICLE_API}?action=articles&limit=${PAGE_LIMIT}&offset=${offset}"

        if ! http_get_retry "$url"; then
            echo "ERROR: failed to fetch article list: $url (curl=$CURL_STATUS http=$HTTP_STATUS)" >&2
            return 1
        fi

        if ! jq -e '.code == 0 and (.data.list | type == "array")' >/dev/null 2>&1 <<<"$HTTP_BODY"; then
            echo "ERROR: unexpected article list response (page $page): $(printf '%.300s' "$HTTP_BODY")" >&2
            return 1
        fi

        count="$(jq -r '.data.list | length' <<<"$HTTP_BODY")"
        total="$(jq -r '.data.total // 0' <<<"$HTTP_BODY")"
        # has_more 缺失时用 total 兜底，避免只取到第一页
        has_more="$(jq -r '
            if (.data | has("has_more")) then
                (if .data.has_more == true then "true" else "false" end)
            else
                ""
            end
        ' <<<"$HTTP_BODY")"

        jq -r --arg cutoff "$CUTOFF" '
            .data.list[]
            | (.created_at // .publish_time // "" | tostring) as $t
            | ($t[0:10]) as $d
            | select($cutoff == "" or $d == "" or $d >= $cutoff)
            | "\(.id)\t\($d)"
        ' <<<"$HTTP_BODY" >> "$IDS_FILE"

        echo "List page $page: count=$count total=$total offset=$offset has_more=${has_more:-unknown}"

        if [[ "$count" -eq 0 ]]; then
            break
        fi

        if [[ "$has_more" == "false" ]]; then
            break
        fi

        if [[ -n "$CUTOFF" ]]; then
            oldest="$(jq -r '
                [ .data.list[]
                  | (.created_at // .publish_time // "" | tostring)[0:10]
                  | select(. != "")
                ] | sort | .[0] // ""
            ' <<<"$HTTP_BODY")"
            if [[ -n "$oldest" && "$oldest" < "$CUTOFF" ]]; then
                echo "Stop pagination: oldest=$oldest < cutoff=$CUTOFF"
                break
            fi
        fi

        if [[ -z "$has_more" && "$total" =~ ^[0-9]+$ && "$total" -gt 0 \
            && $((offset + count)) -ge "$total" ]]; then
            break
        fi

        offset=$((offset + count))

        if [[ $page -ge 1000 ]]; then
            echo "WARN: pagination guard reached (1000 pages)" >&2
            break
        fi
    done
}

upload_article_json() {
    local file="$1" savename="$2" resp status http_code body
    set +e
    resp="$(curl -sS --max-time "$API_TIMEOUT" \
        -X POST "$API_BASE/api/uivision_upload.php" \
        -H "X-CSRF-Token: $API_TOKEN" \
        -F "uploadedFile=@$file;type=application/json" \
        -F "savename=$savename" \
        -w $'\n%{http_code}')"
    status=$?
    set -e

    if [[ "$resp" == *$'\n'* ]]; then
        http_code="${resp##*$'\n'}"
        body="${resp%$'\n'*}"
    else
        http_code=""
        body="$resp"
    fi

    printf '%s\n' "$body"

    if { [[ $status -eq 0 ]] || [[ $status -eq 28 ]]; } \
        && [[ "$http_code" == "200" ]] \
        && [[ "$body" == *"File successfully uploaded"* ]]; then
        return 0
    fi

    echo "uivision_upload failed (curl=$status http=$http_code)" >&2
    return 1
}

upload_topic() {
    local obj_file="$1" keyword="$2" git_name="$3" domain="$4"
    local lang="$5" geo="$6" pubdir="$7" ctx_id="$8"
    local response curl_status

    jq -cn \
        --arg ctx_id "$ctx_id" \
        --arg keyword "$keyword" \
        --arg git_name "$git_name" \
        --arg domain "$domain" \
        --arg lang "$lang" \
        --arg geo "$geo" \
        --arg pubdir "$pubdir" \
        --slurpfile article "$obj_file" \
        '{
            post_keyword: $keyword,
            post_gitname: $git_name,
            post_domain: $domain,
            post_lang: $lang,
            post_geo: $geo,
            post_pubdir: $pubdir,
            post_status: "aidone",
            json: $article[0]
        } + (if $ctx_id != "" then {post_uuid: $ctx_id} else {} end)' > "$PAYLOAD_FILE"

    # 不使用 --fail-with-body：旧版 curl（< 7.76）不支持该选项，
    # 成功与否统一以响应 JSON 的 ok 字段为准。
    set +e
    response="$(curl -sS --max-time "$API_TIMEOUT" \
        -X POST "$API_BASE/api/topic_ops.php" \
        -H 'Content-Type: application/json' \
        -H "X-CSRF-Token: $API_TOKEN" \
        --data-binary "@$PAYLOAD_FILE")"
    curl_status=$?
    set -e

    printf '%s\n' "$response"

    # 秒数超时（curl 28）但服务端已返回成功结果时也按成功处理
    if { [[ $curl_status -eq 0 ]] || [[ $curl_status -eq 28 ]]; } \
        && [[ -n "$response" ]] \
        && jq -e '.ok == true' >/dev/null 2>&1 <<<"$response"; then
        return 0
    fi

    echo "topic_ops failed (curl=$curl_status)" >&2
    return 1
}

PROCESS_COUNT=0
UPLOAD_COUNT=0
FAIL_COUNT=0
SKIP_COUNT=0
DRY_COUNT=0

for ((config_index = 0; config_index < ${#CONFIG_MPIDS[@]}; config_index++)); do
    echo "Site[$config_index]: mp_id=${CONFIG_MPIDS[$config_index]} git_name=${CONFIG_GITS[$config_index]} domain=${CONFIG_DOMAINS[$config_index]:-'-'} lang=${CONFIG_LANGS[$config_index]:-'-'} geo=${CONFIG_GEOS[$config_index]:-'-'} pubdir=${CONFIG_PUBDIRS[$config_index]} site_dir=${CONFIG_SITEDIRS[$config_index]:-'-'}"
done

if [[ -n "$ONLY_IDS" ]]; then
    printf '%s\n' "$ONLY_IDS" | tr ',' '\n' \
        | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//' \
        | sed '/^$/d' > "$IDS_FILE"
    echo "Mode: ids ($(wc -l < "$IDS_FILE" | tr -d ' ') requested)"
else
    echo "Mode: $MODE (today=${TODAY} cutoff=${CUTOFF:-none})"
    if ! fetch_article_ids; then
        exit 1
    fi
    echo "Selected articles: $(wc -l < "$IDS_FILE" | tr -d ' ')"
fi

if [[ ! -s "$IDS_FILE" ]]; then
    echo "Nothing to do."
    exit 0
fi

while IFS=$'\t' read -r article_key _rest; do
    article_key="${article_key%$'\r'}"
    [[ -z "$article_key" || "$article_key" == \#* ]] && continue

    if [[ $MAX_ARTICLES -gt 0 && $PROCESS_COUNT -ge $MAX_ARTICLES ]]; then
        break
    fi

    if ! http_get_retry "${ARTICLE_API}?action=article&id=${article_key}"; then
        echo "ERROR: failed to fetch article detail: id=$article_key (curl=$CURL_STATUS http=$HTTP_STATUS)" >&2
        continue
    fi

    if ! jq -e '.code == 0 and (.data | type == "object")' >/dev/null 2>&1 <<<"$HTTP_BODY"; then
        echo "ERROR: unexpected article detail response: id=$article_key $(printf '%.300s' "$HTTP_BODY")" >&2
        continue
    fi

    jq -c '.data' <<<"$HTTP_BODY" > "$DETAIL_FILE"
    mp_id="$(jq -r '.mp_id // "" | tostring' "$DETAIL_FILE")"
    article_id="$(jq -r '.article_id // .url // (if .id != null then (.id | tostring) else "" end) // ""' "$DETAIL_FILE")"
    title="$(jq -r '.title // "" | tostring' "$DETAIL_FILE")"

    if [[ -z "$article_id" ]]; then
        article_id="$article_key"
    fi

    if [[ -z "$mp_id" ]]; then
        echo "SKIP article=$article_id (detail has no mp_id)" >&2
        continue
    fi

    matched=0
    for ((config_index = 0; config_index < ${#CONFIG_MPIDS[@]}; config_index++)); do
        if [[ "${CONFIG_MPIDS[$config_index]}" != "$mp_id" ]]; then
            continue
        fi
        matched=1

        if [[ $MAX_ARTICLES -gt 0 && $PROCESS_COUNT -ge $MAX_ARTICLES ]]; then
            break 2
        fi

        git_name="${CONFIG_GITS[$config_index]}"
        lang="${CONFIG_LANGS[$config_index]}"
        geo="${CONFIG_GEOS[$config_index]}"
        site_domain="${CONFIG_DOMAINS[$config_index]}"
        config_pubdir="${CONFIG_PUBDIRS[$config_index]}"
        series="${CONFIG_SERIES[$config_index]}"

        # 详情里如果带 pubdir（字符串），优先使用；否则用站点配置
        detail_pubdir="$(jq -r '.pubdir | if type == "string" then . else empty end' "$DETAIL_FILE")"
        pubdir="${detail_pubdir:-$config_pubdir}"

        upload_key="${article_id}|${site_domain}|${lang}|${geo}|${git_name}|${pubdir}"
        if is_uploaded "$upload_key"; then
            echo "SKIP already uploaded: $upload_key"
            SKIP_COUNT=$((SKIP_COUNT + 1))
            continue
        fi

        publish_time="$(jq -r '.publish_time // .created_at // "" | tostring' "$DETAIL_FILE")"
        create_epoch="$(epoch_from_datetime "$publish_time")"
        if [[ -z "$create_epoch" ]]; then
            create_epoch="$NOW_EPOCH"
        fi

        unique="${article_id}|${site_domain}|${lang}|${geo}|${git_name}|${pubdir}"
        post_uuid="${create_epoch}$(printf '%s' "$unique" | hash_hex | cut -c1-22)"

        slug="$(make_slug "$title" "$article_id")"

        jq -cn \
            --slurpfile d "$DETAIL_FILE" \
            --arg pubdir "$pubdir" \
            --arg create_at "$create_epoch" \
            --arg update_date "$UPDATE_DATE" \
            --arg lang "$lang" \
            --arg series "$series" \
            --arg site_domain "$site_domain" \
            --arg git_name "$git_name" \
            --arg savename "$slug" \
            --arg upload_site "$UPLOAD_SITE" \
            --arg post_uuid "$post_uuid" \
            '{
                pubdir: $pubdir,
                createAt: {text: [$create_at]},
                update_date: $update_date,
                title: {text: [($d[0].title // "" | tostring)]},
                url: ($d[0].url // "" | tostring),
                lang: $lang,
                static_thumbnail: {text: [($d[0].pic_url // "" | tostring)]},
                iframesrc: {text: [""]},
                series: $series,
                tags: {text: [($d[0].mp_name // "" | tostring)]},
                keywords: {text: [($d[0].mp_name // "" | tostring)]},
                description: {text: [($d[0].description // "" | tostring)]},
                savename: {text: [$savename]},
                slug: {text: [$savename]},
                globalpublish: "yes",
                pubdomain: ([$site_domain, $git_name] | map(select(. != ""))),
                translate_to_langs: [],
                content: {html: [($d[0].content // "" | tostring)]},
                upload_site: $upload_site,
                post_uuid: $post_uuid
            }' > "$OBJ_FILE"

        jq -c '[.]' "$OBJ_FILE" > "$ARRAY_FILE"

        if [[ -n "$JSON_DIR" ]]; then
            cp "$ARRAY_FILE" "$JSON_DIR/$post_uuid.json"
        fi

        if [[ $DRY_RUN -eq 1 ]]; then
            echo "DRY article=$article_id site=$git_name/$pubdir lang=$lang geo=$geo key=$upload_key"
            echo "    json=${JSON_DIR:+$JSON_DIR/$post_uuid.json}"
            DRY_COUNT=$((DRY_COUNT + 1))
            PROCESS_COUNT=$((PROCESS_COUNT + 1))
            continue
        fi

        echo "Uploading article=$article_id title=$(printf '%.60s' "$title") site=$git_name/$pubdir lang=$lang geo=$geo"

        upload_ok=1
        if [[ "$UPLOAD_MODE" == "both" || "$UPLOAD_MODE" == "json" ]]; then
            if ! upload_article_json "$ARRAY_FILE" "$post_uuid.json"; then
                upload_ok=0
            fi
        fi

        if [[ $upload_ok -eq 1 && ( "$UPLOAD_MODE" == "both" || "$UPLOAD_MODE" == "topic" ) ]]; then
            if ! upload_topic "$OBJ_FILE" "$title" "$git_name" "$site_domain" "$lang" "$geo" "$pubdir" "$post_uuid"; then
                upload_ok=0
            fi
        fi

        if [[ $upload_ok -eq 1 ]]; then
            mark_uploaded "$upload_key"
            UPLOAD_COUNT=$((UPLOAD_COUNT + 1))
            PROCESS_COUNT=$((PROCESS_COUNT + 1))
            echo "Uploaded $upload_key"
        else
            mark_failed "$upload_key"
            FAIL_COUNT=$((FAIL_COUNT + 1))
            PROCESS_COUNT=$((PROCESS_COUNT + 1))
            echo "FAILED $upload_key" >&2
        fi
    done

    if [[ $matched -eq 0 ]]; then
        echo "SKIP article=$article_id mp_id=$mp_id (no matching config)"
    fi
done < "$IDS_FILE"

echo "Done: uploaded=$UPLOAD_COUNT failed=$FAIL_COUNT skipped=$SKIP_COUNT dry_run=$DRY_COUNT (uploaded_file=$UPLOADED_FILE failed_file=$FAILED_FILE)"

if [[ $FAIL_COUNT -gt 0 ]]; then
    exit 1
fi
