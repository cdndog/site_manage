<div class="container app-page" id="main">
  <div class="card card-sops">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
      <h1 class="h5 mb-0 page-title"><i class="bi bi-list-ul mr-2" aria-hidden="true"></i>改写列表</h1>
      <div class="d-flex align-items-center" style="gap:6px;">
<?php if (!empty($can_manage)): ?>
        <button class="btn btn-sm btn-outline-warning" type="button" id="importArticleBtn" title="从 global_config.php base.rewrite_files 配置的列表文件导入需要改写的文章"><i class="bi bi-cloud-download mr-1" aria-hidden="true"></i>导入文章列表</button>
<?php endif; ?>
        <a class="btn btn-sm btn-outline-secondary" href="rewriteops.php"><i class="bi bi-plus-circle mr-1" aria-hidden="true"></i>新增改写</a>
      </div>
    </div>
    <div class="card-body">
<?php if ($total > 0): ?>
    <table id="table" class="table table-hover table-sm">
      <thead>
        <tr>
          <th scope="col">日期</th>
          <th scope="col">改写关键词</th>
          <th scope="col">状态</th>
          <th scope="col">发布GIT</th>
          <th scope="col">发布域名</th>
          <th scope="col">发布目录</th>
          <th scope="col">语言</th>
          <th scope="col">区域</th>
          <th scope="col">上次任务</th>
          <th scope="col">操作</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
    <script>
      function rewriteTimeFormatter(value) {
        return typeof window.sopsFormatTime === 'function' ? window.sopsFormatTime(value) : (value == null ? '' : String(value));
      }

      function rewriteEditer(value, row) {
        var html = '<a class="btn btn-sm btn-outline-primary" href="rewriteops.php?eid=' + encodeURIComponent(row.ctx_id) + '" title="Editor"><i class="bi bi-pencil-square mr-1" aria-hidden="true"></i>编辑</a>';
        html += '<a class="btn btn-sm btn-outline-danger ml-1" href="javascript:void(0)" onclick="rewriteDeleteConfirm(\'' + String(row.ctx_id).replace(/'/g, '') + '\', \'' + String(row.keyword || row.git_name || '').replace(/'/g, '') + '\')"><i class="bi bi-trash mr-1" aria-hidden="true"></i>删除</a>';
        return html;
      }

      function sopsParseJson(text) {
        text = String(text || '');
        try { return JSON.parse(text); } catch (e) {}
        var s = text.indexOf('{');
        var e2 = text.lastIndexOf('}');
        if (s !== -1 && e2 > s) {
          try { return JSON.parse(text.slice(s, e2 + 1)); } catch (e3) {}
        }
        return null;
      }

      var rewriteDeleteTarget = null;
      function rewriteDeleteConfirm(ctxId, label) {
        rewriteDeleteTarget = ctxId;
        document.getElementById('rewriteDeleteLabel').textContent = String(label || ctxId);
        window.jQuery('#rewriteDeleteModal').modal('show');
      }

      function rewriteRefreshTable() {
        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.bootstrapTable) {
          window.jQuery('#table').bootstrapTable('refresh');
        } else {
          window.location.reload();
        }
      }

      function rewriteDeleteExecute() {
        if (!rewriteDeleteTarget) { return; }
        var csrf = '<?php echo e(isset($csrf_token) ? $csrf_token : ''); ?>';
        var payload = 'action=delete-rewrite&ctx_id=' + encodeURIComponent(rewriteDeleteTarget) + '&csrf_token=' + encodeURIComponent(csrf);
        window.jQuery('#rewriteDeleteModal').modal('hide');
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'rewritelist.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function () {
          if (xhr.readyState !== 4) { return; }
          if (xhr.status === 401) { return; }
          var resp = xhr.status === 200 ? sopsParseJson(xhr.responseText) : null;
          var row = resp && resp.rows && resp.rows[0];
          if (row && row.ok) {
            sopsToast(row.message || '删除成功', 'success');
            rewriteRefreshTable();
          } else if (row && row.message) {
            sopsToast(row.message, 'danger');
          } else {
            sopsToast('删除失败 (HTTP ' + xhr.status + ')', 'danger');
          }
        };
        xhr.send(payload);
      }

      (function () {
        function initRewriteTable() {
          if (window.jQuery && window.jQuery.fn && window.jQuery.fn.bootstrapTable) {
            jQuery('#table').bootstrapTable({
              locale: 'zh-CN',
              iconsPrefix: 'bi',
              url: 'rewritelist.php',
              sidePagination: 'server',
              totalField: 'total',
              dataField: 'rows',
              queryParams: function (params) {
                return {
                  format: 'json',
                  offset: params.offset,
                  limit: params.limit,
                  search: params.search || '',
                  sort: params.sort,
                  order: params.order
                };
              },
              columns: [
                { field: 'ctx_id', title: '日期', sortable: true, formatter: rewriteTimeFormatter },
                { field: 'keyword', title: '改写关键词', sortable: true },
                { field: 'status', title: '状态', sortable: true },
                { field: 'git_name', title: '发布GIT', sortable: true },
                { field: 'domain', title: '发布域名', sortable: true },
                { field: 'pubdir', title: '发布目录', sortable: true },
                { field: 'lang', title: '语言', sortable: true },
                { field: 'geo', title: '区域', sortable: true },
                { field: 'lasttask', title: '上次任务', sortable: true },
                { field: '_op', title: '操作', class: 'op-col', width: 150, align: 'center', formatter: rewriteEditer }
              ],
              search: true,
              showToggle: true,
              showColumns: true,
              showRefresh: true,
              sortName: 'ctx_id',
              sortOrder: 'desc',
              pagination: true,
              pageSize: 20,
              pageList: [10, 20, 50, 100],
              searchPlaceholder: '搜索',
              formatShowingRows: function (pageFrom, pageTo, totalRows) {
                return '显示 ' + pageFrom + ' 到 ' + pageTo + ' 共 ' + totalRows + ' 条';
              },
              formatNoMatches: function () { return '没有找到匹配记录'; },
              formatSearch: function () { return '搜索'; },
              formatRefresh: function () { return '刷新'; },
              formatToggle: function () { return '切换视图'; },
              formatColumns: function () { return '列'; },
              formatPageSize: function () { return '每页'; },
              formatAllRows: function () { return '全部'; }
            });
            return true;
          }
          return false;
        }
        if (!initRewriteTable()) {
          var timer = setInterval(function () {
            if (initRewriteTable()) {
              clearInterval(timer);
            }
          }, 100);
          setTimeout(function () { clearInterval(timer); }, 10000);
        }
      })();
    </script>
