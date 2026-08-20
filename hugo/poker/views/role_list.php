<div class="container app-page" id="main">
  <meta name="csrf-token" content="<?php echo e($csrf_token); ?>">
  <div class="card card-sops">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
      <h1 class="h5 mb-0 page-title"><i class="bi bi-shield-check mr-2" aria-hidden="true"></i>角色权限</h1>
      <a class="btn btn-sm btn-outline-primary" href="role_edit.php"><i class="bi bi-plus-circle mr-1" aria-hidden="true"></i>新建角色</a>
    </div>
    <div class="card-body">
<?php if (isset($message)): ?>
      <div class="alert <?php echo !empty($error) ? 'alert-danger' : 'alert-success'; ?> py-2 small"><?php echo e($message); ?></div>
<?php endif; ?>
      <table class="table table-hover table-sm">
        <thead>
          <tr>
            <th scope="col">ID</th>
            <th scope="col">角色名</th>
            <th scope="col">描述</th>
            <th scope="col">用户数</th>
            <th scope="col">权限数</th>
            <th scope="col">操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
          <tr>
            <td><?php echo (int)$row['id']; ?></td>
            <td><strong><?php echo e($row['name']); ?></strong></td>
            <td><?php echo e($row['description']); ?></td>
            <td><?php echo (int)$row['users']; ?></td>
            <td><?php echo (int)$row['permission_count']; ?></td>
            <td>
              <a class="btn btn-sm btn-outline-primary" href="role_edit.php?eid=<?php echo (int)$row['id']; ?>"><i class="bi bi-pencil-square mr-1" aria-hidden="true"></i>编辑</a>
              <?php if (empty($row['protected'])): ?>
              <a class="btn btn-sm btn-outline-danger ml-1" href="javascript:void(0)" onclick="roleDelete(this, <?php echo (int)$row['id']; ?>, '<?php echo e($row['name']); ?>')"><i class="bi bi-trash mr-1" aria-hidden="true"></i>删除</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script>
  function roleDelete(btn, id, name) {
    sopsConfirm('确定删除角色「' + name + '」？关联的用户角色与权限配置将一并移除。', function () {
      var csrf = '';
      var meta = document.querySelector('meta[name="csrf-token"]');
      if (meta) { csrf = meta.getAttribute('content'); }
      var form = document.createElement('form');
      form.method = 'POST';
      form.action = 'role_edit.php';
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'csrf_token';
      input.value = csrf;
      form.appendChild(input);
      var i2 = document.createElement('input');
      i2.type = 'hidden';
      i2.name = 'id';
      i2.value = id;
      form.appendChild(i2);
      var i3 = document.createElement('input');
      i3.type = 'hidden';
      i3.name = 'action';
      i3.value = 'delete';
      form.appendChild(i3);
      document.body.appendChild(form);
      form.submit();
    });
  }
</script>
