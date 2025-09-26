#!/bin/bash

# echo 'createtime|question|status|domain|pubdir|lang|mainword' > table_relatedfaqlist_new.txt
echo '' > table_faq_topic_new.txt

[[ ! -f published_faq_topic_list.txt ]] && touch published_faq_topic_list.txt

cat keyword_monitor_list.txt | cut -d'|' -f1 | while read ctx_id
do
  echo $ctx_id
  keyword=$(cat keyword_monitor_list.txt | grep -F "${ctx_id}|" | cut -d'|' -f2)
  lang=$(cat keyword_monitor_list.txt | grep -F "${ctx_id}|" | cut -d'|' -f6)
  geo=$(cat keyword_monitor_list.txt | grep -F "${ctx_id}|" | cut -d'|' -f7 |jq -r '.geo')
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
          --arg geo "$geo" \
          --arg createtime "$createtime" \
          --arg keyword "$keyword" \
          --arg domain "$domain" \
          --arg gitname "$gitname" \
          --arg pubdir "$pubdir" \
          --arg status "$status" '
          .[]
          | "\($gitname)|\($domain)|\(.faqlist_q)|\($pubdir)|\($status)|\($lang)|\($geo)"' | \
    tee -a table_faq_topic_new.txt
  fi
done

if [ -f table_faq_topic_new.txt ]; then
  mv -f table_faq_topic_new.txt table_faq_topic.txt
fi

if [ -f topic_bulk_update_via_import.php ] && [ -f table_faq_topic.txt  ]; then
  php -f topic_bulk_update_via_import.php import_file="table_faq_topic.txt"
fi

if [ $(id www |grep -c "www") -eq 1 ]; then
  chown www:www table_faq_topic.txt published_faq_topic_list.txt topic_monitor_list.txt create_faq_topic_table.sh
fi