<?php else: ?>
    <div class="text-center py-5 text-muted">
      <i class="bi bi-inbox display-4 d-block mb-2"></i>
      暂无数据（请先在改写管理中新增改写数据）
    </div>
<?php endif; ?>
    </div>
  </div>
</div>
<?php if (!empty($can_manage)): ?>
<div class="modal fade" id="rewriteImportModal" tabindex="-1" role="dialog" aria-labelledby="rewriteImportModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="rewriteImportModalTitle"><i class="bi bi-cloud-download text-warning mr-1" aria-hidden="true"></i>导入文章列表</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <?php
        $importFiles = isset($import_files) ? (array)$import_files : [];
        $importLanguages = isset($import_languages) ? (array)$import_languages : [];
        $importCountries = isset($import_countries) ? (array)$import_countries : [];
        ?>
        <div class="form-group">
          <label for="rewriteImportPath">文章列表文件</label>
<?php if (count($importFiles) > 0): ?>
          <select class="form-control" id="rewriteImportPath">
            <?php foreach ($importFiles as $importFile): ?>
            <option value="<?php echo e($importFile); ?>"<?php echo (string)$importFile === (string)(isset($import_default_path) ? $import_default_path : '') ? ' selected' : ''; ?>><?php echo e($importFile); ?></option>
            <?php endforeach; ?>
          </select>
          <small class="form-text text-muted">来自 global_config.php 的 <code>base.rewrite_files</code> 配置。</small>
