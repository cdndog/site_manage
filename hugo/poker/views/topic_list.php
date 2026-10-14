<div class="container app-page" id="main">
  <div class="card card-sops">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
      <h1 class="h5 mb-0 page-title"><i class="bi bi-journal-richtext mr-2" aria-hidden="true"></i>话题列表</h1>
      <div class="d-flex align-items-center" style="gap:6px;">
        <a class="btn btn-sm btn-outline-secondary" href="topicops.php"><i class="bi bi-plus-circle mr-1" aria-hidden="true"></i>新增话题</a>
<?php if ($total > 0): ?>
        <button class="btn btn-sm btn-outline-secondary" type="button" id="topicBatchEditBtn" disabled title="把选中的多个话题统一修改为同一组字段值" onclick="topicBatchEditOpen()"><i class="bi bi-pencil-square mr-1" aria-hidden="true"></i>批量编辑<span id="topicBatchEditCount" class="topic-batch-count"></span></button>
        <button class="btn btn-sm btn-outline-danger" type="button" id="topicBatchDelBtn" disabled title="删除所有勾选的话题" onclick="topicBatchDeleteOpen()"><i class="bi bi-trash mr-1" aria-hidden="true"></i>批量删除<span id="topicBatchDelCount" class="topic-batch-count"></span></button>
