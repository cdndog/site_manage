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
        return '<a class="btn btn-sm btn-outline-primary" target="_blank" href="topicedit.php?eid=' + row.ctx_id + '" title="Editor"><i class="bi bi-pencil-square mr-1" aria-hidden="true"></i>编辑</a>';
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
                { field: 'ctx_id', title: '操作', formatter: topicEditer }
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
