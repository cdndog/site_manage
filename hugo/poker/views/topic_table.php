<div class="container app-page" id="main">
  <div class="card card-sops">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
      <h1 class="h5 mb-0 page-title"><i class="bi bi-bar-chart-line mr-2" aria-hidden="true"></i>话题报表</h1>
      <div>
        <a class="btn btn-sm btn-outline-secondary" href="topicops.php"><i class="bi bi-plus-circle mr-1" aria-hidden="true"></i>新增话题</a>
        <a class="btn btn-sm btn-outline-secondary" href="topiclist.php"><i class="bi bi-journal-richtext mr-1" aria-hidden="true"></i>话题列表</a>
      </div>
    </div>
    <div class="card-body">
      <div class="row mb-3">
        <div class="col-sm-3 mb-2">
          <div class="card text-center">
            <div class="card-body py-2">
              <div class="text-muted small">话题总数</div>
              <div class="h4 mb-0"><?php echo e((string)$by_status['total']); ?></div>
            </div>
          </div>
        </div>
        <div class="col-sm-3 mb-2">
          <div class="card text-center">
            <div class="card-body py-2">
              <div class="text-muted small">AI完成 (aidone)</div>
              <div class="h4 mb-0 text-success"><?php echo e((string)$by_status['aidone']); ?></div>
            </div>
          </div>
        </div>
        <div class="col-sm-3 mb-2">
          <div class="card text-center">
            <div class="card-body py-2">
              <div class="text-muted small">启用 (enable)</div>
              <div class="h4 mb-0 text-primary"><?php echo e((string)$by_status['enable']); ?></div>
            </div>
          </div>
        </div>
        <div class="col-sm-3 mb-2">
          <div class="card text-center">
            <div class="card-body py-2">
              <div class="text-muted small">其他</div>
              <div class="h4 mb-0 text-muted"><?php echo e((string)$by_status['other']); ?></div>
            </div>
          </div>
        </div>
      </div>

      <ul class="nav nav-tabs mb-3" id="topicReportTabs" role="tablist">
        <li class="nav-item">
          <a class="nav-link active" id="tab-date-tab" data-toggle="tab" href="#tab-date" role="tab" aria-controls="tab-date" aria-selected="true"><i class="bi bi-calendar3 mr-1" aria-hidden="true"></i>按发布日期</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" id="tab-domain-tab" data-toggle="tab" href="#tab-domain" role="tab" aria-controls="tab-domain" aria-selected="false"><i class="bi bi-globe mr-1" aria-hidden="true"></i>按发布域名</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" id="tab-detail-tab" data-toggle="tab" href="#tab-detail" role="tab" aria-controls="tab-detail" aria-selected="false"><i class="bi bi-table mr-1" aria-hidden="true"></i>话题明细</a>
        </li>
      </ul>

      <div class="tab-content" id="topicReportTabsContent">
        <div class="tab-pane fade show active" id="tab-date" role="tabpanel" aria-labelledby="tab-date-tab">
          <div class="card">
            <div class="card-header py-2">按发布日期统计</div>
            <table id="table-date" class="table table-sm table-hover mb-0">
              <thead>
                <tr>
                  <th scope="col">日期</th>
                  <th scope="col">总数</th>
                  <th scope="col">aidone</th>
                  <th scope="col">enable</th>
                  <th scope="col">其他</th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>
        </div>
        <div class="tab-pane fade" id="tab-domain" role="tabpanel" aria-labelledby="tab-domain-tab">
          <div class="card">
            <div class="card-header py-2">按发布域名统计</div>
            <table id="table-domain" class="table table-sm table-hover mb-0">
              <thead>
                <tr>
                  <th scope="col">域名</th>
                  <th scope="col">总数</th>
                  <th scope="col">aidone</th>
                  <th scope="col">enable</th>
                  <th scope="col">其他</th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>
        </div>
        <div class="tab-pane fade" id="tab-detail" role="tabpanel" aria-labelledby="tab-detail-tab">
            <table id="table-detail" class="table table-hover table-sm">
              <thead>
                <tr>
                  <th scope="col">ID</th>
                  <th scope="col">话题</th>
                  <th scope="col">发布域名</th>
                  <th scope="col">发布GIT</th>
                  <th scope="col">发布目录</th>
                  <th scope="col">状态</th>
                  <th scope="col">语言</th>
                  <th scope="col">区域</th>
                  <th scope="col">最近任务</th>
                  <th scope="col">创建时间</th>
                  <th scope="col">操作</th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>
    <script>
      function topicReportEditer(value, row, index) {
        return '<a class="btn btn-sm btn-outline-primary" target="_blank" href="topicops.php?eid=' + row.ctx_id + '" title="Editor"><i class="bi bi-pencil-square mr-1" aria-hidden="true"></i>编辑</a>';
      }

      function topicRateFormatter(value, row, index) {
        var rate = parseInt(value, 10);
        if (isNaN(rate)) { rate = 0; }
        if (rate < 0) { rate = 0; }
        if (rate > 100) { rate = 100; }
        var color = rate >= 80 ? 'bg-success' : (rate >= 50 ? 'bg-warning' : 'bg-danger');
        return '<div class="d-flex align-items-center justify-content-center">'
          + '<div class="progress mr-2" style="flex:0 0 96px;width:96px;height:14px;">'
          + '<div class="progress-bar ' + color + '" style="width:' + rate + '%"></div>'
          + '</div>'
          + '<span class="small text-muted" style="flex:0 0 34px;text-align:left;">' + rate + '%</span>'
          + '</div>';
      }

      function initTopicReportTable(tableId, view, columns, sortName, sortOrder) {
        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.bootstrapTable) {
          jQuery(tableId).bootstrapTable({
            locale: 'zh-CN',
            iconsPrefix: 'bi',
            url: 'topictable.php',
            sidePagination: 'server',
            totalField: 'total',
            dataField: 'rows',
            queryParams: function (params) {
              return {
                format: 'json',
                view: view,
                offset: params.offset,
                limit: params.limit,
                search: params.search || '',
                sort: params.sort,
                order: params.order
              };
            },
            columns: columns,
            search: true,
            showToggle: true,
            showColumns: true,
            showRefresh: true,
            sortName: sortName,
            sortOrder: sortOrder,
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

      (function () {
        var tables = [
          ['#table-date', 'date', [
            { field: 'date', title: '日期', sortable: true },
            { field: 'total', title: '总数', sortable: true, align: 'right' },
            { field: 'aidone', title: 'aidone', sortable: true, align: 'right' },
            { field: 'enable', title: 'enable', sortable: true, align: 'right' },
            { field: 'other', title: '其他', sortable: true, align: 'right' },
            { field: 'rate', title: '完成率', sortable: true, align: 'center', formatter: topicRateFormatter }
          ], 'date', 'desc'],
          ['#table-domain', 'domain', [
            { field: 'domain', title: '域名', sortable: true },
            { field: 'total', title: '总数', sortable: true, align: 'right' },
            { field: 'aidone', title: 'aidone', sortable: true, align: 'right' },
            { field: 'enable', title: 'enable', sortable: true, align: 'right' },
            { field: 'other', title: '其他', sortable: true, align: 'right' },
            { field: 'rate', title: '完成率', sortable: true, align: 'center', formatter: topicRateFormatter }
          ], 'total', 'desc'],
          ['#table-detail', 'detail', [
            { field: 'id', title: 'ID', sortable: true },
            { field: 'keyword', title: '话题', sortable: true },
            { field: 'domain', title: '发布域名', sortable: true },
            { field: 'git_name', title: '发布GIT', sortable: true },
            { field: 'pubdir', title: '发布目录', sortable: true },
            { field: 'status', title: '状态', sortable: true },
            { field: 'lang', title: '语言', sortable: true },
            { field: 'geo', title: '区域', sortable: true },
            { field: 'lasttask', title: '最近任务', sortable: true },
            { field: 'time', title: '创建时间', sortable: true },
            { field: 'ctx_id', title: '操作', formatter: topicReportEditer }
          ], 'id', 'desc']
        ];
        var ready = false;
        function initAllTables() {
          if (!ready) {
            var allReady = true;
            for (var i = 0; i < tables.length; i++) {
              if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.bootstrapTable) {
                allReady = false;
                break;
              }
              initTopicReportTable(tables[i][0], tables[i][1], tables[i][2], tables[i][3], tables[i][4]);
            }
            if (allReady) {
              ready = true;
              jQuery('#topicReportTabs').on('shown.bs.tab', function (e) {
                var target = jQuery(e.target).attr('href');
                if (target === '#tab-date') {
                  jQuery('#table-date').bootstrapTable('resetView');
                } else if (target === '#tab-domain') {
                  jQuery('#table-domain').bootstrapTable('resetView');
                } else if (target === '#tab-detail') {
                  jQuery('#table-detail').bootstrapTable('resetView');
                }
              });
            }
          }
          return ready;
        }
        if (!initAllTables()) {
          var timer = setInterval(function () {
            if (initAllTables()) {
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
  </div>
</div>
