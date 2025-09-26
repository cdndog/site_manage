#!/bin/bash
# */5 * * * * flock -xn /tmp/keyword_monitor_sync.lock -c 'cd /cygdrive/c/apps/reddit/hugo && bash keyword_monitor_sync.sh > keyword_monitor_sync.log 2>&1 &'
date

echo "--------------------"
echo " upload keyword_monitor_list.txt"
echo "--------------------"

rclone copyto keyword_monitor_list.txt wptclips:/scripts/autobot/backlink/keyword_monitor_list.txt -P
