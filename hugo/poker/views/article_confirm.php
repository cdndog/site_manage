<div class="container app-page" id="main">
<div class="card card-sops">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h1 class="h5 mb-0 page-title"><i class="bi bi-check-circle mr-2" aria-hidden="true"></i>文章已保存</h1>
    <span class="badge badge-success"><i class="bi bi-check2 mr-1" aria-hidden="true"></i>保存成功</span>
  </div>
  <div class="card-body">
    <div class="alert alert-success">
      <i class="bi bi-check-circle-fill mr-2" aria-hidden="true"></i>文章已写入 article 表，JSON 已保存至 <code>json/<?php echo e(isset($record['ctx_id']) ? $record['ctx_id'] : ''); ?>.json</code>
      <?php if (!empty($publish_messages)): ?>
      <br><i class="bi bi-send mr-2" aria-hidden="true"></i>发布任务已触发：<?php echo e(implode('；', array_map(function ($m) { return is_array($m) ? (isset($m['message']) ? $m['message'] : '') : (string)$m; }, $publish_messages))); ?>
      <?php endif; ?>
    </div>
    <a href="article_new.php" class="btn btn-sm btn-primary mb-3"><i class="bi bi-plus-lg mr-1" aria-hidden="true"></i>再写一篇</a>
    <a href="article_list.php" class="btn btn-sm btn-secondary mb-3"><i class="bi bi-list-ul mr-1" aria-hidden="true"></i>文章列表</a>

    <div class="form-group">
      <div class="row">
        <div class="col-md-6 col-xs-12">
          <label class="badge badge-light my-2"><i class="bi bi-link-45deg mr-1" aria-hidden="true"></i>原文链接：</label>
          <input type="text" class="form-control form-control-sm" value="<?php echo e(isset($record['url']) ? $record['url'] : ''); ?>" readonly>
        </div>
        <div class="col-md-6 col-xs-12">
          <label class="badge badge-light my-2"><i class="bi bi-type mr-1" aria-hidden="true"></i>文章标题：</label>
          <input type="text" class="form-control form-control-sm" value="<?php echo e(isset($record['title']) ? $record['title'] : ''); ?>" readonly>
        </div>
      </div>
      <div class="row">
        <div class="col-md-6 col-xs-12">
          <label class="badge badge-light my-2"><i class="bi bi-image mr-1" aria-hidden="true"></i>封面图片：</label>
          <input type="text" class="form-control form-control-sm" value="<?php echo e(isset($record['static_thumbnail']) ? $record['static_thumbnail'] : ''); ?>" readonly>
        </div>
        <div class="col-md-6 col-xs-12">
          <label class="badge badge-light my-2"><i class="bi bi-keyboard mr-1" aria-hidden="true"></i>关键词：</label>
          <input type="text" class="form-control form-control-sm" value="<?php echo e(isset($record['keyword']) ? $record['keyword'] : ''); ?>" readonly>
        </div>
      </div>
      <div class="row">
        <div class="col-md-4 col-xs-12">
          <label class="badge badge-light my-2"><i class="bi bi-translate mr-1" aria-hidden="true"></i>语言：</label>
          <input type="text" class="form-control form-control-sm" value="<?php echo e(isset($record['lang']) ? $record['lang'] : ''); ?>" readonly>
        </div>
        <div class="col-md-4 col-xs-12">
          <label class="badge badge-light my-2"><i class="bi bi-folder mr-1" aria-hidden="true"></i>保存栏目：</label>
          <input type="text" class="form-control form-control-sm" value="<?php echo e(isset($record['series']) ? $record['series'] : ''); ?>" readonly>
        </div>
        <div class="col-md-4 col-xs-12">
          <label class="badge badge-light my-2"><i class="bi bi-folder-open mr-1" aria-hidden="true"></i>保存目录：</label>
          <input type="text" class="form-control form-control-sm" value="<?php echo e(isset($record['pubdir']) ? $record['pubdir'] : ''); ?>" readonly>
        </div>
      </div>
    </div>

    <div class="form-group">
      <label class="badge badge-light my-2"><i class="bi bi-link-45deg mr-1" aria-hidden="true"></i>关联站点：</label>
      <input type="text" class="form-control form-control-sm" value="<?php echo e(isset($record['pubdomain']) ? $record['pubdomain'] : ''); ?>" readonly>
    </div>

    <div class="form-group">
      <label class="badge badge-light my-2"><i class="bi bi-database mr-1" aria-hidden="true"></i>Json：</label>
      <textarea class="form-control form-control-sm" rows="6" readonly><?php echo e(isset($json_text) ? $json_text : ''); ?></textarea>
    </div>

    <style type="text/css">
      .container img { max-width: 100%; height: auto; }
    </style>
    <div class="container" id="text_content">
      <?php echo isset($record['content']) ? $record['content'] : ''; ?>
    </div>
    <hr>
    <div class="form-group">
      <span class="badge badge-primary">publish to all site</span> -> <?php echo (isset($record['globalpublish']) && $record['globalpublish'] === 'yes') ? 'yes' : 'no'; ?>
    </div>
    <div class="form-group">
      <span class="badge badge-primary">translate to languages</span> -> <?php echo e(isset($record['translate_to_langs']) ? $record['translate_to_langs'] : ''); ?>
    </div>
  </div>
</div>
</div>