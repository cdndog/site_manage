<div class="container app-page" id="main">
  <div class="card card-sops">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
      <h1 class="h5 mb-0 page-title"><i class="bi bi-sliders mr-2" aria-hidden="true"></i>配置管理</h1>
      <form method="post" action="config_list.php" class="d-inline" id="config_import_form" onsubmit="return confirm('将用 global_config 中的字典配置覆盖数据库，确定导入？');">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
        <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download mr-1" aria-hidden="true"></i>从 global_config 导入</button>
      </form>
    </div>
    <div class="card-body">
<?php if (isset($message)): ?>
      <div class="alert <?php echo !empty($error) ? 'alert-danger' : 'alert-success'; ?> py-2 small"><?php echo e($message); ?></div>
<?php endif; ?>
      <p class="small text-muted mb-2">业务字典配置存于数据库，部署/密钥类配置保留在 global_config.php；可从配置文件一键导入覆盖。</p>
      <table class="table table-hover table-sm">
        <thead>
          <tr>
            <th scope="col">配置项</th>
            <th scope="col">说明</th>
            <th scope="col">条目数</th>
            <th scope="col">来源</th>
            <th scope="col">最近更新</th>
            <th scope="col">操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
          <tr>
            <td><strong><?php echo e($row['key']); ?></strong></td>
            <td><?php echo e($row['description']); ?></td>
            <td><?php echo (int)$row['count']; ?></td>
            <td>
              <?php if ($row['source'] === 'database'): ?>
              <span class="badge badge-success">数据库</span>
              <?php else: ?>
              <span class="badge badge-info">配置文件</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($row['updated_at'] !== ''): ?>
              <?php echo e($row['updated_at']); ?> <span class="text-muted small">by <?php echo e($row['updated_by']); ?></span>
              <?php else: ?>
              <span class="text-muted">-</span>
              <?php endif; ?>
            </td>
            <td>
              <a class="btn btn-sm btn-outline-primary" href="config_edit.php?key=<?php echo e($row['key']); ?>"><i class="bi bi-pencil-square mr-1" aria-hidden="true"></i>编辑</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="alert alert-info small mb-0 mt-3">
        <i class="bi bi-info-circle mr-1" aria-hidden="true"></i>
        字典配置（语言/国家/目录等）保存后立即生效，供各录入表单下拉使用；新增条目请保持 JSON 对象格式
        <code>{ "label": "value" }</code>。
      </div>
    </div>
  </div>
</div>
