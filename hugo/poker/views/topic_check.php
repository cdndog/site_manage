<div class="container app-page" id="main">
  <div class="card card-sops">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
      <h1 class="h5 mb-0 page-title"><i class="bi bi-exclamation-triangle mr-2" aria-hidden="true"></i>数据排查</h1>
      <div>
        <a class="btn btn-sm btn-outline-secondary" href="topiclist.php"><i class="bi bi-journal-richtext mr-1" aria-hidden="true"></i>话题列表</a>
        <a class="btn btn-sm btn-outline-secondary" href="topictable.php"><i class="bi bi-bar-chart-line mr-1" aria-hidden="true"></i>话题报表</a>
        <a class="btn btn-sm btn-outline-secondary" href="topicops.php"><i class="bi bi-plus-circle mr-1" aria-hidden="true"></i>新增话题</a>
      </div>
    </div>
    <div class="card-body">
      <div class="alert alert-warning py-2" role="alert">
        <i class="bi bi-info-circle mr-1" aria-hidden="true"></i>
        仅显示发布域名在站点列表（siteops）的 GIT 名称或发布域名中均不存在的异常话题。每页最多显示 1000 条。
      </div>
      <form class="form-row align-items-end mb-3" id="checkFilterForm" onsubmit="return topicCheckApplyFilter(event)">
        <div class="col-sm-4 col-lg-3 mb-2">
          <label class="small mb-1" for="checkDomain">发布域名 <span class="text-muted">（模糊匹配）</span></label>
          <input type="text" class="form-control form-control-sm" id="checkDomain" name="domain" value="<?php echo e(isset($domain) ? $domain : ''); ?>" placeholder="例如 nicecalc.com" autocomplete="off">
        </div>
        <div class="col-sm-4 col-lg-3 mb-2">
          <label class="small mb-1" for="checkGitName">发布GIT <span class="text-muted">（模糊匹配）</span></label>
          <input type="text" class="form-control form-control-sm" id="checkGitName" name="git_name" value="<?php echo e(isset($git_name) ? $git_name : ''); ?>" placeholder="例如 nicecalc.com" autocomplete="off">
        </div>
        <div class="col-sm-4 col-lg-6 mb-2 d-flex flex-wrap align-items-center" style="gap:6px;">
          <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel mr-1" aria-hidden="true"></i>筛选</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="topicCheckResetFilter()"><i class="bi bi-arrow-counterclockwise mr-1" aria-hidden="true"></i>重置</button>
<?php if (!empty($can_manage)): ?>
          <button type="button" class="btn btn-sm btn-danger ml-auto" id="checkDeleteBtn" onclick="topicCheckDelete()"><i class="bi bi-trash mr-1" aria-hidden="true"></i>一键删除异常话题</button>
