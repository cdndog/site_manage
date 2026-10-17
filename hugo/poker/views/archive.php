<?php
use App\Services\ArchiveConfigService;
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
if ($selPreset !== '' && $selPreset !== '__manual__' && ArchiveConfigService::proxyById($selPreset) === '') {
    $selPreset = ''; // 未知预设回退自动
}
if ($selPreset === '' && $proxies) {
    $selPreset = (string)($proxies[0]['id'] ?? ''); // 默认选首个预设（保持原行为）
}
// 代理 combobox 展示态：手工 → 输入地址；预设 → 显示预设名（隐藏字段携带 id，地址不落 DOM）；空 → 自动
$proxyHidden = '';
$proxyDisplay = '';
if ($selPreset === '__manual__') {
    $proxyHidden = '__manual__';
    $proxyDisplay = $f('proxy_addr');
} elseif ($selPreset !== '') {
    $proxyHidden = $selPreset;
    foreach ($proxies as $pPick) {
        if ((string)($pPick['id'] ?? '') === $selPreset) {
            $proxyDisplay = trim((string)($pPick['name'] ?? '')) !== '' ? (string)$pPick['name'] : (string)($pPick['id'] ?? '');
            break;
        }
    }
} else {
    $proxyDisplay = $f('proxy_addr');
}
// 联想数据：首行 = 自动，预设行名称主行 + 打码地址辅行（真实地址不进 DOM，防凭据泄露）
$pxOpts = [['id' => '', 'name' => '自动（直连 / 环境代理回退）', 'urlMasked' => '']];
foreach ($proxies as $pPx) {
    $pxOpts[] = [
        'id' => (string)($pPx['id'] ?? ''),
        'name' => trim((string)($pPx['name'] ?? '')) !== '' ? (string)$pPx['name'] : (string)($pPx['id'] ?? ''),
        'urlMasked' => (string)preg_replace('#(//[^:/@]+):[^@]+@#', '$1:***@', (string)($pPx['url'] ?? '')),
    ];
}
$siteOptions = isset($site_options) && is_array($site_options) ? $site_options : [];
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
  .archive-page .site-ac { position:relative; }
  .archive-page .site-ac-menu { position:absolute; left:0; right:0; top:100%; margin-top:2px; z-index:1060; background:var(--card-bg, #fff); color:var(--body-color, inherit); border:1px solid rgba(0,0,0,.15); border-radius:.25rem; box-shadow:0 6px 18px rgba(0,0,0,.12); max-height:280px; overflow-y:auto; }
  .archive-page .site-ac-item { padding:.35rem .6rem; cursor:pointer; display:flex; flex-direction:column; gap:1px; font-size:.82rem; line-height:1.3; }
  .archive-page .site-ac-item.site-ac-active, .archive-page .site-ac-item:hover { background:rgba(13,110,253,.1); }
  .archive-page .site-ac-domain { color:var(--body-color, inherit); word-break:break-all; }
  .archive-page .site-ac-title { color:var(--sops-muted, #6c757d); font-size:.72rem; }
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
              <label class="small-label" for="domain">域名（输入可搜索站点，亦可手工录入；如 www.sdwenkong.com）</label>
              <div class="site-ac">
                <input type="text" class="form-control form-control-sm" id="domain" name="domain" value="<?php echo e($f('domain')); ?>" placeholder="www.example.com"
                       autocomplete="off" role="combobox" aria-expanded="false" aria-controls="site_ac_menu" aria-autocomplete="list"
                       onfocus="siteAcRender()" oninput="siteAcRender()" onkeydown="siteAcKey(event)"
                       onblur="setTimeout(function(){ siteAcClose(); }, 150)">
                <div class="site-ac-menu" id="site_ac_menu" role="listbox" hidden
                     onmousedown="var it = siteAcItemOf(event.target); if (it) { event.preventDefault(); }"
                     onclick="var it = siteAcItemOf(event.target); if (it) { siteAcPick(it); }"></div>
              </div>
            </div>
            <div class="f-proxy">
              <label class="small-label" for="proxy_addr">代理（输入可搜索预设，亦可手工录入地址；测试探测外网连通性）</label>
              <div class="site-ac">
                <input type="text" class="form-control form-control-sm" id="proxy_addr" name="proxy_addr"
                       value="<?php echo e($proxyDisplay); ?>" placeholder="留空 = 自动；或 http://ip:port / socks5h://ip:port"
                       autocomplete="off" role="combobox" aria-expanded="false" aria-controls="px_ac_menu" aria-autocomplete="list"
                       onfocus="pxAcRender()" oninput="pxAcInput()" onkeydown="pxAcKey(event)"
                       onblur="setTimeout(function(){ pxAcClose(); }, 150)" title="自动 / 预设 / 手工代理地址">
                <input type="hidden" name="proxy_preset" id="proxy_preset" value="<?php echo e($proxyHidden); ?>"<?php if ($proxyHidden !== '' && $proxyHidden !== '__manual__') { ?> data-picked="<?php echo e($proxyDisplay); ?>"<?php } ?>>
                <div class="site-ac-menu" id="px_ac_menu" role="listbox" hidden
                     onmousedown="var it = pxAcItemOf(event.target); if (it) { event.preventDefault(); }"
                     onclick="var it = pxAcItemOf(event.target); if (it) { pxAcPick(it); }"></div>
              </div>
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

          <div id="siteMetaBox" class="mt-2 mb-0" style="display:none;">
            <span class="archive-hint">归属账号：<b id="siteMetaAccount" class="mono" style="color:var(--sops-indigo);"></b></span>
            <span class="archive-hint ml-3">站点标题：<b id="siteMetaTitle" style="color:var(--body-color);"></b></span>
          </div>

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
      if (domain === '') { if (window.sopsToast) sopsToast('请先输入域名', 'warning'); $('domain').focus(); return; }
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
                if (!b) { if (window.sopsToast) sopsToast('请先选择一条记录', 'warning'); return; }
                setBaseline(b.ts, b.url, b.title, b.domain);
              });
            }
          } else {
            box.innerHTML = '<div class="alert alert-danger py-2 small mb-0">' + (j.error || '查询失败') + '</div>';
          }
        })
        .catch(function () {
          if (window.jQuery && pm) { window.jQuery(pm).modal('hide'); } else if (pm) { pm.style.display = 'none'; }
          if (window.sopsToast) sopsToast('查询失败，请检查网络后重试。', 'danger');
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

  // 域名联想：站点下拉（支持搜索）+ 手工录入一体的 combobox
  // ↑/↓ 移动高亮，Enter 选中或直接提交，Esc 关闭；鼠标点击选中；输入即按 域名/站点名 过滤
  var SITE_OPTIONS = <?php echo json_encode($siteOptions, JSON_UNESCAPED_UNICODE); ?>;
  var siteAcActive = -1;
  function siteAcEsc(v) {
    return String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  window.siteAcItemOf = function (t) {
    while (t && t !== document) {
      if (t.classList && t.classList.contains('site-ac-item')) return t;
      t = t.parentNode;
    }
    return null;
  };
  window.siteAcClose = function () {
    var box = $('site_ac_menu'), input = $('domain');
    if (box) box.hidden = true;
    if (input) input.setAttribute('aria-expanded', 'false');
    siteAcActive = -1;
  };
  window.siteAcRender = function () {
    var box = $('site_ac_menu'), input = $('domain');
    if (!box || !input) return;
    var raw = (input.value || '').trim();
    var pickedD = input.getAttribute('data-picked') || '';
    // 值仍是已选站点 = 未过滤，聚焦即显示全部项（与代理 combobox 一致）
    var q = (raw !== '' && raw === pickedD) ? '' : raw.toLowerCase();
    var list = (SITE_OPTIONS || []).filter(function (s) {
      if (!q) return true;
      return String(s.domain || '').toLowerCase().indexOf(q) !== -1 || String(s.site_title || '').toLowerCase().indexOf(q) !== -1 || String(s.git_account || '').toLowerCase().indexOf(q) !== -1;
    }).slice(0, 50);
    if (!list.length) { siteAcClose(); box.innerHTML = ''; return; }
    box.innerHTML = list.map(function (s) {
      var title = String(s.site_title || '').trim();
      var acct = String(s.git_account || '').trim();
      var domainLabel = (acct ? String(s.domain || '') + ' - ' + acct : String(s.domain || ''));
      return '<div class="site-ac-item" role="option" data-domain="' + siteAcEsc(s.domain || '') + '">'
        + '<span class="site-ac-domain">' + siteAcEsc(domainLabel) + '</span>'
        + (title ? '<span class="site-ac-title">' + siteAcEsc(title) + '</span>' : '')
        + '</div>';
    }).join('');
    box.hidden = false;
    input.setAttribute('aria-expanded', 'true');
    siteAcActive = -1;
  };
  // 域名归属信息展示：选中/输入域名后在提示下方显示 git_account 与站点标题
  function siteMetaUpdate(domain) {
    var box = $('siteMetaBox'), accEl = $('siteMetaAccount'), titleEl = $('siteMetaTitle');
    if (!box || !accEl || !titleEl) return;
    var d = String(domain || '').trim().toLowerCase();
    if (!d) { box.style.display = 'none'; return; }
    var hit = null;
    for (var i = 0; i < (SITE_OPTIONS || []).length; i++) {
      if (String(SITE_OPTIONS[i].domain || '').trim().toLowerCase() === d) { hit = SITE_OPTIONS[i]; break; }
    }
    if (!hit) { box.style.display = 'none'; return; }
    accEl.textContent = String(hit.git_account || '').trim() || '—';
    titleEl.textContent = String(hit.site_title || '').trim() || '—';
    box.style.display = '';
  }
  window.__siteMetaUpdate = siteMetaUpdate;

  window.siteAcPick = function (el) {
    var input = $('domain');
    if (!el || !input) return;
    input.value = el.getAttribute('data-domain') || '';
    input.setAttribute('data-picked', input.value);
    siteMetaUpdate(input.value);
    siteAcClose();
    input.focus();
  };
  window.siteAcKey = function (e) {
    var box = $('site_ac_menu');
    if (!box) return;
    if (box.hidden) { if (e.key === 'ArrowDown') siteAcRender(); return; }
    var items = box.querySelectorAll('.site-ac-item');
    if (!items.length) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      siteAcActive += (e.key === 'ArrowDown' ? 1 : -1);
      if (siteAcActive < 0) siteAcActive = items.length - 1;
      if (siteAcActive >= items.length) siteAcActive = 0;
      for (var i = 0; i < items.length; i++) {
        if (items[i].classList) items[i].classList.toggle('site-ac-active', i === siteAcActive);
      }
      if (items[siteAcActive] && items[siteAcActive].scrollIntoView) {
        items[siteAcActive].scrollIntoView({ block: 'nearest' });
      }
    } else if (e.key === 'Enter') {
      if (siteAcActive >= 0 && items[siteAcActive]) { e.preventDefault(); siteAcPick(items[siteAcActive]); }
      else { siteAcClose(); } // 无高亮时 Enter 交给表单提交（手工录入路径）
    } else if (e.key === 'Escape') {
      siteAcClose();
    }
  };

  // 代理联想：预设下拉（支持搜索，地址打码显示）+ 手工录入 + 空值 = 自动，交互同域名 combobox
  var PX_OPTIONS = <?php echo json_encode($pxOpts, JSON_UNESCAPED_UNICODE); ?>;
  var pxAcActive = -1;
  window.pxAcItemOf = function (t) {
    while (t && t !== document) {
      if (t.classList && t.classList.contains('site-ac-item')) return t;
      t = t.parentNode;
    }
    return null;
  };
  window.pxAcClose = function () {
    var box = $('px_ac_menu'), input = $('proxy_addr');
    if (box) box.hidden = true;
    if (input) input.setAttribute('aria-expanded', 'false');
    pxAcActive = -1;
  };
  window.pxAcRender = function () {
    var box = $('px_ac_menu'), input = $('proxy_addr'), hid = $('proxy_preset');
    if (!box || !input) return;
    var raw = (input.value || '').trim();
    var picked = (hid && hid.getAttribute('data-picked')) || '';
    // 值仍是已选预设名 = 未过滤，聚焦即显示全部项；一旦改动则按输入过滤
    var q = (raw !== '' && raw === picked) ? '' : raw.toLowerCase();
    var list = (PX_OPTIONS || []).filter(function (s) {
      if (!q) return true;
      return String(s.name || '').toLowerCase().indexOf(q) !== -1
        || String(s.id || '').toLowerCase().indexOf(q) !== -1
        || String(s.urlMasked || '').toLowerCase().indexOf(q) !== -1;
    }).slice(0, 50);
    if (!list.length) { pxAcClose(); box.innerHTML = ''; return; }
    box.innerHTML = list.map(function (s) {
      return '<div class="site-ac-item" role="option" data-id="' + siteAcEsc(s.id || '') + '" data-name="' + siteAcEsc(s.name || '') + '">'
        + '<span class="site-ac-domain">' + siteAcEsc(s.name || '') + '</span>'
        + (s.urlMasked ? '<span class="site-ac-title">' + siteAcEsc(s.urlMasked) + '</span>' : '')
        + '</div>';
    }).join('');
    box.hidden = false;
    input.setAttribute('aria-expanded', 'true');
    pxAcActive = -1;
  };
  window.pxAcPick = function (el) {
    var input = $('proxy_addr'), hid = $('proxy_preset');
    if (!el || !input || !hid) return;
    var id = el.getAttribute('data-id') || '';
    var name = el.getAttribute('data-name') || '';
    if (id === '') { // 自动：清空输入与预设
      input.value = ''; hid.value = ''; hid.removeAttribute('data-picked');
    } else { // 预设：输入框显示预设名，隐藏字段携带 id（真实地址不进 DOM）
      input.value = name; hid.value = id; hid.setAttribute('data-picked', name);
    }
    pxAcClose();
    input.focus();
  };
  window.pxAcInput = function () {
    var input = $('proxy_addr'), hid = $('proxy_preset');
    if (!input || !hid) return;
    var v = (input.value || '').trim();
    var picked = hid.getAttribute('data-picked') || '';
    if (v === '') { hid.value = ''; hid.removeAttribute('data-picked'); } // 空 = 自动
    else if (v !== picked) { hid.value = '__manual__'; hid.removeAttribute('data-picked'); } // 一经编辑即手工模式
    pxAcRender();
  };
  window.pxAcKey = function (e) {
    var box = $('px_ac_menu');
    if (!box) return;
    if (box.hidden) { if (e.key === 'ArrowDown') pxAcRender(); return; }
    var items = box.querySelectorAll('.site-ac-item');
    if (!items.length) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      pxAcActive += (e.key === 'ArrowDown' ? 1 : -1);
      if (pxAcActive < 0) pxAcActive = items.length - 1;
      if (pxAcActive >= items.length) pxAcActive = 0;
      for (var i = 0; i < items.length; i++) {
        if (items[i].classList) items[i].classList.toggle('site-ac-active', i === pxAcActive);
      }
      if (items[pxAcActive] && items[pxAcActive].scrollIntoView) items[pxAcActive].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter') {
      if (pxAcActive >= 0 && items[pxAcActive]) { e.preventDefault(); pxAcPick(items[pxAcActive]); }
      else { pxAcClose(); } // 无高亮时 Enter 交给表单提交
    } else if (e.key === 'Escape') {
      pxAcClose();
    }
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
    if (user === '') { if (window.sopsToast) sopsToast('GitHub 用户名为空，请输入用户名后继续。', 'warning'); $('username').focus(); return false; }
    if (repo === '') { if (window.sopsToast) sopsToast('仓库名称为空，请输入仓库名称后继续。', 'warning'); $('repo_name').focus(); return false; }
    if (v === '') { if (window.sopsToast) sopsToast('GitHub Token 为空，请输入有效的 Token 后继续。', 'warning'); $('token').focus(); return false; }
    if (!isValidToken(v)) { if (window.sopsToast) sopsToast('GitHub Token 格式不正确（应以 ghp_ / gho_ / github_pat_ 等开头，长度至少 15 位）。', 'warning'); $('token').focus(); return false; }
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
        .catch(function () { if (window.sopsToast) sopsToast('预览失败，请检查网络。', 'danger'); });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    checkToken();
    var tok = $('token');
    if (tok) tok.addEventListener('input', checkToken);
    var domInput = $('domain');
    if (domInput) {
      siteMetaUpdate(domInput.value); // 回填态（服务端渲染 domain）也展示归属信息
      domInput.addEventListener('input', function () { siteMetaUpdate(domInput.value); });
    }
  });
})();
</script>
