#!/usr/bin/env bash
set -euo pipefail

# 所有入参均通过命令行选项传入，不读取环境变量，避免环境变量污染程序
API_BASE="https://wpk.cpanice.com/v2"
API_TOKEN=""
API_TIMEOUT="120"

CONFIG_FILE="crawler_publish_config.txt"
UPLOADED_FILE="rewrite_uploaded.list"
FAILED_FILE="rewrite_failed.list"

TOPIC_FILE=""
PROCESS_MODE="today"

PROCESS_FILE_TMP=""

cleanup() {
    if [[ -n "$PROCESS_FILE_TMP" ]]; then rm -f "$PROCESS_FILE_TMP"; fi
}
trap cleanup EXIT

usage() {
    cat >&2 <<EOF
Usage: $0 -f <topic_file> [options]

  -f <file>   Topic list file, one topic per line: <id>|<url>|<json>  (required)
  -t <mode>   all: upload every topic in the file
              today (default): only topics whose json update_date is today (UTC)

  -c <file>   Site config file, default: crawler_publish_config.txt
              lines: domain|(unused)|lang|geo|git_name|pubdir
  -b <url>    API base URL, default: https://wpk.cpanice.com/v2
  -k <token>  API token, sent as X-CSRF-Token (default: empty)
  -m <secs>   curl max time, default: 120
  -u <file>   Uploaded record file, default: rewrite_uploaded.list
  -x <file>   Failed record file, default: rewrite_failed.list
  -h          Show this help

Topics are uploaded to api/rewrite_ops.php with post_status=rewrite.
Uploaded records are keyed by <topic_id>|<git_name>|<pubdir>, so a topic can be
uploaded once per site/pubdir. Remove a line from the uploaded file to retry it.
EOF
}

while getopts ":f:t:c:b:k:m:u:x:h" opt; do
    case "$opt" in
        f)
            TOPIC_FILE="$OPTARG"
            ;;
        t)
            PROCESS_MODE="$OPTARG"
            ;;
        c)
            CONFIG_FILE="$OPTARG"
            ;;
        b)
            API_BASE="$OPTARG"
            ;;
        k)
            API_TOKEN="$OPTARG"
            ;;
        m)
            API_TIMEOUT="$OPTARG"
            ;;
        u)
            UPLOADED_FILE="$OPTARG"
            ;;
        x)
            FAILED_FILE="$OPTARG"
            ;;
        h)
            usage
            exit 0
            ;;
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

if [[ "$PROCESS_MODE" != "all" && "$PROCESS_MODE" != "today" ]]; then
    echo "ERROR: Invalid value for -t: $PROCESS_MODE (allowed: all, today)" >&2
    usage
    exit 2
fi

if [[ -z "$TOPIC_FILE" ]]; then
    echo "ERROR: -f <topic_file> is required" >&2
    usage
    exit 2
fi

if [[ ! -f "$TOPIC_FILE" ]]; then
    echo "ERROR: Topic file not found: $TOPIC_FILE" >&2
    exit 2
fi

if [[ ! -r "$TOPIC_FILE" ]]; then
    echo "ERROR: Topic file is not readable: $TOPIC_FILE" >&2
    exit 2
fi

if [[ ! "$API_TIMEOUT" =~ ^[0-9]+$ || "$API_TIMEOUT" -le 0 ]]; then
    echo "ERROR: Invalid value for -m: $API_TIMEOUT (must be a positive integer)" >&2
    exit 2
fi

if [[ ! -f "$CONFIG_FILE" ]]; then
    echo "ERROR: Config file not found: $CONFIG_FILE" >&2
    exit 2
fi

# 当天日期（UTC，与 topic_json 中 update_date 的 Z 时间保持一致）
TODAY=$(date -u +%Y-%m-%d)

touch "$UPLOADED_FILE" "$FAILED_FILE"

echo "Mode: $PROCESS_MODE (today=${TODAY})"

# 默认模式：先用一次 jq 调用把「当天更新」的合法数据预过滤到临时文件，
# 避免在主循环里对整份文件逐行 fork jq 做日期判断
if [[ "$PROCESS_MODE" == "all" ]]; then
    PROCESS_FILE="$TOPIC_FILE"
