#!/bin/bash

echo 'createtime|question|status|domain|pubdir|lang|mainword' > table_relatedfaqlist_new.txt

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
  pubdir="faqs"
  gitname=$(cat keyword_monitor_list.txt | grep -F "${ctx_id}|" | cut -d'|' -f4)
  domain=$(cat siteops_setting.txt | grep -F "|${gitname}|" | cut -d'|' -f6)
  createtime=$(cat keyword_monitor_list.txt | grep -F "${ctx_id}|" | cut -d'|' -f1)
  relatedfaq_file=$(echo "${lang}_${keyword}_relatedfaq.json" | sed -E "s/[[:space:]]{1,}/_/g" | sed -E "s/_{2,}$/_/g")
  ls -s seodata/${relatedfaq_file}
  if [ -f seodata/${relatedfaq_file} ]; then
    cat seodata/${relatedfaq_file} | \
    jq -r --arg lang "$lang" \
          --arg createtime "$createtime" \
          --arg keyword "$keyword" \
          --arg domain "$domain" \
          --arg pubdir "$pubdir" \
          --arg status "$status" '
          .[]
          | (.faqlist_a // "") as $a
          | (.faqlist_q // "") as $q
          | select(($q | split(" ")
          | length) < ($a | split(" ") | length))
          | "\($createtime)|\(.faqlist_q)|\($status)|\($domain)|\($pubdir)|\($lang)|\($keyword)"' | \
    tee -a table_relatedfaqlist_new.txt
  fi
done


if [ -f table_relatedfaqlist_new.txt ]; then
  mv -f table_relatedfaqlist_new.txt table_relatedfaqlist.txt
fi


