<div class="container app-page" id="main">
  <div class="card card-sops">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
      <h1 class="h5 mb-0 page-title"><i class="bi bi-check2-circle mr-2" aria-hidden="true"></i>关键词提交确认</h1>
      <span class="badge badge-success"><i class="bi bi-check-circle mr-1" aria-hidden="true"></i>已入库 <?php echo count($records); ?> 条</span>
    </div>
    <div class="card-body">
        <div class="form-group">
            <div class="row">
                <div class="col-md-6 col-xs-12">
                <label for="post_keyword">监控关键词:</label>
                <input type="text" class="form-control form-control-sm" id="post_keyword" name="post_keyword" value="<?php echo e($records[0]['keyword'] ?? ''); ?>" readonly>
                </div>
                <div class="col-md-6 col-xs-12">
                <label for="post_gitname">发布到站点:</label>
                <input type="text" class="form-control form-control-sm" id="post_gitname" name="post_gitname" value="<?php echo e($records[0]['git_name'] ?? ''); ?>" readonly>
                </div>
            </div>
        </div>

        <div class="form-group">
          <div class="row">
            <div class="col-md-3 col-xs-12">
                <label for="post_lang">站点语言:</label>
                <input type="text" class="form-control form-control-sm" id="post_lang" name="post_lang" value="<?php echo e($records[0]['lang'] ?? ''); ?>" readonly>
            </div>
            <div class="col-md-3 col-xs-12">
                <label for="post_geo">国家地区:</label>
                <input type="text" class="form-control form-control-sm" id="post_geo" name="post_geo" value="<?php echo e($records[0]['geo'] ?? ''); ?>" readonly>
            </div>
            <div class="col-md-3 col-xs-12">
                <label for="post_pubdir">发布目录:</label>
                <input type="text" class="form-control form-control-sm" id="post_pubdir" name="post_pubdir" value="<?php echo e($records[0]['pubdir'] ?? ''); ?>" readonly>
            </div>
            <div class="col-md-3 col-xs-12">
                <label for="post_status">状态:</label>
                <input type="text" class="form-control form-control-sm" id="post_status" name="post_status" value="<?php echo e($records[0]['status'] ?? ''); ?>" readonly>
            </div>
          </div>
        </div>

        <hr>
        <h6 class="page-title"><i class="bi bi-table mr-1" aria-hidden="true"></i>该站点关键词列表</h6>
        <div id="displaytable">
        <table
            data-toggle="table"
            data-icons-prefix="bi"
            data-search="true"
            data-show-columns="true">
          <thead>
            <tr>
              <th scope="col">#id</th>
              <th scope="col">git_name</th>
              <th scope="col">keyword</th>
              <th scope="col">pubdir</th>
              <th scope="col">status</th>
              <th scope="col">lang</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($keywords as $item) :?>
            <tr>
              <th scope="row"><?php echo e($item['id']); ?></th>
              <td><?php echo e($item['git_name']); ?></td>
              <td><?php echo e($item['keyword']); ?></td>
              <td><?php echo e($item['pubdir']); ?></td>
              <td><?php echo e($item['status']); ?></td>
              <td><?php echo e($item['lang']); ?></td>
            </tr>
            <?php endforeach ?>
          </tbody>
        </table>
        </div>
        <a class="btn btn-sm btn-secondary mt-3" href="keywordops.php"><i class="bi bi-plus-circle mr-1" aria-hidden="true"></i>继续新增</a>
    </div>
  </div>
</div>