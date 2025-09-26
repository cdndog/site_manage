#!/bin/bash

cat "task_config.txt" | while read singleText
do
  serverDomain=$(echo ${singleText} |cut -d'|' -f1)
  siteType=$(echo ${singleText} |cut -d'|' -f1)
  uploadUrl=$(echo ${singleText} |cut -d'|' -f1)
  # siteType="poker"
  topicUrl="${serverDomain}/hugo/topicquery.php?t=all"
  # uploadUrl="https://wptg.wptdata.com/hugo/uivision_upload.php"
  localTaskList="${siteType}_perplexity_seo_keyword.csv"

  curl -L "${topicUrl}" | jq -r --arg siteType "${uploadUrl}" '.[] | select(.status == "enable") |
       [.keyword, "Please answer the following topic as an blog writer in language["+ .lang + "]." ,.lang, "Each paragraph should be detailed(data, examples), complete.", .domain, .pubdir, $siteType ] |@csv' | \
       tee "${localTaskList}"
done
