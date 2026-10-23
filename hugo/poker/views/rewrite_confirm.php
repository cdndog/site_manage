<div class="container app-page" id="main">
  <div class="card card-sops">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
      <h1 class="h5 mb-0 page-title"><i class="bi bi-check2-circle mr-2" aria-hidden="true"></i>改写数据保存成功</h1>
      <span class="badge badge-success"><i class="bi bi-check-circle mr-1" aria-hidden="true"></i>已保存</span>
    </div>
    <div class="card-body">
        <div class="form-group">
            <div class="row">
                <div class="col-md-6 col-xs-12">
                <label for="post_keyword">改写关键词:</label>
                <input type="text" class="form-control form-control-sm" id="post_keyword" value="<?php echo e($record['keyword']); ?>" readonly>
                </div>
                <div class="col-md-6 col-xs-12">
                <label for="post_gitname">发布到站点:</label>
                <input type="text" class="form-control form-control-sm" id="post_gitname" value="<?php echo e($record['git_name']); ?>" readonly>
                </div>
            </div>
        </div>

        <div class="form-group">
          <div class="row">
            <div class="col-md-3 col-xs-12">
                <label for="post_domain">发布到域名:</label>
                <input type="text" class="form-control form-control-sm" id="post_domain" value="<?php echo e($record['domain']); ?>" readonly>
            </div>
            <div class="col-md-3 col-xs-12">
                <label for="post_lang">站点语言:</label>
                <input type="text" class="form-control form-control-sm" id="post_lang" value="<?php echo e($record['lang']); ?>" readonly>
            </div>
            <div class="col-md-3 col-xs-12">
                <label for="post_geo">国家地区:</label>
                <input type="text" class="form-control form-control-sm" id="post_geo" value="<?php echo e($record['geo']); ?>" readonly>
            </div>
            <div class="col-md-3 col-xs-12">
                <label for="post_pubdir">发布目录:</label>
                <input type="text" class="form-control form-control-sm" id="post_pubdir" value="<?php echo e($record['pubdir']); ?>" readonly>
            </div>
          </div>
        </div>

        <div class="form-group">
          <div class="row">
            <div class="col-md-3 col-xs-12">
                <label for="post_status">状态:</label>
                <input type="text" class="form-control form-control-sm" id="post_status" value="<?php echo e($record['status']); ?>" readonly>
            </div>
            <div class="col-md-3 col-xs-12">
                <label for="post_lasttask">上次任务:</label>
                <input type="text" class="form-control form-control-sm" id="post_lasttask" value="<?php echo e($record['lasttask']); ?>" readonly>
            </div>
            <div class="col-md-6 col-xs-12">
                <label for="rewrite_file">数据位置:</label>
                <input type="text" class="form-control form-control-sm" id="rewrite_file" value="<?php echo e($file_path); ?>" readonly>
            </div>
          </div>
        </div>

        <hr>
        <div class="d-flex justify-content-between flex-wrap" style="gap:8px;">
            <a class="btn btn-sm btn-secondary" href="rewriteops.php"><i class="bi bi-plus-circle mr-1" aria-hidden="true"></i>继续新增</a>
            <a class="btn btn-sm btn-outline-primary" href="rewriteops.php?eid=<?php echo e(urlencode($record['ctx_id'])); ?>"><i class="bi bi-pencil-square mr-1" aria-hidden="true"></i>编辑该条</a>
        </div>
    </div>
  </div>
</div>
