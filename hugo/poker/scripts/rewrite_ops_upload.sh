#!/usr/bin/env bash
set -euo pipefail

API_BASE="${API_BASE:-http://127.0.0.1:8888}"
API_TOKEN="${API_TOKEN:?set API_TOKEN first}"
API_TIMEOUT="${API_TIMEOUT:-120}"

ARTICLE_FILE="${ARTICLE_FILE:-}"
GIT_NAME="${GIT_NAME:-}"
KEYWORD="${KEYWORD:-}"
CTX_ID="${CTX_ID:-}"
DOMAIN="${DOMAIN:-}"
LANG="${LANG:-}"
GEO="${GEO:-}"
PUBDIR="${PUBDIR:-article}"

usage() {
    echo "usage: API_TOKEN=... $0 article=/path/article.json git_name=*** keyword=*** [ctx_id=***] [domain=***] [lang=***] [geo=***] [pubdir=article]" >&2
}

for arg in "$@"; do
    case "$arg" in
        article=*|article_file=*) ARTICLE_FILE="${arg#*=}" ;;
        git_name=*) GIT_NAME="${arg#*=}" ;;
        keyword=*) KEYWORD="${arg#*=}" ;;
        ctx_id=*) CTX_ID="${arg#*=}" ;;
        domain=*) DOMAIN="${arg#*=}" ;;
        lang=*) LANG="${arg#*=}" ;;
        geo=*) GEO="${arg#*=}" ;;
        pubdir=*) PUBDIR="${arg#*=}" ;;
        -h|--help) usage; exit 0 ;;
        *) echo "unknown argument: $arg" >&2; usage; exit 2 ;;
    esac
done

if [[ -z "$ARTICLE_FILE" || -z "$GIT_NAME" || -z "$KEYWORD" ]]; then
    usage
    exit 2
fi

if [[ ! -f "$ARTICLE_FILE" ]]; then
    echo "article file not found: $ARTICLE_FILE" >&2
    exit 2
fi

ARTICLE_JSON="$(jq -c 'if type == "array" then .[0] else . end' "$ARTICLE_FILE")"
if [[ "$ARTICLE_JSON" == "null" || "$ARTICLE_JSON" == "{}" ]]; then
    echo "article file is empty: $ARTICLE_FILE" >&2
    exit 2
fi

PAYLOAD="$(jq -cn \
    --arg ctx_id "$CTX_ID" \
    --arg keyword "$KEYWORD" \
    --arg git_name "$GIT_NAME" \
    --arg domain "$DOMAIN" \
    --arg lang "$LANG" \
    --arg geo "$GEO" \
    --arg pubdir "$PUBDIR" \
    --argjson article "$ARTICLE_JSON" \
    '{
        post_keyword: $keyword,
        post_gitname: $git_name,
        post_domain: $domain,
        post_lang: $lang,
        post_geo: $geo,
        post_pubdir: $pubdir,
        json: $article
    } + (if $ctx_id != "" then {post_uuid: $ctx_id} else {} end)')"

set +e
RESPONSE="$(curl -sS --fail-with-body --max-time "$API_TIMEOUT" \
    -X POST "$API_BASE/api/rewrite_ops.php" \
    -H 'Content-Type: application/json' \
    -H "X-CSRF-Token: $API_TOKEN" \
    --data-binary "$PAYLOAD")"
CURL_STATUS=$?
set -e
printf '%s\n' "$RESPONSE"
if [[ $CURL_STATUS -eq 0 ]]; then
    exit 0
fi
if [[ $CURL_STATUS -eq 28 && -n "$RESPONSE" ]] && jq -e '.ok == true' >/dev/null 2>&1 <<<"$RESPONSE"; then
    exit 0
fi
exit "$CURL_STATUS"
