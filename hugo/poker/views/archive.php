<?php
use App\Services\GitHubService;
use App\Services\WaybackService;

$proxies = isset($proxies) && is_array($proxies) ? $proxies : [];
$accounts = isset($accounts) && is_array($accounts) ? $accounts : [];
$templates = isset($templates) && is_array($templates) ? $templates : [];
$repos = isset($repos) && is_array($repos) ? $repos : [];
$error = isset($error) ? (string)$error : '';
$notice = isset($notice) ? (string)$notice : '';
$repoCreated = isset($repo_created) ? $repo_created : null;
$proxyTest = isset($proxy_test) ? $proxy_test : null;
$form = isset($form) && is_array($form) ? $form : [];
$baseline = isset($baseline) ? $baseline : null;
$canManage = !empty($can_manage);
$csrf = isset($csrf_token) ? (string)$csrf_token : '';

$f = function ($key, $default = '') use ($form) {
    return isset($form[$key]) ? (string)$form[$key] : $default;
};

$selPreset = trim($f('proxy_preset'));
if ($selPreset === '' && $proxies) {
    $selPreset = (string)($proxies[0]['id'] ?? '');
}
$manualShow = ($selPreset === '__manual__');
$selTemplate = $f('template_id');
if ($selTemplate === '' && $templates) {
    foreach ($templates as $tOpt) {
        if (!empty($tOpt['default'])) {
            $selTemplate = (string)$tOpt['id'];
            break;
        }
    }
    if ($selTemplate === '' && isset($templates[0])) {
        $selTemplate = (string)$templates[0]['id'];
    }
}
$baselineTs = $baseline !== null ? (string)($baseline['ts'] ?? '') : $f('archive_ts');
$baselineUrl = $baseline !== null ? (string)($baseline['url'] ?? '') : $f('archive_url');
$baselineDomain = $baseline !== null ? (string)($baseline['domain'] ?? '') : $f('archive_domain');
$baselineTitle = $baseline !== null ? (string)($baseline['title'] ?? '') : $f('site_name');
$repoNameDefault = $f('repo_name');
if ($repoNameDefault === '' && $baselineUrl !== '') {
    $repoNameDefault = GitHubService::sanitizeRepoName(GitHubService::domainFromArchiveUrl($baselineUrl));
}
?>
<style>
  .archive-page .step-num { display:inline-block; background:var(--sops-indigo); color:#fff; border-radius:999px; width:22px; height:22px; text-align:center; line-height:22px; font-size:12px; margin-right:8px; }
  .archive-page .archive-form { display:grid; grid-template-columns:repeat(12,1fr); gap:.75rem; align-items:end; }
  .archive-page .f-domain { grid-column:span 4; }
  .archive-page .f-proxy { grid-column:span 3; }
  .archive-page .f-actions { grid-column:span 5; display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; }
  .archive-page label.small-label { font-size:.78rem; color:var(--sops-muted); margin-bottom:.25rem; display:block; }
  .archive-page .archive-hint { font-size:.76rem; color:var(--sops-muted); }
  .archive-page .archive-table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
  .archive-page .archive-table-wrap .table { margin-bottom:0; }
  .archive-page .archive-cell-url { max-width:340px; }
  .archive-page .archive-cell-title { max-width:220px; }
  .archive-page .archive-cell-time { white-space:nowrap; }
  .archive-page .baseline-box { background:rgba(111,106,248,.06); border:1px solid var(--sops-border); border-radius:var(--sops-radius-lg,10px); padding:.75rem .9rem; font-size:.85rem; }
  .archive-page .baseline-box b { color:var(--sops-indigo); }
  .archive-page .repo-preview { border:1px solid var(--sops-border); border-radius:var(--sops-radius-lg,10px); margin-top:.9rem; overflow:hidden; }
  .archive-page .repo-preview h3 { margin:0; padding:.5rem .9rem; background:#f3f4f6; font-size:.8rem; font-weight:600; }
  .archive-page .repo-preview .purl { padding:.6rem .9rem; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.78rem; word-break:break-all; }
  .archive-page .repo-preview pre { margin:0; padding:.9rem; overflow:auto; max-height:420px; background:#0f172a; color:#e2e8f0; font-size:.72rem; line-height:1.5; }
  .archive-page #token.valid { border:2px solid var(--sops-accent); outline:none; }
  .archive-page #token.invalid { border:2px solid #dc3545; outline:none; }
  .archive-page .token-badge { font-size:.72rem; }
  @media (max-width:768px) {
    .archive-page .f-domain, .archive-page .f-proxy, .archive-page .f-actions { grid-column:span 12; }
    .archive-page .archive-cell-url { max-width:200px; }
    .archive-page .archive-cell-title { max-width:140px; }
    .archive-page .archive-table-wrap .table { font-size:.78rem; }
  }
</style>

<div class="container app-page archive-page" id="main">
  <div class="card card-sops">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
      <h1 class="h5 mb-0 page-title"><i class="bi bi-clock-history mr-2" aria-hidden="true"></i>网站历史档</h1>
      <div class="d-flex align-items-center" style="gap:6px;flex-wrap:wrap;">
        <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="<?php echo e(WaybackService::calendarUrl($baselineDomain !== '' ? $baselineDomain : $f('domain'))); ?>"><i class="bi bi-calendar3 mr-1" aria-hidden="true"></i>时光机时间线</a>
        <?php if ($canManage): ?>
        <a class="btn btn-sm btn-outline-secondary" href="archive_config.php"><i class="bi bi-gear mr-1" aria-hidden="true"></i>历史档配置</a>
        <?php endif; ?>
        <a class="btn btn-sm btn-outline-secondary" href="seo_report.php?reporttype=sitelist"><i class="bi bi-globe mr-1" aria-hidden="true"></i>站点列表</a>
      </div>
    </div>
    <div class="card-body">

<?php if ($error !== ''): ?>
      <div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-triangle mr-1" aria-hidden="true"></i><?php echo e($error); ?></div>
<?php endif; ?>
<?php if ($notice !== ''): ?>
      <div class="alert alert-warning py-2 small"><i class="bi bi-info-circle mr-1" aria-hidden="true"></i><?php echo e($notice); ?></div>
<?php endif; ?>
<?php if (is_array($repoCreated)): ?>
      <div class="alert alert-success py-2 small">
        仓库创建成功：<a href="<?php echo e($repoCreated['html'] ?? '#'); ?>" target="_blank" rel="noopener"><?php echo e($repoCreated['repo'] ?? ''); ?></a>
        （README 已写入：<a href="<?php echo e($repoCreated['readme'] ?? '#'); ?>" target="_blank" rel="noopener">查看</a>）
        <?php if (!empty($repoCreated['upload'])): ?>
        <br>目录上传：<?php echo e($repoCreated['upload']); ?>
        <?php endif; ?>
      </div>
<?php endif; ?>

      <!-- 1. 查询 Archive 历史名称 -->
      <div class="card card-sops mb-3">
        <div class="card-header py-2"><h2 class="h6 mb-0"><span class="step-num">1</span>查询 Archive 历史名称</h2></div>
        <div class="card-body py-2">
          <form method="post" id="form-check" class="archive-form">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
            <div class="f-domain">
              <label class="small-label" for="domain">域名（如 www.sdwenkong.com）</label>
              <input type="text" class="form-control form-control-sm" id="domain" name="domain" value="<?php echo e($f('domain')); ?>" placeholder="www.example.com" required>
            </div>
            <div class="f-proxy">
              <label class="small-label" for="proxy_preset">代理</label>
              <select class="form-control form-control-sm" id="proxy_preset" name="proxy_preset" onchange="onProxyPreset(this)">
                <option value="">-- 自动（直连 / 环境代理回退）--</option>
                <?php foreach ($proxies as $p): ?>
                <?php $pv = (string)($p['id'] ?? $p['url'] ?? ''); ?>
                <option value="<?php echo e($pv); ?>"<?php echo $selPreset === $pv ? ' selected' : ''; ?>><?php echo e($p['name'] ?? $p['id'] ?? $p['url'] ?? ''); ?></option>
                <?php endforeach; ?>
                <option value="__manual__"<?php echo $selPreset === '__manual__' ? ' selected' : ''; ?>>手工录入…</option>
              </select>
              <input type="text" class="form-control form-control-sm mt-2" id="proxy_addr" name="proxy_addr"
                     value="<?php echo e($f('proxy_addr')); ?>" placeholder="http://ip:port 或 socks5h://ip:port"
                     style="<?php echo $manualShow ? '' : 'display:none;' ?>" title="手工录入代理地址">
            </div>
            <div class="f-actions">
              <div class="custom-control custom-checkbox mr-2">
                <input type="checkbox" class="custom-control-input" id="homepage_only" name="homepage_only" value="1"<?php echo $f('homepage_only') !== '' ? ' checked' : ''; ?>>
                <label class="custom-control-label small" for="homepage_only">每年一个首页</label>
              </div>
              <button type="submit" class="btn btn-sm btn-primary" id="btnCheck" name="action" value="check"><i class="bi bi-search mr-1" aria-hidden="true"></i>查询历史名称</button>
              <button type="submit" class="btn btn-sm btn-outline-secondary" name="action" value="proxy_test"><i class="bi bi-plug mr-1" aria-hidden="true"></i>测试代理</button>
            </div>
          </form>
          <p class="archive-hint mt-2 mb-0">代理可从下拉选择（配置于「历史档配置」）或手工录入；"自动"模式直连失败时回退环境代理。SOCKS 建议用 <code>socks5h://</code>（DNS 走代理解析）。查询会抓取全部 HTTP 200 首页快照并并发提取标题；勾选「每年一个首页」则每年只取第一条（取消勾选=全部首页快照）。</p>

<?php if (is_array($proxyTest)): ?>
          <div class="alert <?php echo !empty($proxyTest['ok']) ? 'alert-success' : 'alert-danger'; ?> py-2 small mt-2 mb-0"><?php echo e($proxyTest['msg'] ?? ''); ?></div>
<?php endif; ?>

          <div id="archiveResults" class="mt-3"></div>
        </div>
      </div>

      <!-- 2. 归档基线 -->
      <div class="card card-sops mb-3">
        <div class="card-header py-2"><h2 class="h6 mb-0"><span class="step-num">2</span>归档基线</h2></div>
        <div class="card-body py-2">
          <div id="baselineBox">
            <div class="baseline-box" id="baselineContent" style="<?php echo ($baselineTs !== '' && $baselineUrl !== '') ? '' : 'display:none;' ?>">
              <div>网站名：<b id="blSiteName"><?php echo e($baselineTitle); ?></b></div>
              <div>归档链接：<span class="mono" id="blLink"><?php echo e(WaybackService::replayUrl($baselineTs, $baselineUrl)); ?></span></div>
              <div>归档基准日期：<span class="mono" id="blDate"><?php echo e($baselineTs); ?></span></div>
            </div>
            <p class="archive-hint mb-0" id="baselineHint" style="<?php echo ($baselineTs !== '' && $baselineUrl !== '') ? 'display:none;' : '' ?>">在步骤 1 的结果中选择一条记录作为归档基线。</p>
          </div>
        </div>
      </div>

      <!-- 3. 创建 GitHub 私有仓库 -->
      <div class="card card-sops mb-3">
        <div class="card-header py-2"><h2 class="h6 mb-0"><span class="step-num">3</span>创建 GitHub 私有仓库</h2></div>
        <div class="card-body py-2">
          <form method="post" id="form-create">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="archive_ts" id="archive_ts" value="<?php echo e($baselineTs); ?>">
            <input type="hidden" name="archive_url" id="archive_url" value="<?php echo e($baselineUrl); ?>">
            <input type="hidden" name="archive_domain" id="archive_domain" value="<?php echo e($baselineDomain); ?>">

            <div class="form-row">
              <div class="form-group col-md-6">
                <label class="small-label" for="account_id">GitHub 账号（可选）</label>
                <select class="form-control form-control-sm" id="account_id" name="account_id" onchange="fillAccount(this)">
                  <option value="">-- 选择账号（自动填入用户名与 Token）--</option>
                  <?php foreach ($accounts as $acc): ?>
                  <option value="<?php echo e($acc['id'] ?? ''); ?>"
                          data-username="<?php echo e($acc['username'] ?? ''); ?>"
                          data-token="<?php echo e($acc['token'] ?? ''); ?>"
                          <?php echo $f('account_id') === (string)($acc['id'] ?? '') ? 'selected' : ''; ?>>
                    <?php echo e($acc['name'] ?? $acc['id'] ?? ''); ?><?php echo (string)($acc['token'] ?? '') === '' || strpos((string)($acc['token'] ?? ''), 'PASTE_') === 0 ? '（未配置 Token）' : ''; ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group col-md-3">
                <label class="small-label" for="username">GitHub 用户名</label>
                <input type="text" class="form-control form-control-sm" id="username" name="username" value="<?php echo e($f('username')); ?>" placeholder="github-username" autocomplete="off">
              </div>
              <div class="form-group col-md-3">
                <label class="small-label" for="token">GitHub Token <span id="tokenBadge" class="token-badge"></span></label>
                <input type="password" class="form-control form-control-sm" id="token" name="token" value="<?php echo e($f('token')); ?>" placeholder="ghp_xxx" autocomplete="off">
              </div>
            </div>
            <div class="form-row">
              <div class="form-group col-md-4">
                <label class="small-label" for="repo_name">仓库名称（私有）</label>
                <input type="text" class="form-control form-control-sm" id="repo_name" name="repo_name" value="<?php echo e($repoNameDefault); ?>" placeholder="my-site-restore" required>
              </div>
              <div class="form-group col-md-4">
                <label class="small-label" for="site_name">网站标题（可手工修改）</label>
                <input type="text" class="form-control form-control-sm" id="site_name" name="site_name" value="<?php echo e($f('site_name', $baselineTitle)); ?>" placeholder="网站标题">
              </div>
              <div class="form-group col-md-4">
                <label class="small-label" for="template_id">README 模板</label>
                <select class="form-control form-control-sm" id="template_id" name="template_id">
                  <?php foreach ($templates as $t): ?>
                  <option value="<?php echo e($t['id'] ?? ''); ?>"<?php echo $selTemplate === (string)($t['id'] ?? '') ? ' selected' : ''; ?>><?php echo e($t['name'] ?? $t['id'] ?? ''); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="d-flex gap-2 mt-1" style="gap:.6rem;">
              <button type="button" class="btn btn-sm btn-outline-secondary" id="btnPreview"><i class="bi bi-eye mr-1" aria-hidden="true"></i>生成预览</button>
              <button type="submit" class="btn btn-sm btn-success" name="action" value="create" id="btnCreate" onclick="return checkTokenBeforeCreate();"><i class="bi bi-github mr-1" aria-hidden="true"></i>创建私有仓库 + 写入 README</button>
            </div>
          </form>
          <div id="previewWrap"></div>
        </div>
      </div>

      <!-- 4. 已创建仓库 -->
      <?php if ($repos): ?>
      <div class="card card-sops mb-0">
        <div class="card-header py-2"><h2 class="h6 mb-0"><span class="step-num">4</span>已创建仓库（<?php echo e((string)count($repos)); ?>）</h2></div>
        <div class="card-body py-2">
          <div class="archive-table-wrap">
            <table class="table table-hover table-sm mb-0">
              <thead><tr><th>仓库</th><th>网站标题</th><th>归档日期</th><th>创建人</th><th>创建时间</th></tr></thead>
              <tbody>
              <?php foreach ($repos as $r): ?>
                <tr>
                  <td class="mono"><a href="<?php echo e($r['html_url'] ?? '#'); ?>" target="_blank" rel="noopener"><?php echo e(($r['owner'] ?? '') . '/' . ($r['repo'] ?? '')); ?></a></td>
                  <td><div class="text-truncate archive-cell-title"><?php echo e($r['site_name'] ?? ''); ?></div></td>
                  <td class="mono"><?php echo e($r['archive_ts'] ?? ''); ?></td>
                  <td><?php echo e($r['created_by'] ?? ''); ?></td>
                  <td class="mono"><?php echo e($r['created_at'] ?? ''); ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<!-- 查询进度 Modal -->
<div id="progressModal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-body text-center py-4">
        <div class="spinner-border text-primary mb-3" role="status"><span class="sr-only">加载中…</span></div>
        <h5 class="h6 mb-1">正在查询归档历史…</h5>
        <p class="text-muted small mb-0">正在从 Archive 查询并解析历史快照，请稍候…</p>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var csrf = <?php echo json_encode($csrf); ?>;
  var baseline = {
    ts: <?php echo json_encode($baselineTs); ?>,
    url: <?php echo json_encode($baselineUrl); ?>,
    title: <?php echo json_encode($baselineTitle); ?>,
    domain: <?php echo json_encode($baselineDomain); ?>
  };

  function $(id) { return document.getElementById(id); }

  window.onProxyPreset = function (sel) {
    var manual = $('proxy_addr');
    if (!manual) return;
    manual.style.display = sel.value === '__manual__' ? '' : 'none';
    if (sel.value === '__manual__') manual.focus();
  };

  function setBaseline(ts, url, title, domain) {
    baseline = { ts: ts, url: url, title: title, domain: domain };
    $('archive_ts').value = ts;
    $('archive_url').value = url;
    $('archive_domain').value = domain;
    var box = $('baselineContent'), hint = $('baselineHint');
    if (box) {
      box.style.display = '';
      $('blSiteName').textContent = title || domain || '';
      $('blDate').textContent = ts;
      var link = $('blLink');
      link.textContent = 'https://web.archive.org/web/' + ts + '/' + url;
      link.setAttribute('href', link.textContent);
    }
    if (hint) hint.style.display = 'none';
    if ($('site_name') && !$('site_name').value) $('site_name').value = title || '';
    if ($('repo_name') && !$('repo_name').value) {
      var host = url.replace(/^[a-z][a-z0-9+.-]*:\/\//i, '').split(/[/?#]/)[0].replace(/:\d+$/, '');
      $('repo_name').value = host.toLowerCase().replace(/[^a-z0-9._-]+/g, '-').replace(/^[-.]+|[-.]+$/g, '');
    }
  }
  window.__archiveSetBaseline = setBaseline;

  // 步骤1：AJAX 查询
  var formCheck = $('form-check');
  if (formCheck) {
    formCheck.addEventListener('submit', function (e) {
      var btn = e.submitter || document.activeElement;
      if (btn && btn.value === 'proxy_test') return; // 测试代理：原生整页提交
      e.preventDefault();
      var domain = ($('domain').value || '').trim();
      if (domain === '') return;
      var pm = $('progressModal');
      if (window.jQuery && pm) { window.jQuery(pm).modal('show'); } else if (pm) { pm.style.display = 'block'; }
      var data = new FormData(formCheck);
      data.set('action', 'check');
      data.set('csrf_token', csrf);
      fetch('archive.php', { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (window.jQuery && pm) { window.jQuery(pm).modal('hide'); } else if (pm) { pm.style.display = 'none'; }
          var box = $('archiveResults');
          if (!box) return;
          if (j.ok) {
            box.innerHTML = j.html;
            var radios = box.querySelectorAll('.archive-baseline-radio');
            for (var i = 0; i < radios.length; i++) {
              radios[i].addEventListener('change', function () {
                window.__pendingBaseline = {
                  ts: this.getAttribute('data-ts'), url: this.getAttribute('data-url'),
                  title: this.getAttribute('data-title'), domain: this.getAttribute('data-domain')
                };
              });
            }
            var btnSel = $('btnSelectBaseline');
            if (btnSel) {
              btnSel.addEventListener('click', function () {
                var b = window.__pendingBaseline;
                if (!b) { alert('请先选择一条记录'); return; }
                setBaseline(b.ts, b.url, b.title, b.domain);
              });
            }
          } else {
            box.innerHTML = '<div class="alert alert-danger py-2 small mb-0">' + (j.error || '查询失败') + '</div>';
          }
        })
        .catch(function () {
          if (window.jQuery && pm) { window.jQuery(pm).modal('hide'); } else if (pm) { pm.style.display = 'none'; }
          alert('查询失败，请检查网络后重试。');
        });
    });
  }

  // 账号选择回填
  window.fillAccount = function (sel) {
    var opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.dataset) return;
    if (opt.dataset.username) $('username').value = opt.dataset.username;
    var tok = (opt.dataset.token || '').trim();
    if (tok && tok.indexOf('PASTE_') !== 0) { $('token').value = tok; checkToken(); }
  };

  // Token 格式校验
  function isValidToken(v) { return /^(ghp_|gho_|ghu_|ghs_|ghr_|github_pat_)[A-Za-z0-9_]{15,}$/.test(v); }
  function checkToken() {
    var el = $('token'); if (!el) return;
    var v = (el.value || '').trim();
    var badge = $('tokenBadge');
    el.classList.remove('valid', 'invalid');
    if (v === '') { if (badge) badge.textContent = ''; return; }
    if (isValidToken(v)) { el.classList.add('valid'); if (badge) { badge.innerHTML = '<span class="text-success">格式正确</span>'; } }
    else { el.classList.add('invalid'); if (badge) { badge.innerHTML = '<span class="text-danger">格式不正确</span>'; } }
  }
  window.checkToken = checkToken;

  window.checkTokenBeforeCreate = function () {
    var user = ($('username').value || '').trim();
    var repo = ($('repo_name').value || '').trim();
    var v = ($('token').value || '').trim();
    if (user === '') { alert('GitHub 用户名为空，请输入用户名后继续。'); $('username').focus(); return false; }
    if (repo === '') { alert('仓库名称为空，请输入仓库名称后继续。'); $('repo_name').focus(); return false; }
    if (v === '') { alert('GitHub Token 为空，请输入有效的 Token 后继续。'); $('token').focus(); return false; }
    if (!isValidToken(v)) { alert('GitHub Token 格式不正确（应以 ghp_ / gho_ / github_pat_ 等开头，长度至少 15 位）。'); $('token').focus(); return false; }
    return true;
  };

  // 预览（AJAX）
  var btnPreview = $('btnPreview');
  if (btnPreview) {
    btnPreview.addEventListener('click', function () {
      var form = $('form-create');
      var data = new FormData(form);
      data.set('action', 'preview');
      data.set('csrf_token', csrf);
      fetch('archive.php', { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          var wrap = $('previewWrap');
          if (!wrap) return;
          if (!j.ok) { wrap.innerHTML = '<div class="alert alert-danger py-2 small mt-2 mb-0">' + (j.error || '预览失败') + '</div>'; return; }
          var html = '<div class="repo-preview"><h3>预览 1 · GitHub 地址</h3><div class="purl">'
            + (j.github_url ? '<a href="' + j.github_url + '" target="_blank" rel="noopener">' + j.github_url + '</a>（私有仓库）' : '未填写用户名，无法生成地址预览。')
            + '</div></div><div class="repo-preview"><h3>预览 2 · README.md</h3><pre></pre></div>';
          wrap.innerHTML = html;
          wrap.querySelector('pre').textContent = j.readme || '';
        })
        .catch(function () { alert('预览失败，请检查网络。'); });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    checkToken();
    var tok = $('token');
    if (tok) tok.addEventListener('input', checkToken);
    var sel = $('proxy_preset');
    if (sel) onProxyPreset(sel);
  });
})();
</script>
