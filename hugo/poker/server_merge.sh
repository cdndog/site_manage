cat serverlist_demo_old.txt |grep -v "^$" | awk -F'|' 'NF == 11 {print}' | while read Server
do
  echo "$Server"
  ALLCONF=$(echo $Server |awk -F'|' '{print $11}')
  post_uuid=$(echo $Server |awk -F'|' '{print $1}')
  ctx_id=$(echo "${post_uuid}")
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
  deployModel=$(echo "${ALLCONF}" |jq -r .local_deploy)
  localIP=$(echo "${ALLCONF}" |jq -r .local_hostip)

curl -X POST -L \
  http://localhost:8888/hugo/serverops.php \
  -d setupNum="ckeditorFormated" \
  -d post_uuid="${ctx_id}" \
  -d post_gitname="${git_name}" \
  -d post_themename="${theme_name}" \
  -d post_themetype="${theme_type}" \
  -d post_sitedir="" \
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
  -d post_status=done

done

