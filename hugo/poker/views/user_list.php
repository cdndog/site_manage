<div class="container app-page" id="main">
  <meta name="csrf-token" content="<?php echo e($csrf_token); ?>">
  <div class="card card-sops">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
      <h1 class="h5 mb-0 page-title"><i class="bi bi-people mr-2" aria-hidden="true"></i>用户管理</h1>
      <a class="btn btn-sm btn-outline-primary" href="user_edit.php"><i class="bi bi-plus-circle mr-1" aria-hidden="true"></i>新建用户</a>
    </div>
    <div class="card-body">
<?php if (isset($message)): ?>
      <div class="alert <?php echo !empty($error) ? 'alert-danger' : 'alert-success'; ?> py-2 small"><?php echo e($message); ?></div>
<?php endif; ?>
      <table class="table table-hover table-sm">
        <thead>
          <tr>
            <th scope="col">ID</th>
            <th scope="col">用户名</th>
            <th scope="col">显示名</th>
            <th scope="col">状态</th>
            <th scope="col">角色</th>
            <th scope="col">创建时间</th>
            <th scope="col">最近登录</th>
            <th scope="col">操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
          <tr>
            <td><?php echo (int)$row['id']; ?></td>
            <td><strong><?php echo e($row['username']); ?></strong></td>
            <td><?php echo e($row['display_name']); ?></td>
            <td><?php echo $row['status'] === 'active' ? '<span class="badge badge-success">active</span>' : '<span class="badge badge-secondary">disabled</span>'; ?></td>
            <td><?php echo e($row['roles']); ?></td>
            <td><?php echo e($row['created_at']); ?></td>
            <td><?php echo e($row['last_login_at']); ?></td>
            <td>
              <a class="btn btn-sm btn-outline-primary" href="user_edit.php?eid=<?php echo (int)$row['id']; ?>"><i class="bi bi-pencil-square mr-1" aria-hidden="true"></i>编辑</a>
              <?php if (empty($row['protected'])): ?>
              <a class="btn btn-sm btn-outline-danger ml-1" href="javascript:void(0)" onclick="userDelete(this, <?php echo (int)$row['id']; ?>, '<?php echo e($row['username']); ?>')"><i class="bi bi-trash mr-1" aria-hidden="true"></i>删除</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (isset($total) && $total > 0): ?>
      <div class="text-muted small mt-2">共 <?php echo (int)$total; ?> 个用户</div>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
  function userDelete(btn, id, username) {
    sopsConfirm('确定删除用户「' + username + '」？该操作不可恢复。', function () {
      var csrf = '';
      var meta = document.querySelector('meta[name="csrf-token"]');
      if (meta) { csrf = meta.getAttribute('content'); }
      var form = document.createElement('form');
      form.method = 'POST';
      form.action = 'user_edit.php';
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
