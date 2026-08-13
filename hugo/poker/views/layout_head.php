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
</head>
<body>
<?php if (empty($no_shell)): ?>
<div class="sops-shell">
<?php endif; ?>
<script>
function refreshPicture(el_id) {
    var text_raw = document.getElementById('textarea_'+el_id).value.split("|");
    if (text_raw[3].length > 0) {document.getElementById('image_'+el_id).src = text_raw[3];} else {alert("picture not found.");}
}
</script>