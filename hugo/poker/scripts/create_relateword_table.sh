#!/bin/bash

echo 'createtime|subword|status|domain|pubdir|lang|mainword' > table_relatedword_new.txt

# cat keyword_monitor_list.txt |cut -d'|' -f1 |while read ctx_id
# do
#   echo $ctx_id
#   keyword=$(cat keyword_monitor_list.txt |grep -F "${ctx_id}|" |cut -d'|' -f2)
#   lang=$(cat keyword_monitor_list.txt |grep -F "${ctx_id}|" |cut -d'|' -f6)
#   createtime=$(cat keyword_monitor_list.txt |grep -F "${ctx_id}|" |cut -d'|' -f1 |cut -c 1-10)
#   relatedword_file=$(echo "${lang}_${keyword}_relatedword.json" | sed -E "s/[[:space:]]{1,}/_/g" |sed -E "s/_{2,}$/_/g")
#   ls -s seodata/${relatedword_file}
#   if [ -f seodata/${relatedword_file} ]; then
#     cat seodata/${relatedword_file} | jq -r --arg lang "$lang" --arg createtime "$createtime" '.[] | "\($createtime)|\($lang)|\(.mainword)|\(.subword)|\(.)"'
#   fi
# done | tee -a table_relatedword.txt


cat keyword_monitor_list.txt | cut -d'|' -f1 | while read ctx_id
do
  echo $ctx_id
  keyword=$(cat keyword_monitor_list.txt | grep -F "${ctx_id}|" | cut -d'|' -f2)
  lang=$(cat keyword_monitor_list.txt | grep -F "${ctx_id}|" | cut -d'|' -f6)
  status=$(cat keyword_monitor_list.txt | grep -F "${ctx_id}|" | cut -d'|' -f3)
  pubdir="article"
  gitname=$(cat keyword_monitor_list.txt | grep -F "${ctx_id}|" | cut -d'|' -f4)
  domain=$(cat siteops_setting.txt | grep -F "|${gitname}|" | cut -d'|' -f6)
  # gitname
  createtime=$(cat keyword_monitor_list.txt | grep -F "${ctx_id}|" | cut -d'|' -f1)
  relatedword_file=$(echo "${lang}_${keyword}_relatedword.json" | sed -E "s/[[:space:]]{1,}/_/g" | sed -E "s/_{2,}$/_/g")
  ls -s seodata/${relatedword_file}
  if [ -f seodata/${relatedword_file} ]; then
    cat seodata/${relatedword_file} | \
    jq -r --arg lang "$lang" --arg createtime "$createtime" --arg gitname "$gitname" --arg domain "$domain" --arg pubdir "$pubdir" --arg status "$status" '.[] | select((.mainword | split(" ") | length) < (.subword | split(" ") | length)) | "\($createtime)|\(.subword)|\($status)|\($domain)|\($pubdir)|\($lang)|\(.mainword)"' | \
    tee -a table_relatedword_new.txt
  fi
done


if [ -f table_relatedword_new.txt ]; then
  mv -f table_relatedword_new.txt table_relatedword.txt
fi

