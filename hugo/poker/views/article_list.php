<div class="container app-page" id="main">
  <div class="card card-sops">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
      <h1 class="h5 mb-0 page-title"><i class="bi bi-file-earmark-text mr-2" aria-hidden="true"></i>文章列表</h1>
      <div>
        <a class="btn btn-sm btn-outline-secondary" href="article_new.php"><i class="bi bi-plus-circle mr-1" aria-hidden="true"></i>新建文章</a>
        <?php if (!empty($can_manage)): ?>
        <button class="btn btn-sm btn-outline-warning" type="button" id="importLogBtn" title="从 global_config.php base.log_file 配置的日志文件导入"><i class="bi bi-cloud-download mr-1" aria-hidden="true"></i>导入日志</button>
        <?php endif; ?>
      </div>
    </div>
    <div class="card-body">
    <?php if (!empty($can_manage)): ?>
    <div class="modal fade" id="articleImportModal" tabindex="-1" role="dialog" aria-labelledby="articleImportModalTitle" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="articleImportModalTitle"><i class="bi bi-cloud-download text-warning mr-1" aria-hidden="true"></i>导入日志</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <div class="modal-body">
            <p>将从日志文件（<strong><?php echo e(implode(', ', isset($log_files) ? (array)$log_files : ['editor_poker_allpost_list.txt'])); ?></strong>）导入文章到列表，导入后同步更新 seodata/aigc_status.json 与 seodata/json/。</p>
            <ul class="mb-0 small text-muted">
              <li>已存在的记录将跳过；</li>
              <li>JSON 文件缺失的文章将跳过（不入库）；</li>
              <li>导入的文章将同步写入 seodata/json/ 并更新 aigc_status.json。</li>
            </ul>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">取消</button>
            <button type="button" class="btn btn-warning" id="articleImportExecuteBtn"><i class="bi bi-cloud-download mr-1" aria-hidden="true"></i>确定导入</button>
          </div>
        </div>
      </div>
    </div>
    <script>
      (function () {
        var importBtn = document.getElementById('importLogBtn');
        var importModal = document.getElementById('articleImportModal');
        var executeBtn = document.getElementById('articleImportExecuteBtn');
        if (!importBtn || !importModal || !executeBtn) { return; }
        importBtn.addEventListener('click', function () {
          window.jQuery(importModal).modal('show');
        });
        executeBtn.addEventListener('click', function () {
          executeBtn.disabled = true;
          executeBtn.innerHTML = '<i class="bi bi-arrow-repeat mr-1" aria-hidden="true"></i>导入中…';
          var csrf = '<?php echo e(isset($csrf_token) ? $csrf_token : ''); ?>';
          var xhr = new XMLHttpRequest();
          xhr.open('POST', 'article_list.php', true);
          xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
          xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) { return; }
            executeBtn.disabled = false;
            executeBtn.innerHTML = '<i class="bi bi-cloud-download mr-1" aria-hidden="true"></i>确定导入';
            window.jQuery(importModal).modal('hide');
            var isError = true;
            var msg = '导入失败（HTTP ' + xhr.status + '）';
            if (xhr.status === 200) {
              var resp = null;
              try { resp = JSON.parse(xhr.responseText); } catch (e) {}
              if (resp && resp.rows && resp.rows[0]) {
                msg = resp.rows[0].message || '导入完成';
                isError = resp.rows[0].ok === false;
              }
            }
            sopsToast(msg, isError ? 'danger' : 'success');
            if (window.jQuery && window.jQuery.fn && window.jQuery.fn.bootstrapTable && document.getElementById('articleTable')) {
              jQuery('#articleTable').bootstrapTable('refresh');
            } else {
              window.location.reload();
            }
          };
          xhr.send('action=import-log&csrf_token=' + encodeURIComponent(csrf));
        });
      })();
    </script>
    <?php endif; ?>
