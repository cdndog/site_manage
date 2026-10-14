<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <title><?php echo isset($page_title) ? e($page_title) . ' · HUGO 站点管理' : 'HUGO 站点管理'; ?></title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#f6f7f8">
  <link rel="stylesheet" href="css/bootstrap.min.css">
  <link rel="stylesheet" href="css/bootstrap-icons.css" />
  <link rel="stylesheet" href="css/bootstrap-select.min.css" />
  <link rel="stylesheet" href="css/bootstrap-table.min.css">
  <link rel="stylesheet" href="css/theme.css">
  <!-- 时间 helper 必须在 head 加载：表格 bootstrap-table 初始化/首屏渲染发生在页面中部内联脚本阶段，
       而 siteops.js 在 layout_tail 才加载，晚于渲染 → formatter 调用会抛
       ReferenceError: sopsFormatTime is not defined，表格卡在 Loading, Please wait -->
  <script>window.sopsTimeZone = <?php echo json_encode(\App\Config::displayTimezone(), JSON_UNESCAPED_SLASHES); ?>;</script>
  <script src="js/sops-time.js"></script>
  <script>
  /* 降级兜底：sops-time.js 加载失败（404/网络）时保留原值显示，
     绝不允许 formatter 抛 ReferenceError 把 bootstrap-table 卡在 Loading */
  if (typeof window.sopsFormatTime !== 'function') {
    window.sopsFormatTime = function (value) { return value === null || value === undefined ? '' : String(value); };
  }
  </script>
  <?php if (!empty($extra_head)): ?>
  <?php echo $extra_head; ?>
  <?php endif; ?>
</head>
<body>
<?php if (empty($no_shell)): ?>
<div class="sops-shell">
<?php endif; ?>
<script>
function refreshPicture(el_id) {
    var text_raw = document.getElementById('textarea_'+el_id).value.split("|");
    if (text_raw[3].length > 0) {document.getElementById('image_'+el_id).src = text_raw[3];} else {sopsToast("picture not found.", 'warning');}
}
</script>