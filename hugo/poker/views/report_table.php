<div class="container app-page" id="main">
  <div class="card card-sops">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
      <h1 class="h5 mb-0 page-title"><i class="bi bi-bar-chart-line mr-2" aria-hidden="true"></i><?php echo e($title); ?></h1>
      <div>
        <a class="btn btn-sm btn-outline-secondary" href="seo_report.php?reporttype=wordlist"><i class="bi bi-list-check mr-1" aria-hidden="true"></i>关键词列表</a>
        <a class="btn btn-sm btn-outline-secondary" href="seo_report.php?reporttype=relateword"><i class="bi bi-link-45deg mr-1" aria-hidden="true"></i>关联关键词</a>
        <a class="btn btn-sm btn-outline-secondary" href="seo_report.php?reporttype=sitelist"><i class="bi bi-globe mr-1" aria-hidden="true"></i>站点列表</a>
        <a class="btn btn-sm btn-outline-secondary" href="seo_report.php?reporttype=topiclist"><i class="bi bi-journal-richtext mr-1" aria-hidden="true"></i>话题列表</a>
        <a class="btn btn-sm btn-outline-secondary" href="keywordops.php"><i class="bi bi-plus-circle mr-1" aria-hidden="true"></i>新增关键词</a>
      </div>
    </div>
    <div class="card-body">
    <table id="table" class="table table-hover table-sm">
      <thead>
        <tr>
          <?php foreach ($columns as $column): ?>
          <th scope="col"><?php echo e($column['title']); ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
    <script>
      var reportType = <?php echo json_encode($type); ?>;
      var reportColumns = [<?php
          $out = [];
          foreach ($columns as $column) {
              $item = ['field' => $column['field'], 'title' => $column['title']];
              if (!empty($column['sortable'])) {
                  $item['sortable'] = true;
              }
              if (isset($column['align'])) {
                  $item['align'] = $column['align'];
              }
              if (isset($column['width'])) {
                  $item['width'] = $column['width'];
              }
              if (isset($column['class'])) {
                  $item['class'] = $column['class'];
              }
              if (isset($column['formatter'])) {
                  $item['formatter'] = $column['formatter'];
              }
              $out[] = json_encode($item, JSON_UNESCAPED_UNICODE);
          }
          echo implode(',', $out);
      ?>];

      function siteEditer(value, row, index) {
        var html = '<a class="btn btn-sm btn-outline-primary" target="_blank" href="siteops.php?eid=' + row.ctx_id + '" title="Editor"><i class="bi bi-pencil-square mr-1" aria-hidden="true"></i>编辑</a>';
        html += '<a class="btn btn-sm btn-outline-danger ml-1" href="javascript:void(0)" onclick="siteDeleteConfirm(this, \'' + row.ctx_id + '\', \'' + String(row.domain || row.git_name || '').replace(/'/g, '') + '\')"><i class="bi bi-trash mr-1" aria-hidden="true"></i>删除</a>';
        return html;
      }

      var deleteTarget = null;
      var deleteAction = null;
      function openDeleteConfirm(ctxId, label, action) {
        deleteTarget = ctxId;
        deleteAction = action;
        document.getElementById('siteDeleteLabel').textContent = String(label || ctxId);
        var title = action === 'delete-keyword' ? '删除关键词' : '删除站点';
        document.getElementById('siteDeleteModalTitle').innerHTML = '<i class="bi bi-exclamation-triangle text-danger mr-1" aria-hidden="true"></i>' + title;
        window.jQuery('#siteDeleteModal').modal('show');
      }

      function siteDeleteConfirm(btn, ctxId, label) {
        openDeleteConfirm(ctxId, label, 'delete-site');
      }

      function keywordDeleteConfirm(btn, ctxId, label) {
        openDeleteConfirm(ctxId, label, 'delete-keyword');
      }

      function siteDeleteExecute() {
        if (!deleteTarget || !deleteAction) { return; }
        var csrf = '<?php echo e(isset($csrf_token) ? $csrf_token : ''); ?>';
        var payload = 'action=' + deleteAction + '&ctx_id=' + encodeURIComponent(deleteTarget) + '&csrf_token=' + encodeURIComponent(csrf);
        window.jQuery('#siteDeleteModal').modal('hide');
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'seo_report.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function () {
          if (xhr.readyState === 4) {
            if (xhr.status === 200) {
              var resp = null;
              try { resp = JSON.parse(xhr.responseText); } catch (e) {}
              if (resp && resp.ok) {
                if (window.jQuery && window.jQuery.fn && window.jQuery.fn.bootstrapTable) {
                  jQuery('#table').bootstrapTable('refresh');
                } else {
                  window.location.reload();
                }
              } else {
                sopsToast((resp && resp.message) ? resp.message : '删除失败', 'danger');
              }
            } else {
              sopsToast('删除失败 (HTTP ' + xhr.status + ')', 'danger');
            }
          }
        };
        xhr.send(payload);
      }

      function keywordEditer(value, row, index) {
        var html = '<a class="btn btn-sm btn-outline-primary" target="_blank" href="keywordops.php?eid=' + row.ctx_id + '" title="Editor"><i class="bi bi-pencil-square mr-1" aria-hidden="true"></i>编辑</a>';
        html += '<a class="btn btn-sm btn-outline-danger ml-1" href="javascript:void(0)" onclick="keywordDeleteConfirm(this, \'' + row.ctx_id + '\', \'' + String(row.keyword || row.git_name || '').replace(/'/g, '') + '\')"><i class="bi bi-trash mr-1" aria-hidden="true"></i>删除</a>';
        return html;
      }

      function topicEditer(value, row, index) {
        return '<a class="btn btn-sm btn-outline-primary" target="_blank" href="topicops.php?eid=' + row.ctx_id + '" title="Editor"><i class="bi bi-pencil-square mr-1" aria-hidden="true"></i>编辑</a>';
      }

      (function () {
        function initReportTable() {
          if (window.jQuery && window.jQuery.fn && window.jQuery.fn.bootstrapTable) {
            jQuery('#table').bootstrapTable({
              locale: 'zh-CN',
              iconsPrefix: 'bi',
              url: 'seo_report.php',
              sidePagination: 'server',
              totalField: 'total',
              dataField: 'rows',
              queryParams: function (params) {
                return {
                  format: 'json',
                  reporttype: reportType,
                  offset: params.offset,
                  limit: params.limit,
                  search: params.search || '',
                  sort: params.sort,
                  order: params.order
                };
              },
              columns: reportColumns,
              search: true,
              showToggle: true,
              showColumns: true,
              showRefresh: true,
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
        if (!initReportTable()) {
          var timer = setInterval(function () {
            if (initReportTable()) {
              clearInterval(timer);
            }
          }, 100);
          setTimeout(function () { clearInterval(timer); }, 10000);
        }
      })();
    </script>
    </div>
  </div>
</div>
<?php if ($type === 'sitelist' || $type === 'wordlist'): ?>
<div class="modal fade" id="siteDeleteModal" tabindex="-1" role="dialog" aria-labelledby="siteDeleteModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="siteDeleteModalTitle"><i class="bi bi-exclamation-triangle text-danger mr-1" aria-hidden="true"></i>删除站点</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <p>确定要删除「<strong id="siteDeleteLabel"></strong>」吗？</p>
        <p class="text-danger small mb-0">删除后将从列表移除并重新导出配置，此操作不可恢复。</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">取消</button>
        <button type="button" class="btn btn-danger" onclick="siteDeleteExecute()"><i class="bi bi-trash mr-1" aria-hidden="true"></i>确定删除</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>