<?php endif; ?>
        </div>
      </form>
      <p class="small text-muted mb-2">域名/GIT 为模糊匹配（忽略大小写与首尾空格）。删除仅作用于上述过滤条件命中的异常话题；不填写过滤条件将删除全部异常话题，操作不可恢复。</p>

    <table id="table" class="table table-hover table-sm">
      <thead>
        <tr>
          <th scope="col">日期</th>
          <th scope="col">上下文ID</th>
          <th scope="col">关键词</th>
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
      function topicCheckTimeFormatter(value) {
        return typeof window.sopsFormatTime === 'function' ? window.sopsFormatTime(value) : (value == null ? '' : String(value));
      }

      function topicCheckEditer(value, row, index) {
        return '<a class="btn btn-sm btn-outline-primary" target="_blank" href="topicops.php?eid=' + encodeURIComponent(row.ctx_id || '') + '" title="编辑"><i class="bi bi-pencil-square mr-1" aria-hidden="true"></i>编辑</a>';
      }

      function topicCheckFilters() {
        var domain = document.getElementById('checkDomain');
        var gitName = document.getElementById('checkGitName');
        return {
          domain: domain ? domain.value.trim() : '',
          git_name: gitName ? gitName.value.trim() : ''
        };
      }

      function topicCheckRefresh() {
        if (!(window.jQuery && window.jQuery.fn && window.jQuery.fn.bootstrapTable)) {
          return;
        }
        jQuery('#table').bootstrapTable('refreshOptions', { pageNumber: 1 });
        jQuery('#table').bootstrapTable('refresh');
      }

      function topicCheckApplyFilter(event) {
        if (event) {
          event.preventDefault();
        }
        topicCheckRefresh();
        return false;
      }

      function topicCheckResetFilter() {
        var domain = document.getElementById('checkDomain');
        var gitName = document.getElementById('checkGitName');
        if (domain) { domain.value = ''; }
        if (gitName) { gitName.value = ''; }
        topicCheckRefresh();
      }

      var topicCheckPendingFilters = null;

      function topicCheckToast(message, type) {
        window.sopsToast(message, type || 'success');
      }

      function topicCheckDelete() {
        var filters = topicCheckFilters();
        var scope = [];
        if (filters.domain) { scope.push('域名=' + filters.domain); }
        if (filters.git_name) { scope.push('GIT=' + filters.git_name); }
        var label = scope.length > 0 ? scope.join('，') : '全部异常话题';
        topicCheckPendingFilters = filters;
        var labelEl = document.getElementById('topicCheckDeleteLabel');
        if (labelEl) {
          labelEl.textContent = label;
        }
        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.modal) {
          window.jQuery('#topicCheckDeleteModal').modal('show');
        } else if (typeof window.sopsConfirm === 'function') {
          window.sopsConfirm('确定删除「' + label + '」的异常话题吗？删除后不可恢复。', topicCheckDeleteExecute);
        }
      }

      function topicCheckDeleteExecute() {
        var filters = topicCheckPendingFilters;
        if (!filters) {
          return;
        }
        topicCheckPendingFilters = null;
        var btn = document.getElementById('topicCheckDeleteConfirmBtn');
        var pageBtn = document.getElementById('checkDeleteBtn');
        if (btn) {
          btn.disabled = true;
        }
        if (pageBtn) {
          pageBtn.disabled = true;
        }
        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.modal) {
          window.jQuery('#topicCheckDeleteModal').modal('hide');
        }
        var csrf = '<?php echo e(isset($csrf_token) ? $csrf_token : ''); ?>';
        var body = 'action=delete-missing-topics'
          + '&domain=' + encodeURIComponent(filters.domain)
          + '&git_name=' + encodeURIComponent(filters.git_name)
          + '&csrf_token=' + encodeURIComponent(csrf);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'topiccheck.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function () {
          if (xhr.readyState !== 4) {
            return;
          }
          if (btn) {
            btn.disabled = false;
          }
          if (pageBtn) {
            pageBtn.disabled = false;
          }
          if (xhr.status === 401) {
            return;
          }
          var resp = null;
          try { resp = JSON.parse(xhr.responseText); } catch (e) {}
          var row = resp && resp.rows && resp.rows[0];
          if (row && row.ok) {
            topicCheckToast(row.message || '删除完成', 'success');
            topicCheckRefresh();
          } else {
            topicCheckToast((row && row.message) ? row.message : '删除失败', 'danger');
          }
        };
        xhr.send(body);
      }

      (function () {
        function initTopicCheckTable() {
          if (window.jQuery && window.jQuery.fn && window.jQuery.fn.bootstrapTable) {
            jQuery('#table').bootstrapTable({
              locale: 'zh-CN',
              iconsPrefix: 'bi',
              url: 'topiccheck.php',
              cache: false,
              sidePagination: 'server',
              totalField: 'total',
              dataField: 'rows',
              queryParams: function (params) {
                var filters = topicCheckFilters();
                return {
                  format: 'json',
                  _t: Date.now(),
                  offset: params.offset,
                  limit: params.limit,
                  search: params.search || '',
                  sort: params.sort,
                  order: params.order,
                  domain: filters.domain,
                  git_name: filters.git_name
                };
              },
              columns: [
                { field: 'time', title: '日期', sortable: true, formatter: topicCheckTimeFormatter },
                { field: 'ctx_id', title: '上下文ID', sortable: true },
                { field: 'keyword', title: '关键词', sortable: true },
                { field: 'status', title: '状态', sortable: true },
                { field: 'git_name', title: '发布GIT', sortable: true },
                { field: 'domain', title: '发布域名', sortable: true },
                { field: 'pubdir', title: '发布目录', sortable: true },
                { field: 'lang', title: '语言', sortable: true },
                { field: 'geo', title: '区域', sortable: true },
                { field: 'lasttask', title: '上次任务', sortable: true },
                { field: '_op', title: '操作', class: 'op-col', width: 100, align: 'center', formatter: topicCheckEditer }
              ],
              search: true,
              showToggle: true,
              showColumns: true,
              showRefresh: true,
              sortName: 'ctx_id',
              sortOrder: 'desc',
              pagination: true,
              pageSize: 20,
              pageList: [10, 20, 50, 100, 200, 500, 1000],
              searchPlaceholder: '搜索关键词、域名、GIT、状态等',
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
        if (!initTopicCheckTable()) {
          var timer = setInterval(function () {
            if (initTopicCheckTable()) {
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

<?php if (!empty($can_manage)): ?>
<div class="modal fade" id="topicCheckDeleteModal" tabindex="-1" role="dialog" aria-labelledby="topicCheckDeleteModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="topicCheckDeleteModalTitle"><i class="bi bi-exclamation-triangle text-danger mr-1" aria-hidden="true"></i>删除异常话题</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <p>确定删除「<strong id="topicCheckDeleteLabel"></strong>」的异常话题吗？</p>
        <p class="text-danger small mb-0">删除后不可恢复。</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">取消</button>
        <button type="button" class="btn btn-danger" id="topicCheckDeleteConfirmBtn" onclick="topicCheckDeleteExecute()"><i class="bi bi-trash mr-1" aria-hidden="true"></i>确定删除</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