<?php else: ?>
          <input type="text" class="form-control" id="rewriteImportPath" value="<?php echo e(isset($import_default_path) ? $import_default_path : ''); ?>" placeholder="/Users/apache/Documents/poker/articles/pandagazo_auto/pandagazo_article_allpost_list.txt" autocomplete="off">
          <small class="form-text text-muted">未配置 <code>base.rewrite_files</code>，可填写绝对路径或相对项目根目录的路径。</small>
<?php endif; ?>
        </div>
        <?php $importSites = isset($sites) ? (array)$sites : []; ?>
        <div class="form-group">
          <label for="rewriteImportDomain">发布到域名</label>
<?php if (count($importSites) > 0): ?>
          <select class="form-control" id="rewriteImportDomain">
            <option value="">请选择发布域名</option>
            <?php foreach ($importSites as $site): ?>
            <?php
            $siteDomain = isset($site['domain']) ? (string)$site['domain'] : '';
            $siteGit = isset($site['git_name']) ? (string)$site['git_name'] : '';
            $siteLang = isset($site['rewrite_lang']) ? (string)$site['rewrite_lang'] : (isset($site['languages']) ? (string)$site['languages'] : '');
            $siteGeo = isset($site['rewrite_geo']) ? (string)$site['rewrite_geo'] : '';
            ?>
            <?php if ($siteDomain !== ''): ?>
            <option value="<?php echo e($siteDomain); ?>" data-lang="<?php echo e($siteLang); ?>" data-geo="<?php echo e($siteGeo); ?>"><?php echo e($siteDomain); ?><?php echo $siteGit !== '' ? '（' . e($siteGit) . '）' : ''; ?></option>
            <?php endif; ?>
            <?php endforeach; ?>
          </select>
          <small class="form-text text-muted">来自 siteops 表；导入后所有文章使用该站点的 git_name 与 domain。</small>
<?php else: ?>
          <input type="text" class="form-control" value="siteops 暂无可用站点" readonly>
          <small class="form-text text-muted">请先在站点管理中录入站点。</small>
