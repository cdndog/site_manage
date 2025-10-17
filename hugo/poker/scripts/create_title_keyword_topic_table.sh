#!/bin/bash

# echo 'createtime|question|status|domain|pubdir|lang|mainword' > table_relatedfaqlist_new.txt
echo '' > table_title_keyword_topic_new.txt

[[ ! -f published_title_keyword_topic_list.txt ]] && touch published_title_keyword_topic_list.txt

cat keyword_monitor_list.txt | cut -d'|' -f1 | while read ctx_id
do
  echo $ctx_id
  keyword=$(cat keyword_monitor_list.txt | grep -F "${ctx_id}|" | cut -d'|' -f2)
  lang=$(cat keyword_monitor_list.txt | grep -F "${ctx_id}|" | cut -d'|' -f6)
  geo=$(cat keyword_monitor_list.txt | grep -F "${ctx_id}|" | cut -d'|' -f7 |jq -r '.geo // empty')
  status=$(cat keyword_monitor_list.txt | grep -F "${ctx_id}|" | cut -d'|' -f3)
  pubdir="home"
  gitname=$(cat keyword_monitor_list.txt | grep -F "${ctx_id}|" | cut -d'|' -f4)
  domain=$(cat siteops_setting.txt | grep -F "|${gitname}|" | cut -d'|' -f6)
  
  createtime=$(cat keyword_monitor_list.txt | grep -F "${ctx_id}|" | cut -d'|' -f1)
  title_keyword_file=$(echo "${lang}_${keyword}.json" | sed -E "s/[[:space:]]{1,}/_/g" | sed -E "s/_{2,}$/_/g")
  relatedword_file=$(echo "${lang}_${keyword}_relatedword.json" | sed -E "s/[[:space:]]{1,}/_/g" | sed -E "s/_{2,}$/_/g")
  ls -s seodata/${title_keyword_file}
  if [ -f seodata/${title_keyword_file} ]; then
    cat seodata/${title_keyword_file} | \
    jq -r --arg lang "$lang" \
          --arg geo "$geo" \
          --arg createtime "$createtime" \
          --arg keyword "$keyword" \
          --arg domain "$domain" \
          --arg gitname "$gitname" \
          --arg pubdir "$pubdir" \
          --arg status "$status" '
          .[] 
          | select(has("title") and has("pubdomain") and has("url") and has("gitname") and (.url | contains($domain) | not) and (.title | contains("Apps on Google Play") | not) and (.title | contains("on the App Store") | not) and (.title | contains("http") | not) and (.title | contains("@") | not) and (.title | contains("https") | not))
          | .title |= gsub("\\|"; " ")
          | "\($gitname)|\($domain)|\(.title)|\($pubdir)|\($status)|\($lang)|\($geo)|\(.url)"' | \
    tee -a table_title_keyword_topic_new.txt
  fi
done

if [ -f table_title_keyword_topic_new.txt ]; then
  mv -f table_title_keyword_topic_new.txt table_title_keyword_topic.txt
fi

if [ -f topic_bulk_update_via_import.php ] && [ -f table_title_keyword_topic.txt  ]; then
  php -f topic_bulk_update_via_import.php import_file="table_title_keyword_topic.txt"
fi

if [ $(id www |grep -c "www") -eq 1 ]; then
  chown www:www table_title_keyword_topic.txt published_title_keyword_topic_list.txt topic_monitor_list.txt create_title_keyword_topic_table.sh
fi