else
    PROCESS_FILE_TMP="$(mktemp)"
    PROCESS_FILE="$PROCESS_FILE_TMP"

    jq -R -r --arg today "$TODAY" '
        select(. != "" and (startswith("#") | not))
        | (split("|") | .[2:] | join("|")) as $json
        | (try ($json | fromjson) catch null) as $obj
        | select($obj != null)
        | select(($obj.update_date // "")[0:10] == $today)
        | .
    ' "$TOPIC_FILE" > "$PROCESS_FILE" || true

    echo "Pre-filtered (${TODAY}): $(wc -l < "$PROCESS_FILE" | tr -d ' ') / $(wc -l < "$TOPIC_FILE" | tr -d ' ') lines"
fi

# 站点配置统一从配置文件读取（路径由 -c 指定）
CONFIG_SOURCE="$CONFIG_FILE"

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

upload_topic() {
    local domain="$1" lang="$2" geo="$3" git_name="$4" pubdir="$5"
    local keyword="$6" ctx_id="$7" topic_json="$8"

    local payload response curl_status
    payload="$(jq -cn \
        --arg ctx_id "$ctx_id" \
        --arg keyword "$keyword" \
        --arg git_name "$git_name" \
        --arg domain "$domain" \
        --arg lang "$lang" \
        --arg geo "$geo" \
        --arg pubdir "$pubdir" \
        --argjson article "$topic_json" \
        '{
            post_keyword: $keyword,
            post_gitname: $git_name,
            post_domain: $domain,
            post_lang: $lang,
            post_geo: $geo,
            post_pubdir: $pubdir,
            post_status: "rewrite",
            json: $article
        } + (if $ctx_id != "" then {post_uuid: $ctx_id} else {} end)')"

    # 不使用 --fail-with-body：旧版 curl（< 7.76）不支持该选项，
    # 成功与否统一以响应 JSON 的 ok 字段为准。
    set +e
    response="$(curl -sS --max-time "$API_TIMEOUT" \
        -X POST "$API_BASE/api/rewrite_ops.php" \
        -H 'Content-Type: application/json' \
        -H "X-CSRF-Token: $API_TOKEN" \
        --data-binary "$payload")"
    curl_status=$?
    set -e

    printf '%s\n' "$response"

    # 秒数超时（curl 28）但服务端已返回成功结果时也按成功处理
    if { [[ $curl_status -eq 0 ]] || [[ $curl_status -eq 28 ]]; } \
        && [[ -n "$response" ]] \
        && jq -e '.ok == true' >/dev/null 2>&1 <<<"$response"; then
        return 0
    fi

    echo "curl exit status: $curl_status" >&2
    return 1
}

UPLOAD_COUNT=0
FAIL_COUNT=0

while IFS= read -r config_line || [[ -n "$config_line" ]]; do
    config_line="${config_line%$'\r'}"
    [[ -z "$config_line" || "$config_line" == \#* ]] && continue

    # crawler_publish_config.txt: domain|(unused)|lang|geo|git_name|pubdir
    IFS='|' read -r domain _unused lang geo git_name pubdir _rest <<< "$config_line"
    pubdir="${pubdir:-article}"

    if [[ -z "$git_name" ]]; then
        echo "WARN: skip config line without git_name: $config_line" >&2
        continue
    fi

    echo "==> site: domain=${domain:-'-'} git_name=$git_name lang=${lang:-'-'} geo=${geo:-'-'} pubdir=$pubdir"

    while IFS= read -r line || [[ -n "$line" ]]; do
        line="${line%$'\r'}"
        [[ -z "$line" || "$line" == \#* ]] && continue

        if [[ "$line" != *"|"* ]]; then
            echo "SKIP malformed line (expected id|url|json): ${line:0:80}" >&2
            continue
        fi

        # 按 | 分割：第1列 ID，第2列 URL，第3列起为 JSON
        # topic_id="${line%%|*}"
        # rest="${line#*|}"
        # topic_url="${rest%%|*}"
        # topic_json="${rest#*|}"
        # 按 | 分割：第1列 ID，第2列 URL，第3列 JSON
        topic_id=$(echo "$line" | cut -d'|' -f1)
        topic_url=$(echo "$line" | cut -d'|' -f2)
        topic_json=$(echo "$line" | cut -d'|' -f3-)

        # JSON 格式不合法则直接跳过（不处理、不记录失败）
        if ! printf '%s' "$topic_json" | jq -e . >/dev/null 2>&1; then
            echo "SKIP invalid JSON: $topic_id" >&2
            continue
        fi

        keyword="$(printf '%s' "$topic_json" | jq -r '.title.text[0] // .keyword // empty')"
        ctx_id="$(printf '%s' "$topic_json" | jq -r '.post_uuid // .ctx_id // empty')"

        # ctx_id 不合法时交给服务端生成，避免整条上传失败
        if [[ -n "$ctx_id" && ! "$ctx_id" =~ ^[A-Za-z0-9_-]{1,64}$ ]]; then
            echo "WARN: ignoring invalid ctx_id '$ctx_id' for $topic_id" >&2
            ctx_id=""
        fi

        upload_key="${topic_id}|${git_name}|${pubdir}"
        if is_uploaded "$upload_key"; then
            echo "SKIP already uploaded: $upload_key"
            continue
        fi

        if [[ -z "$keyword" ]]; then
            echo "ERROR: cannot extract keyword from title.text[0] / keyword for $topic_id" >&2
            mark_failed "$upload_key"
            FAIL_COUNT=$((FAIL_COUNT + 1))
            continue
        fi

        echo "Uploading $topic_id ($topic_url) keyword=$keyword"
        if upload_topic "$domain" "$lang" "$geo" "$git_name" "$pubdir" "$keyword" "$ctx_id" "$topic_json"; then
            mark_uploaded "$upload_key"
            UPLOAD_COUNT=$((UPLOAD_COUNT + 1))
            echo "Uploaded $upload_key"
        else
            mark_failed "$upload_key"
            FAIL_COUNT=$((FAIL_COUNT + 1))
            echo "FAILED $upload_key" >&2
        fi
    done < "$PROCESS_FILE"
done < "$CONFIG_SOURCE"

echo "Done: uploaded=$UPLOAD_COUNT failed=$FAIL_COUNT (uploaded_file=$UPLOADED_FILE failed_file=$FAILED_FILE)"

if [[ $FAIL_COUNT -gt 0 ]]; then
    exit 1
fi
