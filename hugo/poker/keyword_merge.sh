#!/bin/bash

# grep -v "^#" keyword_monitor_list_20240922.txt |grep -v "^$" |sort -R | awk -F'|' 'NF == 7 {print}' | while read CONFIG
# do
#   ctx_id=$(echo "$CONFIG" |cut -d'|' -f1)
#   keyword=$(echo "$CONFIG" |cut -d'|' -f2)
#   poststatus=$(echo "$CONFIG" |cut -d'|' -f3)
#   gitname=$(echo "$CONFIG" |cut -d'|' -f4)
#   pubdir=$(echo "$CONFIG" |cut -d'|' -f5)
#   lang=$(echo "$CONFIG" |cut -d'|' -f6)
#   json=$(echo "$CONFIG" |cut -d'|' -f7)
#   geo=$(echo "$json" |jq -r '.geo' |grep -v 'null')
#   lasttask=$(echo "$json" |jq -r '.lasttask' |grep -v 'null')
#   if [ "X${geo}" == "X" ] || [ "X${lang}" == "X" ] ; then
#     echo "geo -> $geo , lang -> $lang is null"
#     continue
#   fi

#   curl -L -X POST "http://localhost:8888/hugo/keywordops.php" \
#     --data-urlencode "post_uuid=${ctx_id}"  \
#     --data-urlencode "post_keyword=${keyword}"  \
#     --data-urlencode "setupNum=ckeditorFormated" \
#     --data-urlencode "post_pubdir=${pubdir}"  \
#     --data-urlencode "post_status=${poststatus}"  \
#     --data-urlencode "post_lang=${lang}"  \
#     --data-urlencode "post_geo=${geo}"  \
#     --data-urlencode "post_gitname=${gitname}" \
#     --data-urlencode "post_lasttask=${lasttask}" \
#     --data-urlencode "post_bulkkeyword=disable"
# done


curl -sfkL "https://wpk.cpanice.com/hugo/keyword_monitor_list.txt" -o keyword_monitor_list.txt 

grep -v "^#" keyword_monitor_list.txt |grep -v "^$" |sort -R | awk -F'|' 'NF == 7 {print}' | while read CONFIG
do
  ctx_id=$(echo "$CONFIG" |cut -d'|' -f1)
  keyword=$(echo "$CONFIG" |cut -d'|' -f2)
  poststatus=$(echo "$CONFIG" |cut -d'|' -f3)
  gitname=$(echo "$CONFIG" |cut -d'|' -f4)
  pubdir=$(echo "$CONFIG" |cut -d'|' -f5)
  lang=$(echo "$CONFIG" |cut -d'|' -f6)
  json=$(echo "$CONFIG" |cut -d'|' -f7)
  geo=$(echo "$json" |jq -r '.geo' |grep -v 'null')
  lasttask=$(echo "$json" |jq -r '.lasttask' |grep -v 'null')
  if [ "X${geo}" == "X" ] || [ "X${lang}" == "X" ] ; then
    echo "geo -> $geo , lang -> $lang is null"
    continue
  fi

  curl -L -X POST "http://localhost:8888/hugo_wpk/keywordops.php" \
    --data-urlencode "post_uuid=${ctx_id}"  \
    --data-urlencode "post_keyword=${keyword}"  \
    --data-urlencode "setupNum=ckeditorFormated" \
    --data-urlencode "post_pubdir=${pubdir}"  \
    --data-urlencode "post_status=${poststatus}"  \
    --data-urlencode "post_lang=${lang}"  \
    --data-urlencode "post_geo=${geo}"  \
    --data-urlencode "post_gitname=${gitname}" \
    --data-urlencode "post_lasttask=${lasttask}" \
    --data-urlencode "post_bulkkeyword=disable"
done