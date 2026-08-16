<div class="container app-page" id="main">
<div class="card card-sops">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
    <h1 class="h5 mb-0 page-title"><i class="bi bi-person-plus mr-2" aria-hidden="true"></i><?php echo $form['id'] > 0 ? '编辑用户' : '新建用户'; ?></h1>
    <a class="btn btn-sm btn-outline-secondary" href="users.php"><i class="bi bi-arrow-left mr-1" aria-hidden="true"></i>返回列表</a>
  </div>
  <div class="card-body">
<?php if (isset($message)): ?>
    <div class="alert <?php echo !empty($error) ? 'alert-danger' : 'alert-success'; ?> py-2 small"><?php echo e($message); ?></div>
<?php endif; ?>
    <form action="user_edit.php" method="post" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
      <input type="hidden" name="id" value="<?php echo (int)$form['id']; ?>">
      <div class="form-group">
        <label for="username">用户名 <span class="text-danger" aria-hidden="true">*</span></label>
        <input type="text" class="form-control" id="username" name="username" value="<?php echo e($form['username']); ?>" required pattern="[A-Za-z0-9_.-]{2,32}" maxlength="32" data-msg="用户名仅支持 2-32 位字母/数字/._-">
      </div>
      <div class="form-group">
        <label for="display_name">显示名</label>
        <input type="text" class="form-control" id="display_name" name="display_name" value="<?php echo e($form['display_name']); ?>" maxlength="64">
      </div>
      <div class="form-group">
        <label for="password">密码 <?php echo $form['id'] > 0 ? '' : '<span class="text-danger" aria-hidden="true">*</span>'; ?></label>
        <input type="password" class="form-control" id="password" name="password" value=""<?php echo $form['id'] > 0 ? ' placeholder="留空则不修改"' : ' required minlength="6"'; ?> autocomplete="new-password">
        <small class="form-text text-muted">至少 6 位</small>
      </div>
      <div class="form-group">
        <label for="status">状态</label>
        <select class="form-control" id="status" name="status">
          <option value="active"<?php echo $form['status'] === 'active' ? ' selected' : ''; ?>>active</option>
          <option value="disabled"<?php echo $form['status'] === 'disabled' ? ' selected' : ''; ?>>disabled</option>
        </select>
      </div>
      <div class="form-group">
        <label>角色</label>
        <?php foreach ($roles as $role): ?>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="roles[]" id="role_<?php echo (int)$role['id']; ?>" value="<?php echo (int)$role['id']; ?>"<?php echo in_array((int)$role['id'], $form['role_ids'], true) ? ' checked' : ''; ?>>
          <label class="form-check-label" for="role_<?php echo (int)$role['id']; ?>">
            <?php echo e($role['name']); ?>
            <?php if (!empty($role['description'])): ?><small class="text-muted">- <?php echo e($role['description']); ?></small><?php endif; ?>
          </label>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="d-flex justify-content-end mt-4">
        <button type="submit" class="btn btn-primary px-5"><i class="bi bi-check-circle mr-1" aria-hidden="true"></i>保存</button>
      </div>
    </form>
  </div>
</div>
</div>
<script>
  // 自定义校验提示：pattern / minlength / maxlength 违规时显示 data-msg 中文提示
  (function () {
    document.querySelectorAll('[data-msg]').forEach(function (el) {
      el.addEventListener('invalid', function () {
        if (el.validity.patternMismatch || el.validity.tooLong || el.validity.tooShort) {
          el.setCustomValidity(el.getAttribute('data-msg'));
        }
      });
      el.addEventListener('input', function () {
        el.setCustomValidity('');
      });
    });
  })();
</script>