<?php endif; ?>
        <a class="btn btn-sm btn-outline-secondary" href="topictable.php"><i class="bi bi-bar-chart-line mr-1" aria-hidden="true"></i>话题报表</a>
        <button class="btn btn-sm btn-outline-warning" type="button" id="importTopicBtn" title="从 topic_monitor_list.txt 恢复话题数据"><i class="bi bi-cloud-download mr-1" aria-hidden="true"></i>话题导入</button>
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
        // 降级：helper 缺失（js/sops-time.js 未加载）时显示原值，绝不抛错中断 bootstrap-table 行渲染
        return typeof window.sopsFormatTime === 'function' ? window.sopsFormatTime(value) : (value == null ? '' : String(value));
      }

      function topicEditer(value, row, index) {
        var html = '<a class="btn btn-sm btn-outline-primary" target="_blank" href="topicops.php?eid=' + row.ctx_id + '" title="Editor"><i class="bi bi-pencil-square mr-1" aria-hidden="true"></i>编辑</a>';
        html += '<a class="btn btn-sm btn-outline-danger ml-1" href="javascript:void(0)" onclick="topicDeleteConfirm(this, \'' + row.ctx_id + '\', \'' + String(row.keyword || row.git_name || '').replace(/'/g, '') + '\')"><i class="bi bi-trash mr-1" aria-hidden="true"></i>删除</a>';
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
            if (xhr.status === 401) { return; } // 登录态失效：由 sopsSessionExpired 统一提示并跳转
            if (xhr.status === 200) {
              var resp = sopsParseJson(xhr.responseText);
              var ok = resp && resp.rows && resp.rows[0] && resp.rows[0].ok;
              if (ok) {
                if (window.jQuery && window.jQuery.fn && window.jQuery.fn.bootstrapTable) {
                  jQuery('#table').bootstrapTable('refresh');
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

      // ===== 批量选择 / 批量删除 / 批量编辑 =====
      function topicSelectedIds() {
        if (!(window.jQuery && window.jQuery.fn && window.jQuery.fn.bootstrapTable)) { return []; }
        var rows = window.jQuery('#table').bootstrapTable('getSelections') || [];
        var ids = [];
        for (var i = 0; i < rows.length; i++) {
          if (rows[i] && rows[i].ctx_id) { ids.push(String(rows[i].ctx_id)); }
        }
        return ids;
      }

      // 勾选数变化时刷新头部按钮的可用状态与计数（onCheck/onUncheck/onCheckAll/onUncheckAll/onLoadSuccess 均回调）
      function topicBatchUpdateBar() {
        var n = topicSelectedIds().length;
        var del = document.getElementById('topicBatchDelBtn');
        var edit = document.getElementById('topicBatchEditBtn');
        if (del) { del.disabled = n === 0; }
        if (edit) { edit.disabled = n === 0; }
        var delCount = document.getElementById('topicBatchDelCount');
        if (delCount) { delCount.textContent = n > 0 ? ' (' + n + ')' : ''; }
        var editCount = document.getElementById('topicBatchEditCount');
        if (editCount) { editCount.textContent = n > 0 ? ' (' + n + ')' : ''; }
      }

      function topicRefreshTable() {
        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.bootstrapTable) {
          window.jQuery('#table').bootstrapTable('refresh');
        } else {
          window.location.reload();
        }
      }

      // 批量 POST 通道：统一 401/200/HTTP 错误处理，onDone 在请求结束时回调（用于恢复按钮态）
      function topicBatchPost(payload, errMsg, onDone) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'topiclist.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function () {
          if (xhr.readyState !== 4) { return; }
          if (xhr.status === 401) { if (onDone) { onDone(); } return; } // 登录态失效：由 sopsSessionExpired 统一提示并跳转
          var resp = xhr.status === 200 ? sopsParseJson(xhr.responseText) : null;
          var row = resp && resp.rows && resp.rows[0];
          if (row && row.ok) {
            sopsToast(row.message || '操作完成', 'success');
            topicRefreshTable();
          } else if (row && row.message) {
            sopsToast(row.message, 'danger');
          } else {
            sopsToast(errMsg + ' (HTTP ' + xhr.status + ')', 'danger');
          }
          if (onDone) { onDone(); }
        };
        xhr.send(payload);
      }

      function topicBatchDeleteOpen() {
        var ids = topicSelectedIds();
        if (ids.length === 0) { sopsToast('请先勾选要删除的话题', 'warning'); return; }
        var n = document.getElementById('topicBatchDeleteN');
        if (n) { n.textContent = String(ids.length); }
        window.jQuery('#topicBatchDeleteModal').modal('show');
      }

      function topicBatchDeleteExecute() {
        var ids = topicSelectedIds();
        if (ids.length === 0) { window.jQuery('#topicBatchDeleteModal').modal('hide'); return; }
        var btn = document.getElementById('topicBatchDeleteConfirmBtn');
        if (btn) { btn.disabled = true; }
        var csrf = '<?php echo e(isset($csrf_token) ? $csrf_token : ''); ?>';
        window.jQuery('#topicBatchDeleteModal').modal('hide');
        topicBatchPost(
          'action=delete-topics&ctx_ids=' + encodeURIComponent(ids.join(',')) + '&csrf_token=' + encodeURIComponent(csrf),
          '批量删除失败',
          function () { if (btn) { btn.disabled = false; } }
        );
      }

      function topicBatchEditOpen() {
        var ids = topicSelectedIds();
        if (ids.length === 0) { sopsToast('请先勾选要编辑的话题', 'warning'); return; }
        var n = document.getElementById('topicBatchEditN');
        if (n) { n.textContent = String(ids.length); }
        var form = document.getElementById('topicBatchEditForm');
        if (form) { form.reset(); }
        window.jQuery('#topicBatchEditModal').modal('show');
      }

      function topicBatchEditExecute() {
        var ids = topicSelectedIds();
        if (ids.length === 0) { window.jQuery('#topicBatchEditModal').modal('hide'); return; }
        var form = document.getElementById('topicBatchEditForm');
        if (!form) { return; }
        var parts = [];
        var changed = 0;
        for (var i = 0; i < form.elements.length; i++) {
          var el = form.elements[i];
          if (!el.name || el.type === 'submit' || el.type === 'button') { continue; }
          var v = String(el.value || '').trim();
          if (v === '') { continue; } // 空字段 = 不修改，避免误覆盖
          parts.push(encodeURIComponent(el.name) + '=' + encodeURIComponent(v));
          changed++;
        }
        if (changed === 0) { sopsToast('没有可修改的字段（所有值均为空）', 'warning'); return; }
        var btn = document.getElementById('topicBatchEditConfirmBtn');
        if (btn) { btn.disabled = true; }
        var csrf = '<?php echo e(isset($csrf_token) ? $csrf_token : ''); ?>';
        window.jQuery('#topicBatchEditModal').modal('hide');
        topicBatchPost(
          'action=update-topics&ctx_ids=' + encodeURIComponent(ids.join(',')) + '&csrf_token=' + encodeURIComponent(csrf) + '&' + parts.join('&'),
          '批量编辑失败',
          function () { if (btn) { btn.disabled = false; } }
        );
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
                { field: '_sel', title: '选择', checkbox: true, align: 'center', width: 40 },
                { field: 'ctx_id', title: '日期', sortable: true, formatter: topicTimeFormatter },
                { field: 'keyword', title: '文章与话题', sortable: true },
                { field: 'status', title: '状态', sortable: true },
                { field: 'git_name', title: '发布GIT', sortable: true },
                { field: 'domain', title: '发布域名', sortable: true },
                { field: 'pubdir', title: '发布目录', sortable: true },
                { field: 'lasttask', title: '发布时间', sortable: true },
                { field: 'lang', title: '语言', sortable: true },
                { field: 'geo', title: '区域', sortable: true },
                { field: '_op', title: '操作', class: 'op-col', width: 150, align: 'center', formatter: topicEditer }
              ],
              onCheck: topicBatchUpdateBar,
              onUncheck: topicBatchUpdateBar,
              onCheckAll: topicBatchUpdateBar,
              onUncheckAll: topicBatchUpdateBar,
              onLoadSuccess: topicBatchUpdateBar,
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
<div class="modal fade" id="topicBatchDeleteModal" tabindex="-1" role="dialog" aria-labelledby="topicBatchDeleteModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="topicBatchDeleteModalTitle"><i class="bi bi-exclamation-triangle text-danger mr-1" aria-hidden="true"></i>批量删除话题</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <p>确定删除已勾选的 <strong id="topicBatchDeleteN">0</strong> 个话题吗？</p>
        <p class="text-danger small mb-0">删除后这些话题将从话题列表移除并重新导出配置，此操作不可恢复。</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">取消</button>
        <button type="button" class="btn btn-danger" id="topicBatchDeleteConfirmBtn" onclick="topicBatchDeleteExecute()"><i class="bi bi-trash mr-1" aria-hidden="true"></i>确定删除</button>
      </div>
    </div>
  </div>
</div>
<?php
$batchSelectFields = [
    'status' => isset($config['statuses']) && is_array($config['statuses']) ? $config['statuses'] : [],
    'pubdir' => isset($config['pubdir']) && is_array($config['pubdir'])
        ? array_combine(array_map('strval', $config['pubdir']), array_map('strval', $config['pubdir'])) : [],
    'lang' => isset($config['languages']) && is_array($config['languages']) ? $config['languages'] : [],
    'geo' => isset($config['countries']) && is_array($config['countries']) ? $config['countries'] : [],
];
$batchSelectLabels = ['status' => '状态（status）', 'pubdir' => '发布目录（pubdir）', 'lang' => '站点语言（lang）', 'geo' => '国家地区（geo）'];
$batchTextFields = [
    'keyword' => ['label' => '文章与话题（keyword）', 'placeholder' => '如 poker strategy guide'],
    'git_name' => ['label' => '发布GIT（git_name）', 'placeholder' => '如 myrepo'],
    'domain' => ['label' => '发布域名（domain）', 'placeholder' => '如 example.com'],
    'lasttask' => ['label' => '发布时间（lasttask）', 'placeholder' => '如 20261013'],
];
?>
<div class="modal fade" id="topicBatchEditModal" tabindex="-1" role="dialog" aria-labelledby="topicBatchEditModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="topicBatchEditModalTitle"><i class="bi bi-pencil-square text-primary mr-1" aria-hidden="true"></i>批量编辑话题</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <p class="mb-2">把下面<b>非空</b>字段统一应用到已勾选的 <strong id="topicBatchEditN">0</strong> 个话题；<b>留空的字段保持原值不变</b>。</p>
        <form id="topicBatchEditForm" onsubmit="return false;">
          <div class="row">
            <?php foreach ($batchTextFields as $fieldName => $meta): ?>
            <div class="col-md-6 form-group">
              <label for="be_<?php echo e($fieldName); ?>"><?php echo e($meta['label']); ?></label>
              <input type="text" class="form-control" id="be_<?php echo e($fieldName); ?>" name="<?php echo e($fieldName); ?>" placeholder="<?php echo e($meta['placeholder']); ?>">
            </div>
            <?php endforeach; ?>
            <?php foreach ($batchSelectFields as $fieldName => $options): ?>
            <div class="col-md-6 form-group">
              <label for="be_<?php echo e($fieldName); ?>"><?php echo e(isset($batchSelectLabels[$fieldName]) ? $batchSelectLabels[$fieldName] : $fieldName); ?></label>
              <select class="form-control" id="be_<?php echo e($fieldName); ?>" name="<?php echo e($fieldName); ?>">
                <option value="">— 不修改 —</option>
                <?php foreach ($options as $optLabel => $optValue): ?>
                <option value="<?php echo e($optValue); ?>"><?php echo e($optLabel); ?><?php echo (string)$optLabel === (string)$optValue ? '' : ' · ' . e($optValue); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endforeach; ?>
          </div>
        </form>
        <p class="text-muted small mb-0">字段值内的竖线会被自动剔除（与录入页一致），以保证 topic_monitor_list.txt 导出不被破坏。</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">取消</button>
        <button type="button" class="btn btn-primary" id="topicBatchEditConfirmBtn" onclick="topicBatchEditExecute()"><i class="bi bi-check2-circle mr-1" aria-hidden="true"></i>应用到选中话题</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<div class="modal fade" id="topicImportModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="bi bi-cloud-download text-warning mr-1"></i>话题导入</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
      <div class="modal-body">
        <p>将从 topic_monitor_list.txt 恢复话题数据到数据库，已存在的记录将跳过。</p>
        <p class="text-muted small mb-0">导入后自动刷新列表。</p>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">取消</button><button type="button" class="btn btn-warning" id="topicImportExecuteBtn"><i class="bi bi-cloud-download mr-1"></i>确定导入</button></div>
    </div>
  </div>
</div>
<script>
(function(){
  var btn=document.getElementById('importTopicBtn');
  var modal=document.getElementById('topicImportModal');
  var exec=document.getElementById('topicImportExecuteBtn');
  if(!btn||!modal||!exec) return;
  btn.addEventListener('click',function(){window.jQuery(modal).modal('show');});
  exec.addEventListener('click',function(){
    exec.disabled=true;exec.innerHTML='<i class="bi bi-arrow-repeat mr-1"></i>导入中…';
    var csrf='<?php echo e(isset($csrf_token) ? $csrf_token : ""); ?>';
    var xhr=new XMLHttpRequest();
    xhr.open('POST','topiclist.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onreadystatechange=function(){
      if(xhr.readyState!==4) return;
      if(xhr.status===401) return; // 登录态失效：由 sopsSessionExpired 统一提示并跳转
      exec.disabled=false;exec.innerHTML='<i class="bi bi-cloud-download mr-1"></i>确定导入';
      window.jQuery(modal).modal('hide');
      var msg='导入失败',isError=true;
      if(xhr.status===200){var resp=sopsParseJson(xhr.responseText);
        if(resp&&resp.rows&&resp.rows[0]){msg=resp.rows[0].message||'导入完成';isError=resp.rows[0].ok===false;}
      }
      sopsToast(msg,isError?'danger':'success');
      if(window.jQuery&&window.jQuery.fn&&window.jQuery.fn.bootstrapTable) jQuery('#table').bootstrapTable('refresh');
    };
    xhr.send('action=import-topic&csrf_token='+encodeURIComponent(csrf));
  });
})();
</script>
