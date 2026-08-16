<div class="container app-page" id="main">
<div class="card card-sops">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
    <h1 class="h5 mb-0 page-title"><i class="bi bi-shield-lock mr-2" aria-hidden="true"></i><?php echo $form['id'] > 0 ? '编辑角色' : '新建角色'; ?></h1>
    <a class="btn btn-sm btn-outline-secondary" href="roles.php"><i class="bi bi-arrow-left mr-1" aria-hidden="true"></i>返回列表</a>
  </div>
  <div class="card-body">
<?php if (isset($message)): ?>
    <div class="alert <?php echo !empty($error) ? 'alert-danger' : 'alert-success'; ?> py-2 small"><?php echo e($message); ?></div>
<?php endif; ?>
    <form action="role_edit.php" method="post">
      <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
      <input type="hidden" name="id" value="<?php echo (int)$form['id']; ?>">
      <div class="form-group">
        <label for="name">角色名 <span class="text-danger" aria-hidden="true">*</span></label>
        <input type="text" class="form-control" id="name" name="name" value="<?php echo e($form['name']); ?>" required pattern="[A-Za-z0-9_-]{2,32}" maxlength="32" data-msg="角色名仅支持 2-32 位字母/数字/_-">
      </div>
      <div class="form-group">
        <label for="description">描述</label>
        <input type="text" class="form-control" id="description" name="description" value="<?php echo e($form['description']); ?>" maxlength="200">
      </div>
      <div class="form-group">
        <label>权限</label>
        <div class="row">
          <?php foreach ($permissions as $code => $meta): ?>
          <?php $permId = isset($permission_ids_by_code[$code]) ? (int)$permission_ids_by_code[$code] : 0; ?>
          <div class="col-md-6 col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="permissions[]" id="perm_<?php echo e($code); ?>" value="<?php echo $permId; ?>"<?php echo in_array($permId, $form['permission_ids'], true) ? ' checked' : ''; ?>>
              <label class="form-check-label" for="perm_<?php echo e($code); ?>">
                <?php echo e($meta['name']); ?>
                <small class="text-muted">- <?php echo e(isset($meta['description']) ? $meta['description'] : ''); ?></small>
              </label>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="d-flex justify-content-end mt-4">
        <button type="submit" class="btn btn-primary px-5"><i class="bi bi-check-circle mr-1" aria-hidden="true"></i>保存</button>
      </div>
    </form>
  </div>
</div>
</div>
<script>
  // 自定义校验提示：pattern / maxlength 违规时显示 data-msg 中文提示
  (function () {
    document.querySelectorAll('[data-msg]').forEach(function (el) {
      el.addEventListener('invalid', function () {
        if (el.validity.patternMismatch || el.validity.tooLong) {
          el.setCustomValidity(el.getAttribute('data-msg'));
        }
      });
      el.addEventListener('input', function () {
        el.setCustomValidity('');
      });
    });
  })();
</script>
