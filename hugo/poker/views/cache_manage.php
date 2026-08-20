<div class="container app-page" id="main">
  <div class="card card-sops">
    <div class="card-header d-flex justify-between align-items-center flex-wrap">
      <h1 class="h5 mb-0 page-title"><i class="bi bi-lightning-charge mr-2" aria-hidden="true"></i>缓存管理</h1>
      <a class="btn btn-sm btn-outline-secondary" href="config_list.php"><i class="bi bi-arrow-left mr-1" aria-hidden="true"></i>返回配置管理</a>
    </div>
    <div class="card-body">
<?php if (isset($message) && (string)$message !== ''): ?>
      <div class="alert <?php echo !empty($error) ? 'alert-danger' : 'alert-success'; ?> py-2 small"><?php echo e($message); ?></div>
<?php endif; ?>

<?php
$status = isset($status) ? $status : [];
$backend = isset($status['backend']) ? $status['backend'] : 'file';
$extensionLoaded = isset($status['extension_loaded']) ? $status['extension_loaded'] : false;
$redisConnected = isset($status['redis_connected']) ? $status['redis_connected'] : false;
$redisConfig = isset($status['redis_config']) ? $status['redis_config'] : ['host'=>'','port'=>6379,'auth'=>'','db'=>0,'timeout'=>0.5];
$redisInfo = isset($status['redis_info']) ? $status['redis_info'] : null;
$fileDir = isset($status['file_dir']) ? $status['file_dir'] : '';
$fileCount = isset($status['file_count']) ? $status['file_count'] : 0;
$fileSize = isset($status['file_size']) ? $status['file_size'] : 0;
$cfgHost = isset($redisConfig['host']) ? $redisConfig['host'] : '';
$cfgPort = isset($redisConfig['port']) ? $redisConfig['port'] : 6379;
$cfgAuth = isset($redisConfig['auth']) ? $redisConfig['auth'] : '';
$cfgDb = isset($redisConfig['db']) ? $redisConfig['db'] : 0;
$cfgTimeout = isset($redisConfig['timeout']) ? $redisConfig['timeout'] : 0.5;
?>

      <h2 class="h6 mt-2 mb-3">当前状态</h2>
      <div class="row mb-4">
        <div class="col-sm-3 mb-2">
          <div class="card text-center">
            <div class="card-body py-2">
              <div class="text-muted small">缓存后端</div>
              <div class="h5 mb-0 <?php echo $backend === 'redis' ? 'text-success' : 'text-primary'; ?>"><?php echo $backend === 'redis' ? 'Redis' : '文件'; ?></div>
            </div>
          </div>
        </div>
        <div class="col-sm-3 mb-2">
          <div class="card text-center">
            <div class="card-body py-2">
              <div class="text-muted small">Redis 扩展</div>
              <div class="h5 mb-0 <?php echo $extensionLoaded ? 'text-success' : 'text-danger'; ?>"><?php echo $extensionLoaded ? '已加载' : '未安装'; ?></div>
            </div>
          </div>
        </div>
        <div class="col-sm-3 mb-2">
          <div class="card text-center">
            <div class="card-body py-2">
              <div class="text-muted small">Redis 连接</div>
              <div class="h5 mb-0 <?php echo $redisConnected ? 'text-success' : 'text-muted'; ?>"><?php echo $redisConnected ? '已连接' : '未连接'; ?></div>
            </div>
          </div>
        </div>
        <div class="col-sm-3 mb-2">
          <div class="card text-center">
            <div class="card-body py-2">
              <div class="text-muted small">文件缓存条目</div>
              <div class="h5 mb-0 text-primary"><?php echo e((string)$fileCount); ?> / <?php echo $fileSize > 0 ? e(number_format($fileSize / 1024, 1)) . 'KB' : '0'; ?></div>
            </div>
          </div>
        </div>
      </div>

<?php if ($redisConnected && is_array($redisInfo) && !isset($redisInfo['error'])): ?>
      <div class="card mb-4">
        <div class="card-header py-2">Redis 服务信息</div>
        <div class="card-body py-2 small">
          <div class="row">
            <div class="col-sm-3"><span class="text-muted">版本：</span><?php echo e((string)(isset($redisInfo['version']) ? $redisInfo['version'] : '')); ?></div>
            <div class="col-sm-3"><span class="text-muted">运行天数：</span><?php echo e((string)(isset($redisInfo['uptime_in_days']) ? $redisInfo['uptime_in_days'] : '')); ?></div>
            <div class="col-sm-3"><span class="text-muted">内存占用：</span><?php echo e((string)(isset($redisInfo['used_memory_human']) ? $redisInfo['used_memory_human'] : '')); ?></div>
            <div class="col-sm-3"><span class="text-muted">键数量：</span><?php echo e((string)(isset($redisInfo['db_size']) ? $redisInfo['db_size'] : '')); ?></div>
          </div>
        </div>
      </div>
