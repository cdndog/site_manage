<div class="container app-page" id="main">
  <div class="card card-sops">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
      <h1 class="h5 mb-0 page-title"><i class="bi bi-cookie mr-2" aria-hidden="true"></i>微信cookie配置</h1>
      <a class="btn btn-sm btn-outline-secondary" href="config_list.php"><i class="bi bi-arrow-left mr-1" aria-hidden="true"></i>返回配置管理</a>
    </div>
    <div class="card-body">
<?php if (isset($message) && (string)$message !== ''): ?>
      <div class="alert <?php echo !empty($error) ? 'alert-danger' : 'alert-success'; ?> py-2 small"><?php echo e($message); ?></div>
<?php endif; ?>
      <p class="small text-muted mb-2">
        管理文件 <code><?php echo e(basename($cookie_file)); ?></code>（与 global_config.php 同目录，独立覆盖微信抓取登录态配置）。
        保存后立即生效，供微信导入抓取使用。
      </p>
      <form method="post" action="wechat_cookie.php" id="wechat_cookie_form">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
        <div class="form-group">
          <label for="cookie">公众号登录 Cookie</label>
          <textarea class="form-control codearea" id="cookie" name="cookie" rows="4" spellcheck="false" placeholder="浏览器登录 mp.weixin.qq.com 后复制的 Cookie 头内容（留空则不携带）"><?php echo e($cookie); ?></textarea>
        </div>
        <div class="form-group">
          <label for="http_headers">http_headers（JSON 字符串数组）</label>
          <textarea class="form-control codearea" id="http_headers" name="http_headers" rows="8" spellcheck="false"><?php echo e($http_headers); ?></textarea>
          <div class="invalid-feedback" id="http_headers_hint">JSON 格式错误：请输入合法的 JSON 字符串数组，例如 ["User-Agent: CustomAgent"]；留空 [] 则使用默认浏览器请求头</div>
        </div>
        <div class="form-row">
          <div class="form-group col-md-6">
            <label for="wechat_forwarded_for">wechat_forwarded_for（可选）</label>
            <input type="text" class="form-control" id="wechat_forwarded_for" name="wechat_forwarded_for" value="<?php echo e($wechat_forwarded_for); ?>">
          </div>
          <div class="form-group col-md-6">
            <label for="proxy">proxy（可选代理）</label>
            <input type="text" class="form-control" id="proxy" name="proxy" value="<?php echo e($proxy); ?>">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group col-md-4">
            <label for="connect_timeout">connect_timeout（秒）</label>
            <input type="number" class="form-control" id="connect_timeout" name="connect_timeout" min="1" value="<?php echo (int)$connect_timeout; ?>">
          </div>
          <div class="form-group col-md-4">
            <label for="timeout">timeout（秒）</label>
            <input type="number" class="form-control" id="timeout" name="timeout" min="1" value="<?php echo (int)$timeout; ?>">
          </div>
          <div class="form-group col-md-4">
            <label for="max_retries">max_retries（重试次数）</label>
            <input type="number" class="form-control" id="max_retries" name="max_retries" min="0" value="<?php echo (int)$max_retries; ?>">
          </div>
        </div>
        <div class="d-flex align-items-center">
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle mr-1" aria-hidden="true"></i>保存配置</button>
          <span class="small text-muted ml-3">cookie 属于敏感信息，请勿在非信任环境粘贴。</span>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
(function () {
    var ta = document.getElementById('http_headers');
    var hint = document.getElementById('http_headers_hint');
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
        var ok = Array.isArray(parsed) && parsed.every(function (item) { return typeof item === 'string'; });
        if (ok) {
            ta.classList.remove('is-invalid');
        } else {
            ta.classList.add('is-invalid');
        }
        return ok;
    }
    ta.addEventListener('input', validate);
    ta.addEventListener('blur', validate);
    document.querySelector('#wechat_cookie_form').addEventListener('submit', function (e) {
        if (!validate()) {
            e.preventDefault();
            ta.focus();
        }
    });
})();
</script>