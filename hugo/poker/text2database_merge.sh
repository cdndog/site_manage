#!/bin/bash


curl -L "https://wpk.cpanice.com/hugo/sitequery.php?t=all" | jq -c '.[]' | while read -r ALLCONF
do
  echo "$ALLCONF"
  ctx_id=$(echo "${ALLCONF}" |jq -r .id)
  git_name=$(echo "${ALLCONF}" |jq -r .git_name)
  post_status=$(echo "${ALLCONF}" |jq -r .status)
  theme_name=$(echo "${ALLCONF}" |jq -r .theme_name)
  theme_type=$(echo "${ALLCONF}" |jq -r .theme_type)
  languages=$(echo "${ALLCONF}" |jq -r .languages)
  domain=$(echo "${ALLCONF}" |jq -r .domain)
  sns_id=$(echo "${ALLCONF}" |jq -r .sns_id)
  topnav_menus=$(echo "${ALLCONF}" |jq -r .topnav_menus)
  site_title=$(echo "${ALLCONF}" |jq -r .site_title)
  site_subtitle=$(echo "${ALLCONF}" |jq -r .site_subtitle)
  site_logo=$(echo "${ALLCONF}" |jq -r .site_logo)
  post_sitedir=$(echo "${ALLCONF}" |jq -r .sitedir)
  keyword=$(echo "${ALLCONF}" |jq -r .keyword)
  deploy=$(echo "${ALLCONF}" |jq -r .deploy)
  hostip=$(echo "${ALLCONF}" |jq -r .hostip)
  git_account=$(echo "${ALLCONF}" |jq -r .git_account)
  deployModel=$(echo "${ALLCONF}" |jq -r .deploy)
  localIP=$(echo "${ALLCONF}" |jq -r .local_hostip)

    curl -X POST -L \
    http://localhost:8888/hugo_wpk/siteops.php \
    -d setupNum="ckeditorFormated" \
    -d post_uuid="${ctx_id}" \
    -d post_gitname="${git_name}" \
    -d post_gitaccount="${git_account}" \
    -d post_themename="${theme_name}" \
    -d post_themetype="${theme_type}" \
    -d post_sitedir="${post_sitedir}" \
    -d post_lang="${languages}" \
    -d post_domain="${domain}" \
    -d post_sitedeploy="${deploy}" \
    -d post_sitehostip="${hostip}" \
    -d local_deploy="${deployModel}" \
    -d local_hostip="${localIP}" \
    -d post_sns_id="${sns_id}" \
    -d post_topnavmenus="${topnav_menus}" \
    -d post_sitetitle="${site_title}" \
    -d post_description="${site_subtitle}" \
    -d post_sitelogo="${site_logo}" \
    -d post_keyword="${keyword}" \
    -d post_status="${post_status}"
done