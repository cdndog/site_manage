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
              if (isset($column['formatter'])) {
                  $item['formatter'] = $column['formatter'];
              }
              $out[] = json_encode($item, JSON_UNESCAPED_UNICODE);
          }
          echo implode(',', $out);
      ?>];

      function siteEditer(value, row, index) {
        return '<a class="btn btn-sm btn-outline-primary" target="_blank" href="siteops.php?eid=' + row.ctx_id + '" title="Editor"><i class="bi bi-pencil-square mr-1" aria-hidden="true"></i>编辑</a>';
      }

      function keywordEditer(value, row, index) {
        return '<a class="btn btn-sm btn-outline-primary" target="_blank" href="keywordops.php?eid=' + row.ctx_id + '" title="Editor"><i class="bi bi-pencil-square mr-1" aria-hidden="true"></i>编辑</a>';
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