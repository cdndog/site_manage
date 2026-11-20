#!/bin/bash
set -euo pipefail

TOPIC_FILE=""
PROCESS_MODE="today"

usage() {
    cat >&2 <<EOF
Usage: $0 -f <topic_file> [-t all]

  -f  Topic list file, e.g. vipgrinders_article_allpost_list.txt
  -t  all: upload all topics
      (default) only upload topics whose update_date is today (UTC)
EOF
    exit 1
}

while getopts ":f:t:" opt; do
    case "$opt" in
        f)
            TOPIC_FILE="$OPTARG"
            ;;
        t)
            PROCESS_MODE="$OPTARG"
            ;;
        \?)
            echo "ERROR: Unknown option: -$OPTARG" >&2
            usage
            ;;
        :)
            echo "ERROR: Option -$OPTARG requires an argument" >&2
            usage
            ;;
    esac
done

if [[ "$PROCESS_MODE" != "all" && "$PROCESS_MODE" != "today" ]]; then
    echo "ERROR: Invalid value for -t: $PROCESS_MODE (allowed: all)" >&2
    usage
fi

if [[ -z "$TOPIC_FILE" ]]; then
    echo "ERROR: -f <topic_file> is required" >&2
    usage
fi

if [[ ! -f "$TOPIC_FILE" ]]; then
    echo "ERROR: Topic file not found: $TOPIC_FILE" >&2
    exit 1
fi

if [[ ! -r "$TOPIC_FILE" ]]; then
    echo "ERROR: Topic file is not readable: $TOPIC_FILE" >&2
    exit 1
fi

API_DOMAIN="https://wptg.wptdata.com/v2/api/topic_ops.php"

# 当天日期（UTC，与 topic_json 中 update_date 的 Z 时间保持一致）
TODAY=$(date -u +%Y-%m-%d)

# TOPIC_FILE="vipgrinders_article_allpost_list.txt"
UPLOADED_FILE="uploaded.list"
FAILED_FILE="failed.list"

touch "$UPLOADED_FILE" "$FAILED_FILE"

echo "Mode: $PROCESS_MODE (today=${TODAY})"

# 默认模式：先用一次 jq 调用把「当天更新」的合法数据预过滤到临时文件，
# 避免在主循环里对整份文件逐行 fork jq 做日期判断
if [[ "$PROCESS_MODE" == "all" ]]; then
  PROCESS_FILE="$TOPIC_FILE"
else
  PROCESS_FILE=$(mktemp)
  trap 'rm -f "$PROCESS_FILE"' EXIT

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

cat crawler_publish_config.txt |grep -v "^#" |while read CONFIG
do
  # sitedir=$(echo $CONFIG | cut -d'|' -f2);
  domain=$(echo $CONFIG | cut -d'|' -f1);
  lang=$(echo $CONFIG | cut -d'|' -f3);
  geo=$(echo $CONFIG | cut -d'|' -f4);
  git_name=$(echo $CONFIG | cut -d'|' -f5);
  pubdir=$(echo $CONFIG | cut -d'|' -f6);
  post_status="enable"

#pubdir="news"
#post_status="enable"
#lang="ja"
#geo="JP"
#git_name="betmanjapan"
#domain="betmanjapan.com"

while IFS= read -r line || [ -n "$line" ]; do
  # 跳过空行和注释
  [[ -z "$line" || "$line" == \#* ]] && continue

  # 按 | 分割：第1列 ID，第2列 URL，第3列 JSON
  topic_id=$(echo "$line" | cut -d'|' -f1)
  topic_url=$(echo "$line" | cut -d'|' -f2)
  topic_json=$(echo "$line" | cut -d'|' -f3-)

  # JSON 格式不合法则直接跳过（不处理、不记录失败）
  if ! printf '%s' "$topic_json" | jq -e . >/dev/null 2>&1; then
    echo "SKIP invalid JSON: $topic_id" >&2
    continue
  fi

  # 已上传则跳过
  if grep -Fqx "$topic_id" "$UPLOADED_FILE"; then
    echo "SKIP already uploaded: $topic_id"
    continue
  fi

  # 默认模式下 update_date 已在预过滤阶段筛选，此处无需再逐行 fork jq 判断

  # 从 JSON 提取 title.text[0]
  keyword=$(printf '%s' "$topic_json" | jq -r '.title.text[0] // empty')

  if [[ -z "$keyword" ]]; then
    echo "ERROR: cannot extract keyword for $topic_id" >&2
    echo "$topic_id" >> "$FAILED_FILE"
    continue
  fi

  response=$(curl -sLkS \
    --data-urlencode "keyword=${keyword}" \
    --data-urlencode "pubdir=${pubdir}" \
    --data-urlencode "status=${post_status}" \
    --data-urlencode "lasttask=${post_status}" \
    --data-urlencode "lang=${lang}" \
    --data-urlencode "geo=${geo}" \
    --data-urlencode "git_name=${git_name}" \
    --data-urlencode "domain=${domain}" \
    "${API_DOMAIN}")

  echo "Uploaded $topic_id: $response"

  # 如需更严格判断，可依据 API 返回内容判断成功条件
  echo "$topic_id" >> "$UPLOADED_FILE"

done < "$PROCESS_FILE"

done