<?php endif; ?>
        </div>
        <div class="form-row">
          <div class="form-group col-6">
            <label for="rewriteImportLang">语言</label>
            <select class="form-control" id="rewriteImportLang">
              <option value="">跟随文章/站点</option>
              <?php foreach ($importLanguages as $label => $value): ?>
              <option value="<?php echo e($value); ?>"><?php echo e($value); ?> (<?php echo e($label); ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group col-6">
            <label for="rewriteImportGeo">地区</label>
            <select class="form-control" id="rewriteImportGeo">
              <option value="">跟随文章/站点</option>
              <?php foreach ($importCountries as $label => $value): ?>
              <option value="<?php echo e($value); ?>"><?php echo e($value); ?> (<?php echo e($label); ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <small class="form-text text-muted mt-n2 mb-2">默认取所选发布站点的语言与地区；选择后将作为导入改写文章的优化语言与地区。</small>
        <p class="small text-muted mb-1">文件每行格式：<code>ctx_id|url|json</code>（与文章列表日志一致）。</p>
        <ul class="mb-0 small text-muted">
          <li>改写关键词取文章标题；</li>
          <li>发布 git 与域名取所选发布域名对应站点，未选择时回退文章 URL 域名；</li>
          <li>自动识别发布目录；语言、地区默认取所选站点，也可手动调整，导入时写入改写记录；状态设为 rewrite（待改写）；</li>
          <li>已存在的 ctx_id 将跳过，不覆盖已有改写数据。</li>
        </ul>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">取消</button>
        <button type="button" class="btn btn-warning" id="rewriteImportExecuteBtn"><i class="bi bi-cloud-download mr-1" aria-hidden="true"></i>确定导入</button>
      </div>
    </div>
  </div>
</div>
<script>
  (function () {
    var importBtn = document.getElementById('importArticleBtn');
    var importModal = document.getElementById('rewriteImportModal');
    var executeBtn = document.getElementById('rewriteImportExecuteBtn');
    var pathInput = document.getElementById('rewriteImportPath');
    var domainSelect = document.getElementById('rewriteImportDomain');
    var langSelect = document.getElementById('rewriteImportLang');
    var geoSelect = document.getElementById('rewriteImportGeo');
    if (!importBtn || !importModal || !executeBtn || !pathInput) { return; }
    function syncImportLocale() {
      var opt = domainSelect ? domainSelect.options[domainSelect.selectedIndex] : null;
      if (langSelect) { langSelect.value = opt ? String(opt.getAttribute('data-lang') || '') : ''; }
      if (geoSelect) { geoSelect.value = opt ? String(opt.getAttribute('data-geo') || '') : ''; }
    }
    if (domainSelect) { domainSelect.addEventListener('change', syncImportLocale); }
    importBtn.addEventListener('click', function () {
      syncImportLocale();
      window.jQuery(importModal).modal('show');
    });
    executeBtn.addEventListener('click', function () {
      var path = String(pathInput.value || '').trim();
      if (path === '') {
        sopsToast('请填写文章列表文件路径', 'warning');
        pathInput.focus();
        return;
      }
      if (domainSelect && domainSelect.value === '') {
        sopsToast('请选择发布到域名', 'warning');
        domainSelect.focus();
        return;
      }
      executeBtn.disabled = true;
      executeBtn.innerHTML = '<i class="bi bi-arrow-repeat mr-1" aria-hidden="true"></i>导入中…';
      var csrf = '<?php echo e(isset($csrf_token) ? $csrf_token : ''); ?>';
      var xhr = new XMLHttpRequest();
      xhr.open('POST', 'rewritelist.php', true);
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      xhr.onreadystatechange = function () {
        if (xhr.readyState !== 4) { return; }
        if (xhr.status === 401) { return; }
        executeBtn.disabled = false;
        executeBtn.innerHTML = '<i class="bi bi-cloud-download mr-1" aria-hidden="true"></i>确定导入';
        var isError = true;
        var msg = '导入失败（HTTP ' + xhr.status + '）';
        if (xhr.status === 200) {
          var resp = null;
          try { resp = JSON.parse(xhr.responseText); } catch (e) {}
          var row = resp && resp.rows && resp.rows[0];
          if (row) {
            msg = row.message || '导入完成';
            isError = row.ok === false;
          }
        }
        window.jQuery(importModal).modal('hide');
        sopsToast(msg, isError ? 'danger' : 'success');
        if (!isError) {
          window.location.reload();
        }
      };
      var publishDomain = domainSelect ? String(domainSelect.value || '') : '';
      var importLang = langSelect ? String(langSelect.value || '') : '';
      var importGeo = geoSelect ? String(geoSelect.value || '') : '';
      xhr.send('action=import-articles&import_path=' + encodeURIComponent(path) + '&publish_domain=' + encodeURIComponent(publishDomain) + '&import_lang=' + encodeURIComponent(importLang) + '&import_geo=' + encodeURIComponent(importGeo) + '&csrf_token=' + encodeURIComponent(csrf));
    });
  })();
</script>
<?php endif; ?>
<?php if ($total > 0): ?>
<div class="modal fade" id="rewriteDeleteModal" tabindex="-1" role="dialog" aria-labelledby="rewriteDeleteModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="rewriteDeleteModalTitle"><i class="bi bi-exclamation-triangle text-danger mr-1" aria-hidden="true"></i>删除改写数据</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <p>确定要删除改写「<strong id="rewriteDeleteLabel"></strong>」吗？</p>
        <p class="text-danger small mb-0">删除后该条改写数据文件将被移除，此操作不可恢复。</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">取消</button>
        <button type="button" class="btn btn-danger" onclick="rewriteDeleteExecute()"><i class="bi bi-trash mr-1" aria-hidden="true"></i>确定删除</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
