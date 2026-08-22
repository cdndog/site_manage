<div class="container app-page" id="main">
  <div class="card card-sops">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
      <h1 class="h5 mb-0 page-title"><i class="bi bi-cloud-upload mr-2" aria-hidden="true"></i>发布列表</h1>
      <div class="d-flex align-items-center" style="gap:8px;">
        <span class="badge badge-light">共 <?php echo (int)$total; ?> 条</span>
        <button class="btn btn-sm btn-outline-warning" type="button" id="importPublishBtn" title="从 seodata 同步至数据库"><i class="bi bi-cloud-download mr-1" aria-hidden="true"></i>导入数据</button>
      </div>
    </div>
    <div class="modal fade" id="publishImportModal" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
          <div class="modal-header"><h5 class="modal-title"><i class="bi bi-cloud-download text-warning mr-1"></i>导入数据</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
          <div class="modal-body">
            <p>将从 <strong>seodata/aigc_status.json</strong> 与 <strong>seodata/json/*.json</strong> 导入至 <code>aigc_status</code> 索引表。</p>
            <ul class="mb-0 small text-muted"><li>已存在按 <code>ctx_id</code> 去重；</li><li>缺失的 JSON 文件将跳过；</li><li>导入后自动刷新列表。</li></ul>
          </div>
          <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">取消</button><button type="button" class="btn btn-warning" id="publishImportExecuteBtn"><i class="bi bi-cloud-download mr-1"></i>确定导入</button></div>
        </div>
      </div>
    </div>
    <script>
      (function(){
        var btn=document.getElementById('importPublishBtn');
        var modal=document.getElementById('publishImportModal');
        var exec=document.getElementById('publishImportExecuteBtn');
        if(!btn||!modal||!exec) return;
        btn.addEventListener('click', function(){ window.jQuery(modal).modal('show'); });
        exec.addEventListener('click', function(){
          exec.disabled=true; exec.innerHTML='<i class="bi bi-arrow-repeat mr-1"></i>导入中…';
          var csrf='<?php echo e($csrf_token); ?>';
          var xhr=new XMLHttpRequest();
          xhr.open('POST','publish_list.php',true);
          xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
          xhr.onreadystatechange=function(){
            if(xhr.readyState!==4) return;
            exec.disabled=false; exec.innerHTML='<i class="bi bi-cloud-download mr-1"></i>确定导入';
            window.jQuery(modal).modal('hide');
            var msg='导入失败（HTTP '+xhr.status+'）', isError=true;
            if(xhr.status===200){
              var resp=null; try{resp=JSON.parse(xhr.responseText);}catch(e){}
              if(resp&&resp.rows&&resp.rows[0]){ msg=resp.rows[0].message||'导入完成'; isError=resp.rows[0].ok===false; }
            }
            sopsToast(msg, isError?'danger':'success');
            if(window.jQuery&&window.jQuery.fn&&window.jQuery.fn.bootstrapTable) jQuery('#publishTable').bootstrapTable('refresh');
            else window.location.reload();
          };
          xhr.send('action=import&csrf_token='+encodeURIComponent(csrf));
        });
      })();
    </script>
    <div class="card-body">
    <?php if ($total > 0): ?>
    <table id="publishTable" class="table table-hover table-sm">
      <thead>
        <tr>
          <th scope="col">发布时间</th>
          <th scope="col">关键词</th>
          <th scope="col">语言</th>
          <th scope="col">发布域名</th>
          <th scope="col">创建时间</th>
          <th scope="col">ctx_id</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
    <script>
      function publishTimeFormatter(value) {
        if (!value) return '';
        return String(value).replace('T',' ').replace('Z','');
      }
      function publishCtxFormatter(value) {
        if (!value) return '';
        var s = String(value);
        return '<span class="mono" title="'+s.replace(/"/g,'&quot;')+'">'+s.substring(0,12)+'...</span>';
      }
      function publishOpFormatter(value, row) {
        var id = String(row.ctx_id||'').replace(/'/g,'');
        var kw = String(row.keyword||row.ctx_id||'').replace(/'/g,'');
        var html = '<div class="d-inline-flex" style="gap:6px; white-space:nowrap;">';
        html += '<a class="btn btn-sm btn-outline-primary" href="publish_edit.php?eid='+encodeURIComponent(id)+'" title="文章修改"><i class="bi bi-file-earmark-text mr-1"></i>文章修改</a>';
        html += '<a class="btn btn-sm btn-outline-danger" href="javascript:void(0)" onclick="publishDelete(\''+id+'\',\''+kw+'\')" title="删除"><i class="bi bi-trash mr-1"></i>删除</a>';
        html += '</div>';
        return html;
      }
      var publishDeleteTarget=null;
      function publishDelete(ctxId,label){
        publishDeleteTarget=ctxId;
        document.getElementById('publishDeleteLabel').textContent=String(label||ctxId);
        window.jQuery('#publishDeleteModal').modal('show');
      }
      function publishDeleteExecute(){
        if(!publishDeleteTarget) return;
        var csrf='<?php echo e($csrf_token); ?>';
        window.jQuery('#publishDeleteModal').modal('hide');
        var xhr=new XMLHttpRequest();
        xhr.open('POST','publish_list.php',true);
        xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
        xhr.onreadystatechange=function(){
          if(xhr.readyState===4){
            if(xhr.status===200){
              var resp=null; try{resp=JSON.parse(xhr.responseText);}catch(e){}
              var ok=resp&&resp.rows&&resp.rows[0]&&resp.rows[0].ok;
              if(ok){ jQuery('#publishTable').bootstrapTable('refresh'); sopsToast('删除成功','success'); }
              else sopsToast((resp&&resp.rows&&resp.rows[0]&&resp.rows[0].message)||'删除失败','danger');
            } else sopsToast('删除失败 (HTTP '+xhr.status+')','danger');
          }
        };
        xhr.send('action=delete&ctx_id='+encodeURIComponent(publishDeleteTarget)+'&csrf_token='+encodeURIComponent(csrf));
      }
      (function () {
        function initPublishTable() {
          if (window.jQuery && window.jQuery.fn && window.jQuery.fn.bootstrapTable) {
            jQuery('#publishTable').bootstrapTable({
              locale: 'zh-CN',
              iconsPrefix: 'bi',
              url: 'publish_list.php',
              sidePagination: 'server',
              uniqueId: 'ctx_id',
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
                { field: 'publishAt', title: '发布时间', sortable: true, formatter: publishTimeFormatter },
                { field: 'keyword', title: '关键词', sortable: true },
                { field: 'lang', title: '语言', sortable: true, width: 70, align: 'center' },
                { field: 'pubdomain', title: '发布域名', sortable: true },
                { field: 'createAt', title: '创建时间', sortable: true, formatter: publishTimeFormatter },
                { field: 'ctx_id', title: 'ctx_id', sortable: true, formatter: publishCtxFormatter },
                { field: '_op', title: '操作', width: 200, align: 'center', formatter: publishOpFormatter, switchable: false, class: 'text-nowrap' }
              ],
              search: true,
              searchOnEnterKey: true,
              trimOnSearch: true,
              showToggle: true,
              showColumns: true,
              showRefresh: true,
              sortName: 'publishAt',
              sortOrder: 'desc',
              pagination: true,
              pageSize: 20,
              pageList: [10, 20, 50, 100],
              searchPlaceholder: '搜索关键词/域名/语言（回车触发）',
              formatShowingRows: function (pageFrom, pageTo, totalRows) {
                return '显示 ' + pageFrom + ' 到 ' + pageTo + ' 共 ' + totalRows + ' 条';
              },
              formatNoMatches: function () { return '没有找到匹配记录'; },
              formatSearch: function () { return '搜索'; },
              formatRefresh: function () { return '刷新'; },
              formatToggle: function () { return '切换视图'; },
              formatColumns: function () { return '列'; }
            });
            return true;
          }
          return false;
        }
        if (!initPublishTable()) {
          var timer = setInterval(function () {
            if (initPublishTable()) clearInterval(timer);
          }, 100);
          setTimeout(function () { clearInterval(timer); }, 10000);
        }
      })();
    </script>
    <?php else: ?>
    <div class="text-center py-5 text-muted">
      <i class="bi bi-inbox display-4 d-block mb-2"></i>
      暂无发布记录
    </div>
    <?php endif; ?>
    </div>
  </div>
</div>
<div class="modal fade" id="publishDeleteModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="bi bi-exclamation-triangle text-danger mr-1"></i>删除发布</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
      <div class="modal-body"><p>确定删除「<strong id="publishDeleteLabel"></strong>」？</p><p class="text-danger small mb-0">将同步删除 aigc_status 与 seodata/json 文件。</p></div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">取消</button><button type="button" class="btn btn-danger" onclick="publishDeleteExecute()">确定删除</button></div>
    </div>
  </div>
</div>
