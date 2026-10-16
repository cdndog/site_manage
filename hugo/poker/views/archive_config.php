<?php
use App\Services\ArchiveConfigService;

$proxies = isset($proxies) && is_array($proxies) ? $proxies : [];
$accounts = isset($accounts) && is_array($accounts) ? $accounts : [];
$templates = isset($templates) && is_array($templates) ? $templates : [];
$uploads = isset($uploads) && is_array($uploads) ? $uploads : [];
$uploadDirs = isset($uploads['upload_dirs']) && is_array($uploads['upload_dirs']) ? $uploads['upload_dirs'] : [];
$uploadBase = isset($uploads['base']) ? (string)$uploads['base'] : '';
$error = isset($error) ? (string)$error : '';
$notice = isset($notice) ? (string)$notice : '';
$form = isset($form) && is_array($form) ? $form : [];
$edit = isset($edit) && is_array($edit) ? $edit : [];
$csrf = isset($csrf_token) ? (string)$csrf_token : '';
$configDir = isset($config_dir) ? (string)$config_dir : '';
$templatesDir = isset($templates_dir) ? (string)$templates_dir : '';
$uploadsBaseDir = isset($uploads_base_dir) ? (string)$uploads_base_dir : '';
$rawKinds = isset($raw_kinds) && is_array($raw_kinds) ? $raw_kinds : [];
$rawKind = isset($raw_kind) ? (string)$raw_kind : 'proxy';
$rawJson = isset($raw_json) ? (string)$raw_json : '';

$f = function ($key, $default = '') use ($form) {
    return isset($form[$key]) ? (string)$form[$key] : $default;
};

$maskToken = function ($t) {
    $t = (string)$t;
    if ($t === '') {
        return '（空）';
    }
    if (strlen($t) <= 10) {
        return str_repeat('•', 8);
    }
    return substr($t, 0, 4) . str_repeat('•', 8) . substr($t, -4);
};

$editing = function (string $section) use ($edit, $proxies, $accounts, $templates, $uploadDirs) {
    if (($edit['section'] ?? '') !== $section) {
        return null;
    }
    $id = (string)($edit['id'] ?? '');
    $list = $section === 'proxies' ? $proxies : ($section === 'accounts' ? $accounts : ($section === 'templates' ? $templates : $uploadDirs));
    foreach ($list as $it) {
        if ((string)($it['id'] ?? '') === $id) {
            return $it;
        }
    }
    return null;
};
$edProxies = $editing('proxies');
$edAccounts = $editing('accounts');
$edTemplates = $editing('templates');
$edUploads = $editing('uploads');
?>
<style>
  .archive-config .cfg-grid { display:grid; grid-template-columns:repeat(12,1fr); gap:.75rem; align-items:end; }
  .archive-config .cfg-2 { grid-column:span 4; }
  .archive-config .cfg-3 { grid-column:span 3; }
  .archive-config .cfg-4 { grid-column:span 6; }
  .archive-config .cfg-full { grid-column:span 12; }
  .archive-config label.small-label { font-size:.78rem; color:var(--sops-muted); margin-bottom:.25rem; display:block; }
  .archive-config .cfg-token { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.75rem; }
  .archive-config textarea.cfg-content { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.75rem; min-height:320px; }
  .archive-config .cfg-hint { font-size:.76rem; color:var(--sops-muted); }
  @media (max-width:768px) {
    .archive-config .cfg-2, .archive-config .cfg-3, .archive-config .cfg-4 { grid-column:span 12; }
    .archive-config .cfg-table .table { font-size:.78rem; }
  }
</style>

<div class="container app-page archive-config" id="main">
  <div class="card card-sops">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
      <h1 class="h5 mb-0 page-title"><i class="bi bi-sliders mr-2" aria-hidden="true"></i>历史档配置</h1>
      <div class="d-flex align-items-center" style="gap:6px;flex-wrap:wrap;">
        <a class="btn btn-sm btn-outline-secondary" href="archive.php"><i class="bi bi-clock-history mr-1" aria-hidden="true"></i>返回网站历史档</a>
        <a class="btn btn-sm btn-outline-secondary" href="config_list.php"><i class="bi bi-gear mr-1" aria-hidden="true"></i>配置管理</a>
      </div>
    </div>
    <div class="card-body">