<?php endif; ?>

      <div class="card mb-4">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
          <span><i class="bi bi-server mr-1" aria-hidden="true"></i>Redis 连接配置</span>
          <span class="small text-muted">环境变量 APP_REDIS_HOST 优先；此处配置写入 cache.config.php</span>
        </div>
        <div class="card-body">
          <form method="post" action="cache_manage.php" id="cache_config_form">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
            <input type="hidden" name="action" value="save_config">
            <div class="form-row">
              <div class="form-group col-md-6">
                <label for="redis_host">Redis Host</label>
                <input type="text" class="form-control" id="redis_host" name="redis_host" value="<?php echo e($cfgHost); ?>" placeholder="127.0.0.1（留空则使用文件缓存）">
              </div>
              <div class="form-group col-md-3">
                <label for="redis_port">端口</label>
                <input type="text" class="form-control" id="redis_port" name="redis_port" value="<?php echo e((string)$cfgPort); ?>" placeholder="6379">
              </div>
              <div class="form-group col-md-3">
                <label for="redis_db">DB</label>
                <input type="text" class="form-control" id="redis_db" name="redis_db" value="<?php echo e((string)$cfgDb); ?>" placeholder="0">
              </div>
            </div>
            <div class="form-row">
              <div class="form-group col-md-6">
                <label for="redis_auth">密码（可选）</label>
                <input type="password" class="form-control" id="redis_auth" name="redis_auth" value="<?php echo e($cfgAuth); ?>" placeholder="无密码则留空">
              </div>
              <div class="form-group col-md-3">
                <label for="redis_timeout">连接超时（秒）</label>
                <input type="text" class="form-control" id="redis_timeout" name="redis_timeout" value="<?php echo e((string)$cfgTimeout); ?>" placeholder="0.5">
              </div>
              <div class="form-group col-md-3 d-flex align-items-end">
                <button type="button" class="btn btn-sm btn-outline-info w-100" id="testConnBtn"><i class="bi bi-plug mr-1" aria-hidden="true"></i>测试连接</button>
              </div>
            </div>
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg mr-1" aria-hidden="true"></i>保存配置</button>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="card-header py-2"><i class="bi bi-eraser mr-1" aria-hidden="true"></i>缓存操作</div>
        <div class="card-body">
          <form method="post" action="cache_manage.php" id="cache_flush_form" data-sops-confirm="确认清空全部缓存？清空后下次请求会重新加载。">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
            <input type="hidden" name="action" value="flush">
            <p class="small text-muted mb-2">清空所有缓存条目（Redis flushdb + 删除文件缓存）。清空后热度查询会重新从数据库读取。</p>
            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash mr-1" aria-hidden="true"></i>清空全部缓存</button>
          </form>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
(function () {
  var btn = document.getElementById('testConnBtn');
  if (!btn) return;
  btn.addEventListener('click', function () {
    var host = document.getElementById('redis_host').value.trim();
    var port = document.getElementById('redis_port').value.trim() || '6379';
    var auth = document.getElementById('redis_auth').value;
    var db = document.getElementById('redis_db').value.trim() || '0';
    var timeout = document.getElementById('redis_timeout').value.trim() || '0.5';
    if (!host) { sopsToast('请填写 Redis Host', 'warning'); return; }
    var csrf = document.querySelector('input[name=csrf_token]').value;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-1"></span>测试中...';
    var fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('action', 'test_connection');
    fd.append('redis_host', host);
    fd.append('redis_port', port);
    fd.append('redis_auth', auth);
    fd.append('redis_db', db);
    fd.append('redis_timeout', timeout);
    fetch('cache_manage.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) {
          sopsToast('连接成功！Redis 版本 ' + (data.version || '?') + '，运行 ' + (data.uptime || '?'), 'success');
        } else {
          sopsToast('连接失败：' + (data.error || '未知错误'), 'danger');
        }
      })
      .catch(function (e) { sopsToast('请求失败：' + e.message, 'danger'); })
      .finally(function () {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-plug mr-1" aria-hidden="true"></i>测试连接';
      });
  });
})();
</script>
