<div class="container app-page" id="main">
  <div class="card card-sops">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
      <h1 class="h5 mb-0 page-title"><i class="bi bi-sliders mr-2" aria-hidden="true"></i>配置编辑</h1>
      <a class="btn btn-sm btn-outline-secondary" href="config_list.php"><i class="bi bi-arrow-left mr-1" aria-hidden="true"></i>返回列表</a>
    </div>
    <div class="card-body">
<?php if (isset($message)): ?>
      <div class="alert <?php echo !empty($error) ? 'alert-danger' : 'alert-success'; ?> py-2 small"><?php echo e($message); ?></div>
<?php endif; ?>
      <h6 class="sops-fieldset-title"><?php echo e($key); ?></h6>
      <p class="small text-muted"><?php echo e($description); ?></p>
      <form method="post" action="config_edit.php" id="config_edit_form">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
        <input type="hidden" name="key" value="<?php echo e($key); ?>">
        <div class="form-group">
          <label for="config_value">配置内容（JSON 对象，键值对格式）</label>
          <textarea class="form-control codearea" id="config_value" name="config_value" rows="14" spellcheck="false"><?php echo e($json); ?></textarea>
          <div class="invalid-feedback" id="config_value_hint">JSON 格式错误：请输入合法的 JSON 对象，例如 {"key": "value"}</div>
        </div>
        <div class="d-flex align-items-center">
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle mr-1" aria-hidden="true"></i>保存</button>
          <span class="small text-muted ml-3">
            当前 <?php echo (int)$count; ?> 条
            <?php if ($updated_at !== ''): ?>
            · 最近更新 <?php echo e($updated_at); ?> by <?php echo e($updated_by); ?>
            <?php endif; ?>
          </span>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
(function () {
    var ta = document.getElementById('config_value');
    var hint = document.getElementById('config_value_hint');
    function validate() {
        var value = ta.value.trim();
        if (value === '') {
            ta.classList.remove('is-invalid');
            return true;
        }
        var parsed = null;
        try {
            parsed = JSON.parse(value);
        } catch (e) {
            parsed = null;
        }
        var ok = parsed !== null && typeof parsed === 'object';
        if (ok) {
            ta.classList.remove('is-invalid');
        } else {
            ta.classList.add('is-invalid');
        }
        return ok;
    }
    ta.addEventListener('input', validate);
    ta.addEventListener('blur', validate);
    document.querySelector('#config_edit_form').addEventListener('submit', function (e) {
        if (!validate()) {
            e.preventDefault();
            ta.focus();
        }
    });
})();
</script>