<?php if ($error !== ''): ?>
      <div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-triangle mr-1" aria-hidden="true"></i><?php echo e($error); ?></div>
<?php endif; ?>
<?php if ($notice !== ''): ?>
      <div class="alert alert-success py-2 small"><i class="bi bi-check2-circle mr-1" aria-hidden="true"></i><?php echo e($notice); ?></div>
<?php endif; ?>

      <p class="cfg-hint mb-3">JSON 配置保存在 <code><?php echo e($configDir); ?></code>（<code>proxy.json / accounts.json / templates.json / uploads.json / created_repos.json</code>，与 <code>wbm/config</code> 同构）；模板正文在项目根 <code>templates/*.md</code>（<code><?php echo e($templatesDir); ?></code>），上传资源在 <code><?php echo e($uploadsBaseDir); ?></code>；均不入数据库。</p>

      <!-- 代理预设 -->
      <div class="card card-sops mb-3" id="section-proxies">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
          <h2 class="h6 mb-0"><i class="bi bi-hdd-network mr-1" aria-hidden="true"></i>代理预设</h2>
          <span class="small text-muted"><?php echo e((string)count($proxies)); ?> 条</span>
        </div>
        <div class="card-body py-2 cfg-table">
          <?php if ($proxies): ?>
          <div class="table-responsive">
            <table class="table table-hover table-sm">
              <thead><tr><th>名称</th><th>地址</th><th style="width:150px;">操作</th></tr></thead>
              <tbody>
              <?php foreach ($proxies as $p): ?>
                <tr>
                  <td><?php echo e($p['name'] ?? ''); ?> <span class="text-muted small">(<?php echo e($p['id'] ?? ''); ?>)</span></td>
                  <td class="cfg-token"><div class="text-truncate" style="max-width:340px;" title="<?php echo e($p['url'] ?? ''); ?>"><?php echo e($p['url'] ?? ''); ?></div></td>
                  <td>
                    <a class="btn btn-xs btn-sm btn-outline-secondary mr-1" href="archive_config.php?edit=proxies:<?php echo e($p['id'] ?? ''); ?>">编辑</a>
                    <form method="post" action="archive_config.php" class="d-inline" data-sops-confirm="确定删除该代理预设？">
                      <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                      <input type="hidden" name="action" value="delete-item">
                      <input type="hidden" name="section" value="proxies">
                      <input type="hidden" name="item_id" value="<?php echo e($p['id'] ?? ''); ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger">删除</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php else: ?>
          <p class="cfg-hint mb-2">暂无代理预设（默认直连；可新增 Clash / SOCKS 等代理供历史档查询使用）。</p>
          <?php endif; ?>
          <form method="post" action="archive_config.php" class="mt-2">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="action" value="save-item">
            <input type="hidden" name="section" value="proxies">
            <input type="hidden" name="item_id" value="<?php echo e($edProxies !== null ? (string)($edProxies['id'] ?? '') : $f('item_id')); ?>">
            <div class="cfg-grid">
              <div class="cfg-3">
                <label class="small-label" for="p_name">名称</label>
                <input type="text" class="form-control form-control-sm" id="p_name" name="name"
                       value="<?php echo e($edProxies !== null ? (string)($edProxies['name'] ?? '') : $f('name')); ?>" placeholder="Clash 本地（HTTP）">
              </div>
              <div class="cfg-4">
                <label class="small-label" for="p_url">代理地址</label>
                <input type="text" class="form-control form-control-sm" id="p_url" name="url"
                       value="<?php echo e($edProxies !== null ? (string)($edProxies['url'] ?? '') : $f('url')); ?>" placeholder="http://127.0.0.1:7890 或 socks5h://127.0.0.1:7890">
              </div>
              <div class="cfg-full">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2-circle mr-1" aria-hidden="true"></i><?php echo $edProxies !== null ? '保存代理' : '新增代理'; ?></button>
                <?php if ($edProxies !== null): ?><a class="btn btn-sm btn-outline-secondary ml-1" href="archive_config.php">取消编辑</a><?php endif; ?>
                <span class="cfg-hint ml-2">SOCKS 建议 <code>socks5h://</code>（DNS 走代理解析）；「测试代理」在历史档页验证连通性。</span>
              </div>
            </div>
          </form>
        </div>
      </div>

      <!-- GitHub 账号 -->
      <div class="card card-sops mb-3" id="section-accounts">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
          <h2 class="h6 mb-0"><i class="bi bi-github mr-1" aria-hidden="true"></i>GitHub 账号</h2>
          <span class="small text-muted"><?php echo e((string)count($accounts)); ?> 个</span>
        </div>
        <div class="card-body py-2 cfg-table">
          <?php if ($accounts): ?>
          <div class="table-responsive">
            <table class="table table-hover table-sm">
              <thead><tr><th>名称</th><th>用户名</th><th>Token</th><th style="width:150px;">操作</th></tr></thead>
              <tbody>
              <?php foreach ($accounts as $a): ?>
                <tr>
                  <td><?php echo e($a['name'] ?? ''); ?> <span class="text-muted small">(<?php echo e($a['id'] ?? ''); ?>)</span></td>
                  <td><?php echo e($a['username'] ?? ''); ?></td>
                  <td class="cfg-token"><?php echo e($maskToken($a['token'] ?? '')); ?></td>
                  <td>
                    <a class="btn btn-sm btn-outline-secondary mr-1" href="archive_config.php?edit=accounts:<?php echo e($a['id'] ?? ''); ?>">编辑</a>
                    <form method="post" action="archive_config.php" class="d-inline" data-sops-confirm="确定删除该账号？（不会撤销 GitHub 上已授权的 Token）">
                      <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                      <input type="hidden" name="action" value="delete-item">
                      <input type="hidden" name="section" value="accounts">
                      <input type="hidden" name="item_id" value="<?php echo e($a['id'] ?? ''); ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger">删除</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php else: ?>
          <p class="cfg-hint mb-2">暂无已保存账号；可新增 GitHub 用户名 + Token（classic <code>ghp_</code> 或 fine-grained <code>github_pat_</code>），在历史档页下拉选择自动填入。</p>
          <?php endif; ?>
          <form method="post" action="archive_config.php" class="mt-2">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="action" value="save-item">
            <input type="hidden" name="section" value="accounts">
            <input type="hidden" name="item_id" value="<?php echo e($edAccounts !== null ? (string)($edAccounts['id'] ?? '') : $f('item_id')); ?>">
            <div class="cfg-grid">
              <div class="cfg-3">
                <label class="small-label" for="a_name">名称</label>
                <input type="text" class="form-control form-control-sm" id="a_name" name="name"
                       value="<?php echo e($edAccounts !== null ? (string)($edAccounts['name'] ?? '') : $f('name')); ?>" placeholder="主账号">
              </div>
              <div class="cfg-3">
                <label class="small-label" for="a_username">GitHub 用户名</label>
                <input type="text" class="form-control form-control-sm" id="a_username" name="username" autocomplete="off"
                       value="<?php echo e($edAccounts !== null ? (string)($edAccounts['username'] ?? '') : $f('username')); ?>" placeholder="github-username">
              </div>
              <div class="cfg-4">
                <label class="small-label" for="a_token">Token</label>
                <input type="password" class="form-control form-control-sm" id="a_token" name="token" autocomplete="off"
                       value="<?php echo e($edAccounts !== null ? (string)($edAccounts['token'] ?? '') : $f('token')); ?>" placeholder="ghp_xxxx">
              </div>
              <div class="cfg-full">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2-circle mr-1" aria-hidden="true"></i><?php echo $edAccounts !== null ? '保存账号' : '新增账号'; ?></button>
                <?php if ($edAccounts !== null): ?><a class="btn btn-sm btn-outline-secondary ml-1" href="archive_config.php">取消编辑</a><?php endif; ?>
                <span class="cfg-hint ml-2">Token 仅存于本机文件，用于创建私有仓库与上传目录；需具备 repo 权限。</span>
              </div>
            </div>
          </form>
        </div>
      </div>

      <!-- README 模板 -->
      <div class="card card-sops mb-3" id="section-templates">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
          <h2 class="h6 mb-0"><i class="bi bi-file-earmark-text mr-1" aria-hidden="true"></i>README 模板</h2>
          <span class="small text-muted"><?php echo e((string)count($templates)); ?> 个（<code><?php echo e($templatesDir); ?></code>）</span>
        </div>
        <div class="card-body py-2 cfg-table">
          <?php if ($templates): ?>
          <div class="table-responsive">
            <table class="table table-hover table-sm">
              <thead><tr><th>名称</th><th>文件</th><th>默认</th><th style="width:220px;">操作</th></tr></thead>
              <tbody>
              <?php foreach ($templates as $t): ?>
                <tr>
                  <td><?php echo e($t['name'] ?? ''); ?> <span class="text-muted small">(<?php echo e($t['id'] ?? ''); ?>)</span></td>
                  <td class="cfg-token"><?php echo e($t['file'] ?? ''); ?></td>
                  <td><?php echo !empty($t['default']) ? '<span class="badge badge-success">默认</span>' : ''; ?></td>
                  <td>
                    <a class="btn btn-sm btn-outline-secondary mr-1" href="archive_config.php?edit=templates:<?php echo e($t['id'] ?? ''); ?>">编辑</a>
                    <a class="btn btn-sm btn-outline-secondary mr-1" href="archive_config.php?edit=templates:<?php echo e($t['id'] ?? ''); ?>#t_content">正文</a>
                    <form method="post" action="archive_config.php" class="d-inline" data-sops-confirm="确定删除该模板？">
                      <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                      <input type="hidden" name="action" value="delete-item">
                      <input type="hidden" name="section" value="templates">
                      <input type="hidden" name="item_id" value="<?php echo e($t['id'] ?? ''); ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger">删除</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php else: ?>
          <p class="cfg-hint mb-2">暂无模板。</p>
          <?php endif; ?>

          <form method="post" action="archive_config.php" class="mt-2">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="action" value="save-item">
            <input type="hidden" name="section" value="templates">
            <input type="hidden" name="item_id" value="<?php echo e($edTemplates !== null ? (string)($edTemplates['id'] ?? '') : $f('item_id')); ?>">
            <div class="cfg-grid">
              <div class="cfg-3">
                <label class="small-label" for="t_name">模板名称</label>
                <input type="text" class="form-control form-control-sm" id="t_name" name="name"
                       value="<?php echo e($edTemplates !== null ? (string)($edTemplates['name'] ?? '') : $f('name')); ?>" placeholder="完整站点恢复">
              </div>
              <div class="cfg-3">
                <label class="small-label" for="t_file">md 文件名</label>
                <input type="text" class="form-control form-control-sm" id="t_file" name="file"
                       value="<?php echo e($edTemplates !== null ? (string)($edTemplates['file'] ?? '') : $f('file')); ?>" placeholder="my-template.md">
              </div>
              <div class="cfg-2" style="grid-column:span 3;">
                <label class="small-label" for="t_default">默认模板</label>
                <div class="custom-control custom-checkbox mt-1">
                  <input type="checkbox" class="custom-control-input" id="t_default" name="is_default" value="1"
                         <?php echo ($edTemplates !== null ? !empty($edTemplates['default']) : $f('is_default') !== '') ? 'checked' : ''; ?>>
                  <label class="custom-control-label small" for="t_default">新建仓库时默认选中</label>
                </div>
              </div>
              <div class="cfg-full">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2-circle mr-1" aria-hidden="true"></i><?php echo $edTemplates !== null ? '保存模板' : '新增模板'; ?></button>
                <?php if ($edTemplates !== null): ?><a class="btn btn-sm btn-outline-secondary ml-1" href="archive_config.php">取消编辑</a><?php endif; ?>
                <span class="cfg-hint ml-2">正文支持占位符：<code>{{SITE_NAME}}</code> <code>{{ARCHIVE_PAGE}}</code> <code>{{ARCHIVE_DATE}}</code> <code>{{ARCHIVE_URL}}</code> <code>{{ARCHIVE_DOMAIN}}</code></span>
              </div>
            </div>
          </form>

<?php if ($edTemplates !== null): ?>
          <hr class="my-3">
          <form method="post" action="archive_config.php" class="mt-1">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="action" value="save-content">
            <input type="hidden" name="item_id" value="<?php echo e((string)($edTemplates['id'] ?? '')); ?>">
            <label class="small-label" for="t_content">正文（<?php echo e((string)($edTemplates['name'] ?? '')); ?> / <code><?php echo e((string)($edTemplates['file'] ?? '')); ?></code>）</label>
            <textarea class="form-control form-control-sm cfg-content" id="t_content" name="content" rows="16"><?php echo e(ArchiveConfigService::templateContent((string)($edTemplates['file'] ?? ''))); ?></textarea>
            <div class="mt-2">
              <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2-circle mr-1" aria-hidden="true"></i>保存正文</button>
              <a class="btn btn-sm btn-outline-secondary ml-1" href="archive_config.php">返回列表</a>
            </div>
          </form>
<?php endif; ?>
        </div>
      </div>

      <!-- 上传目录 -->
      <div class="card card-sops mb-3" id="section-uploads">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
          <h2 class="h6 mb-0"><i class="bi bi-folder-upload mr-1" aria-hidden="true"></i>上传目录</h2>
          <span class="small text-muted"><?php echo e((string)count($uploadDirs)); ?> 个</span>
        </div>
        <div class="card-body py-2 cfg-table">
          <form method="post" action="archive_config.php" class="mb-2">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="action" value="save-base">
            <div class="cfg-grid">
              <div class="cfg-4">
                <label class="small-label" for="u_base">上传根目录（相对项目根，留空 = 项目根本身）</label>
                <input type="text" class="form-control form-control-sm" id="u_base" name="base" value="<?php echo e($f('base', $uploadBase)); ?>" placeholder="upload">
                <div class="cfg-hint mt-1">当前解析根：<code><?php echo e($uploadBase !== '' ? $uploadsBaseDir . '/' . $uploadBase : $uploadsBaseDir); ?></code></div>
              </div>
              <div class="cfg-full">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2-circle mr-1" aria-hidden="true"></i>保存根目录</button>
              </div>
            </div>
          </form>
          <?php if ($uploadDirs): ?>
          <div class="table-responsive">
            <table class="table table-hover table-sm">
              <thead><tr><th>标签</th><th>路径</th><th>仓库前缀</th><th style="width:150px;">操作</th></tr></thead>
              <tbody>
              <?php foreach ($uploadDirs as $d): ?>
                <tr>
                  <td><?php echo e($d['label'] ?? ''); ?> <span class="text-muted small">(<?php echo e($d['id'] ?? ''); ?>)</span></td>
                  <td class="cfg-token"><?php echo e($d['path'] ?? ''); ?></td>
                  <td class="cfg-token"><?php echo e($d['repo_prefix'] ?? ''); ?></td>
                  <td>
                    <a class="btn btn-sm btn-outline-secondary mr-1" href="archive_config.php?edit=uploads:<?php echo e($d['id'] ?? ''); ?>">编辑</a>
                    <form method="post" action="archive_config.php" class="d-inline" data-sops-confirm="确定删除该上传目录？">
                      <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                      <input type="hidden" name="action" value="delete-item">
                      <input type="hidden" name="section" value="uploads">
                      <input type="hidden" name="item_id" value="<?php echo e($d['id'] ?? ''); ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger">删除</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php else: ?>
          <p class="cfg-hint mb-2">暂无上传目录；新增后创建仓库时会把这些本地目录递归上传到仓库根目录。</p>
          <?php endif; ?>
          <form method="post" action="archive_config.php" class="mt-2">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="action" value="save-item">
            <input type="hidden" name="section" value="uploads">
            <input type="hidden" name="item_id" value="<?php echo e($edUploads !== null ? (string)($edUploads['id'] ?? '') : $f('item_id')); ?>">
            <div class="cfg-grid">
              <div class="cfg-3">
                <label class="small-label" for="u_label">标签</label>
                <input type="text" class="form-control form-control-sm" id="u_label" name="name"
                       value="<?php echo e($edUploads !== null ? (string)($edUploads['label'] ?? '') : $f('name')); ?>" placeholder="脚本目录">
              </div>
              <div class="cfg-3">
                <label class="small-label" for="u_path">路径</label>
                <input type="text" class="form-control form-control-sm" id="u_path" name="path"
                       value="<?php echo e($edUploads !== null ? (string)($edUploads['path'] ?? '') : $f('path')); ?>" placeholder="scripts">
              </div>
              <div class="cfg-3">
                <label class="small-label" for="u_prefix">仓库前缀</label>
                <input type="text" class="form-control form-control-sm" id="u_prefix" name="repo_prefix"
                       value="<?php echo e($edUploads !== null ? (string)($edUploads['repo_prefix'] ?? '') : $f('repo_prefix')); ?>" placeholder="scripts">
              </div>
              <div class="cfg-full">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2-circle mr-1" aria-hidden="true"></i><?php echo $edUploads !== null ? '保存目录' : '新增目录'; ?></button>
                <?php if ($edUploads !== null): ?><a class="btn btn-sm btn-outline-secondary ml-1" href="archive_config.php">取消编辑</a><?php endif; ?>
                <span class="cfg-hint ml-2">路径相对根目录；<code>..</code> 不允许。仓库前缀为空时目录文件上传到仓库根。</span>
              </div>
            </div>
          </form>
        </div>
      </div>

      <!-- 原始 JSON 编辑 -->
      <div class="card card-sops mb-0" id="section-raw">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
          <h2 class="h6 mb-0"><i class="bi bi-braces mr-1" aria-hidden="true"></i>原始 JSON 配置</h2>
          <span class="small text-muted">直接粘贴 <code>wbm/config</code> 同款 JSON 整文件保存</span>
        </div>
        <div class="card-body py-2">
          <form method="get" action="archive_config.php" class="mb-2">
            <div class="cfg-grid">
              <div class="cfg-3">
                <label class="small-label" for="raw_kind">配置文件</label>
                <select class="form-control form-control-sm" id="raw_kind" name="raw" onchange="this.form.submit()">
<?php foreach ($rawKinds as $k => $file): ?>
                  <option value="<?php echo e($k); ?>" <?php echo $k === $rawKind ? 'selected' : ''; ?>><?php echo e($file); ?></option>
<?php endforeach; ?>
                </select>
              </div>
              <div class="cfg-full">
                <span class="cfg-hint">切换文件后下方显示该 JSON 当前内容；保存时整份覆盖写入 <code><?php echo e($configDir); ?></code>（非法 JSON / 缺顶层键会被拒绝）。</span>
              </div>
            </div>
          </form>
          <form method="post" action="archive_config.php">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="action" value="save-json">
            <input type="hidden" name="kind" value="<?php echo e($rawKind); ?>">
            <label class="small-label" for="raw_json"><?php echo e($rawKinds[$rawKind] ?? ($rawKind . '.json')); ?> 内容</label>
            <textarea class="form-control form-control-sm cfg-content" id="raw_json" name="json" rows="14" spellcheck="false"><?php echo e($rawJson); ?></textarea>
            <div class="mt-2">
              <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2-circle mr-1" aria-hidden="true"></i>保存 JSON</button>
              <span class="cfg-hint ml-2">顶层键要求：<code><?php echo e(\App\Services\ArchiveConfigService::REQUIRED_KEY[$rawKind] ?? '-'); ?></code>；这是整文件覆盖保存，粘贴时请带上原有业务键（如 <code>api_token</code>）。</span>
            </div>
          </form>
        </div>
      </div>

    </div>
  </div>
</div>