<?php if ($total > 0): ?>
    <table id="articleTable" class="table table-hover table-sm">
      <thead>
        <tr>
          <th scope="col">日期</th>
          <th scope="col">标题</th>
          <th scope="col">封面</th>
          <th scope="col">语言</th>
          <th scope="col">关键词</th>
          <th scope="col">关联站点</th>
          <th scope="col">更新时间</th>
          <th scope="col">操作</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
    <script>
      function articleTimeFormatter(value) {
        if (!value) { return value; }
        var s = String(value);
        if (/^\d{4}-\d{2}-\d{2}/.test(s)) {
          return s;
        }
        var ts = parseInt(s.slice(0, 10), 10);
        if (!isNaN(ts) && ts > 0) {
          var d = new Date(ts * 1000);
          if (!isNaN(d.getTime())) {
            return d.toLocaleString();
          }
        }
        return value;
      }

      function articleThumbFormatter(value) {
        if (!value) { return ''; }
        return '<a href="javascript:void(0)" onclick="articlePreviewImage(this)" data-src="' + String(value).replace(/"/g, '&quot;') + '"><img src="' + String(value).replace(/"/g, '&quot;') + '" alt="封面" style="max-width:60px;max-height:40px;object-fit:cover;" loading="lazy"></a>';
      }

      function articlePreviewImage(elm) {
        var src = elm.getAttribute('data-src');
        if (!src) { return; }
        document.getElementById('modalImage').setAttribute('src', src);
        window.jQuery('#imagePreviewModal').modal('show');
      }

      function articleEditer(value, row, index) {
        var html = '<a class="btn btn-sm btn-outline-primary" target="_blank" href="article_new.php?eid=' + encodeURIComponent(row.ctx_id) + '" title="Editor"><i class="bi bi-pencil-square mr-1" aria-hidden="true"></i>编辑</a>';
        html += '<a class="btn btn-sm btn-outline-danger ml-1" href="javascript:void(0)" onclick="articleDeleteConfirm(this, \'' + String(row.ctx_id).replace(/'/g, '') + '\', \'' + String(row.title || row.ctx_id || '').replace(/'/g, '') + '\')"><i class="bi bi-trash mr-1" aria-hidden="true"></i>删除</a>';
        return html;
      }

      var articleDeleteTarget = null;
      function articleDeleteConfirm(btn, ctxId, label) {
        articleDeleteTarget = ctxId;
        document.getElementById('articleDeleteLabel').textContent = String(label || ctxId);
        window.jQuery('#articleDeleteModal').modal('show');
      }

      function articleDeleteExecute() {
        if (!articleDeleteTarget) { return; }
        var csrf = '<?php echo e(isset($csrf_token) ? $csrf_token : ''); ?>';
        var payload = 'action=delete-article&ctx_id=' + encodeURIComponent(articleDeleteTarget) + '&csrf_token=' + encodeURIComponent(csrf);
        window.jQuery('#articleDeleteModal').modal('hide');
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'article_list.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function () {
          if (xhr.readyState === 4) {
            if (xhr.status === 200) {
              var resp = null;
              try { resp = JSON.parse(xhr.responseText); } catch (e) {}
              var ok = resp && resp.rows && resp.rows[0] && resp.rows[0].ok;
              if (ok) {
                if (window.jQuery && window.jQuery.fn && window.jQuery.fn.bootstrapTable) {
                  jQuery('#articleTable').bootstrapTable('refresh');
                } else {
                  window.location.reload();
                }
              } else {
                sopsToast((resp && resp.rows && resp.rows[0] && resp.rows[0].message) ? resp.rows[0].message : '删除失败', 'danger');
              }
            } else {
              sopsToast('删除失败 (HTTP ' + xhr.status + ')', 'danger');
            }
          }
        };
        xhr.send(payload);
      }

      (function () {
        function initArticleTable() {
          if (window.jQuery && window.jQuery.fn && window.jQuery.fn.bootstrapTable) {
            jQuery('#articleTable').bootstrapTable({
              locale: 'zh-CN',
              iconsPrefix: 'bi',
              url: 'article_list.php',
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
                { field: 'ctx_id', title: '日期', sortable: true, formatter: articleTimeFormatter },
                { field: 'title', title: '标题', sortable: true },
                { field: 'static_thumbnail', title: '封面', formatter: articleThumbFormatter },
                { field: 'lang', title: '语言', sortable: true },
                { field: 'keyword', title: '关键词', sortable: true },
                { field: 'pubdomain', title: '关联站点', sortable: true },
                { field: 'update_date', title: '更新时间', sortable: true, formatter: articleTimeFormatter },
                { field: '_op', title: '操作', class: 'op-col', width: 150, align: 'center', formatter: articleEditer }
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
        if (!initArticleTable()) {
          var timer = setInterval(function () {
            if (initArticleTable()) {
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
      暂无文章（请先在【新建文章】中提交文章）
    </div>
<?php endif; ?>
    </div>
  </div>
</div>
<?php if ($total > 0): ?>
<div class="modal fade" id="articleDeleteModal" tabindex="-1" role="dialog" aria-labelledby="articleDeleteModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="articleDeleteModalTitle"><i class="bi bi-exclamation-triangle text-danger mr-1" aria-hidden="true"></i>删除文章</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <p>确定要删除文章「<strong id="articleDeleteLabel"></strong>」吗？</p>
        <p class="text-danger small mb-0">删除后该文章将从列表移除，此操作不可恢复。</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">取消</button>
        <button type="button" class="btn btn-danger" onclick="articleDeleteExecute()"><i class="bi bi-trash mr-1" aria-hidden="true"></i>确定删除</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>