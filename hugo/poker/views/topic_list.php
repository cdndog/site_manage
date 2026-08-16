<div class="container app-page" id="main">
  <div class="card card-sops">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
      <h1 class="h5 mb-0 page-title"><i class="bi bi-journal-richtext mr-2" aria-hidden="true"></i>话题列表</h1>
      <div>
        <a class="btn btn-sm btn-outline-secondary" href="topicops.php"><i class="bi bi-plus-circle mr-1" aria-hidden="true"></i>新增话题</a>
        <a class="btn btn-sm btn-outline-secondary" href="topictable.php"><i class="bi bi-bar-chart-line mr-1" aria-hidden="true"></i>话题报表</a>
      </div>
    </div>
    <div class="card-body">
<?php if ($total > 0): ?>
    <table id="table" class="table table-hover table-sm">
      <thead>
        <tr>
          <th scope="col">日期</th>
          <th scope="col">文章与话题</th>
          <th scope="col">状态</th>
          <th scope="col">发布GIT</th>
          <th scope="col">发布域名</th>
          <th scope="col">发布目录</th>
          <th scope="col">发布时间</th>
          <th scope="col">语言</th>
          <th scope="col">区域</th>
          <th scope="col">操作</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
    <script>
      function topicTimeFormatter(value) {
        var ts = parseInt(String(value).slice(0, 10), 10);
        if (!isNaN(ts) && ts > 0) {
          var d = new Date(ts * 1000);
          if (!isNaN(d.getTime())) {
            return d.toLocaleString();
          }
        }
        return value;
      }

      function topicEditer(value, row, index) {
        var html = '<a class="btn btn-sm btn-outline-primary" target="_blank" href="topicops.php?eid=' + row.ctx_id + '" title="Editor"><i class="bi bi-pencil-square mr-1" aria-hidden="true"></i>编辑</a>';
        html += '<a class="btn btn-sm btn-outline-danger ml-1" href="javascript:void(0)" onclick="topicDeleteConfirm(this, \'' + row.ctx_id + '\', \'' + String(row.keyword || row.git_name || '').replace(/'/g, '') + '\')"><i class="bi bi-trash mr-1" aria-hidden="true"></i>删除</a>';
        return html;
      }

      var topicDeleteTarget = null;
      function topicDeleteConfirm(btn, ctxId, label) {
        topicDeleteTarget = ctxId;
        document.getElementById('topicDeleteLabel').textContent = String(label || ctxId);
        window.jQuery('#topicDeleteModal').modal('show');
      }

      function topicDeleteExecute() {
        if (!topicDeleteTarget) { return; }
        var csrf = '<?php echo e(isset($csrf_token) ? $csrf_token : ''); ?>';
        var payload = 'action=delete-topic&ctx_id=' + encodeURIComponent(topicDeleteTarget) + '&csrf_token=' + encodeURIComponent(csrf);
        window.jQuery('#topicDeleteModal').modal('hide');
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'topiclist.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function () {
          if (xhr.readyState === 4) {
            if (xhr.status === 200) {
              var resp = null;
              try { resp = JSON.parse(xhr.responseText); } catch (e) {}
              var ok = resp && resp.rows && resp.rows[0] && resp.rows[0].ok;
              if (ok) {
                if (window.jQuery && window.jQuery.fn && window.jQuery.fn.bootstrapTable) {
                  jQuery('#table').bootstrapTable('refresh');
                } else {
                  window.location.reload();
                }
              } else {
                window.alert((resp && resp.rows && resp.rows[0] && resp.rows[0].message) ? resp.rows[0].message : '删除失败');
              }
            } else {
              window.alert('删除失败 (HTTP ' + xhr.status + ')');
            }
          }
        };
        xhr.send(payload);
      }

      (function () {
        function initTopicTable() {
          if (window.jQuery && window.jQuery.fn && window.jQuery.fn.bootstrapTable) {
            jQuery('#table').bootstrapTable({
              locale: 'zh-CN',
              iconsPrefix: 'bi',
              url: 'topiclist.php',
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
                { field: 'ctx_id', title: '日期', sortable: true, formatter: topicTimeFormatter },
                { field: 'keyword', title: '文章与话题', sortable: true },
                { field: 'status', title: '状态', sortable: true },
                { field: 'git_name', title: '发布GIT', sortable: true },
                { field: 'domain', title: '发布域名', sortable: true },
                { field: 'pubdir', title: '发布目录', sortable: true },
                { field: 'lasttask', title: '发布时间', sortable: true },
                { field: 'lang', title: '语言', sortable: true },
                { field: 'geo', title: '区域', sortable: true },
                { field: 'ctx_id', title: '操作', class: 'op-col', width: 150, align: 'center', formatter: topicEditer }
              ],
              search: true,
              showToggle: true,
              showColumns: true,
              showRefresh: true,
              sortName: 'id',
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
        if (!initTopicTable()) {
          var timer = setInterval(function () {
            if (initTopicTable()) {
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
      暂无数据（请先在话题管理中新增话题）
    </div>
<?php endif; ?>
    </div>
  </div>
</div>
<?php if ($total > 0): ?>
<div class="modal fade" id="topicDeleteModal" tabindex="-1" role="dialog" aria-labelledby="topicDeleteModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="topicDeleteModalTitle"><i class="bi bi-exclamation-triangle text-danger mr-1" aria-hidden="true"></i>删除话题</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <p>确定要删除话题「<strong id="topicDeleteLabel"></strong>」吗？</p>
        <p class="text-danger small mb-0">删除后该话题将从话题列表移除并重新导出配置，此操作不可恢复。</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">取消</button>
        <button type="button" class="btn btn-danger" onclick="topicDeleteExecute()"><i class="bi bi-trash mr-1" aria-hidden="true"></i>确定删除</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
