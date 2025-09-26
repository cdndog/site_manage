<!DOCTYPE html>
<html lang="en">
<head>
    <title>海外数据报表</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css"> -->
    <!-- <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script> -->
    <!-- <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script> -->
    <script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <script src="https://cdn.bootcdn.net/ajax/libs/twitter-bootstrap/3.4.1/js/bootstrap.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.1/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-iYQeCzEYFbKjA/T2uDLTpkwGzCiq6soy8tYaI1GyVh/UjpbCx/TYkiZhlZB6+fzT" crossorigin="anonymous">
    <link rel="stylesheet" href="https://kit-free.fontawesome.com/releases/latest/css/free.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js" integrity="sha384-oBqDVmMz9ATKxIep9tiCxS/Z9fNfEXiDAYTujMAeBAsjFuCZSmKbSSUnQlmh/jp3" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.1/dist/js/bootstrap.min.js" integrity="sha384-7VPbUDkoPSGFnVtYi0QogXtr74QeVeeIs99Qfg5YCF+TidwNdjvaKZX19NZ/e6oz" crossorigin="anonymous"></script>
    <!-- export table  -->
    <link rel="stylesheet" href="https://kit-free.fontawesome.com/releases/latest/css/free.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://unpkg.com/bootstrap-table@1.21.0/dist/bootstrap-table.min.css">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/bootstrap-table@1.21.0/dist/bootstrap-table.min.js"></script>
    <script src="https://unpkg.com/bootstrap-table@1.21.0/dist/extensions/export/bootstrap-table-export.min.js"></script>
    <script src="https://unpkg.com/tableexport.jquery.plugin/tableExport.min.js"></script>
    <script src="https://unpkg.com/bootstrap-table@1.21.0/dist/bootstrap-table-locale-all.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<div class="container">
    <nav class="navbar navbar-expand-lg navbar-light bg-light">
      <div class="container-fluid">
        <!-- <a class="navbar-brand" href="<?php echo $_SERVER['PHP_SELF'];?>">首页</a> -->
        <a class="navbar-brand" href="#">首页</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
          <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
          <ul class="navbar-nav">
            <li class="nav-item">
              <a class="nav-link" href="seo_report.php?reporttype=wordlist">全部关键词</a>
            </li>  
            <li class="nav-item">
              <a class="nav-link" href="seo_report.php?reporttype=relateword">关联关键词</a>
            </li>          
            <li class="nav-item">
              <a class="nav-link" href="keywordops.php">新增关键词</a>
            </li>
            <li class="nav-item">
              <a class="nav-link alert-primary" href="seo_report.php?reporttype=sitelist">站点数据</a>
            </li>  
            <!-- <li class="nav-item">
              <a class="nav-link" href="seo_report.php?reporttype=ytview">YouTube数据</a>
            </li> -->
            <!-- <li class="nav-item">
              <a class="nav-link" href="seo_report.php?reporttype=tkview">TikTok数据</a>
            </li> -->
            <!-- <li class="nav-item">
              <a class="nav-link" href="seo_report.php?reporttype=blcreated">Backlink数据</a>
            </li> -->
            <!-- <li class="nav-item">
              <a class="nav-link alert-primary" href="/game_report.php?reporttype=kwaiview">Kwai数据</a>
            </li> -->
          </ul>
        </div>
      </div>
    </nav>
</div>
<?php 

date_default_timezone_set("Asia/Shanghai");

// ini_set("display_errors", 0);
// error_reporting(E_ALL ^ E_NOTICE);
// error_reporting(E_ALL ^ E_WARNING);

function getSeoRelateWordData ($logfile) {
    $tableData = file($logfile);
    // var_dump($loginlog);
    $output = array();
    $tableTitle = array('createtime','lang','mainword','subword','domain');
    foreach ($tableData  as $dataKey => $dataLine) {
        if ( $dataKey == 0 && preg_grep('#'. preg_quote("createtime|lang") .'#i', [trim($dataLine)])) {
            $tableTitle = explode('|', trim($dataLine));
        } else {
            $output[] = array_combine($tableTitle, explode('|', trim($dataLine)));
        }  
    }
    return json_encode($output);
}

function getSeoAllKeywordData ($logfile, $appendTitle = array('lasttask')) {
    $tableData = file($logfile);
    // var_dump($loginlog);
    $output = array();
    $tableTitle = array('createtime','mainword','status','gitname','pubdir','lang','jsondata');

    // $appendTitle = array('lasttask');
    foreach ($tableData  as $dataKey => $dataLine) {
        if ( $dataKey == 0 && preg_grep('#'. preg_quote("createtime|mainword") .'#i', [trim($dataLine)])) {
            $tableTitle = explode('|', trim($dataLine));
        } else {
            $table = explode('|', trim($dataLine));
            if (count($tableTitle) == count($table)) {
                $currentTableTitle = $tableTitle;
                $tmpTableData = array_combine($tableTitle, $table);
                $appendTableData = json_decode($tmpTableData['jsondata'],true);
                foreach ($appendTitle as $newTitle) {
                    if (!empty($appendTableData[$newTitle])) {
                        $tmpTableData[$newTitle] = $appendTableData[$newTitle];
                        $currentTableTitle[$newTitle] = $newTitle;
                    } else {
                        $tmpTableData[$newTitle] = '$appendTableData[$newTitle]';
                        $currentTableTitle[$newTitle] = $newTitle;
                    }
                }
                $output[] = array_combine($currentTableTitle, $tmpTableData);
            }
            
        }  
    }
    return json_encode($output);
}

function getTextToTableData ($logfile, $headerTitle) {
    $tableData = file($logfile);
    // var_dump($loginlog);
    $output = array();
    $tableTitle = $headerTitle;
    $headerString = implode("|", $tableTitle);
    // $tableTitle = array('createtime','mainword','status','gitname','pubdir','lang','jsondata');
    foreach ($tableData  as $dataKey => $dataLine) {
        if ( $dataKey == 0 && preg_grep('#'. preg_quote($headerString) .'#i', [trim($dataLine)])) {
            $tableTitle = explode('|', trim($dataLine));
        } else {
            $table = explode('|', trim($dataLine));
            if (count($tableTitle) == count($table)) {
              $output[] = array_combine($tableTitle, $table);
            }
        }  
    }
    return json_encode($output);
}

?>
<div class="container">
    <div class="alert alert-primary" role="alert">
      只显示最近一周数据。
    </div>
<hr>

<?php if ($_SERVER["REQUEST_METHOD"] == "GET") {

if (!empty($_GET['reporttype'])) {
    $report = $_GET['reporttype'];
    switch ($report) {
      case 'relateword':
        $headerTitle = array('createtime','subword','status','domain','pubdir','lang','mainword');
        $logfile = 'table_relatedword.txt';
        $reportData = getTextToTableData ($logfile, $headerTitle);
        $sortCol = 'createtime';
        break;

      case 'wordlist':
        $reportData = getSeoAllKeywordData('keyword_monitor_list.txt');
        $sortCol = 'createtime';
        break;

      case 'sitelist':
        $headerTitle = array('createtime', 'gitname', 'status', 'themetype', 'lang', 'domain', 'themename', 'keyword', 'sitetitle', 'sitedesc', 'json');
        $logfile = 'siteops_setting.txt';
        $reportData = getTextToTableData ($logfile, $headerTitle);
        $sortCol = 'createtime';
        break;

      default:
        
        break;
    }

};
// var_dump($sns_search_result);
?>
<?php if ( !empty($reportData) ) { ?>
<div>
  <div id="toolbar" class="select">
    <select class="form-control">
      <option value="">导出当前页</option>
      <option value="all">导出所有项</option>
      <option value="selected">导出已选项</option>
    </select>
  </div>

 <table
  id="table" 
  data-toolbar="#toolbar" 
  data-show-toggle="true" 
  data-show-fullscreen="true" 
  data-show-columns="true" 
  data-search="true" 
  data-show-refresh="true" 
  data-show-fullscreen="true" 
  data-show-export="true" 
  data-click-to-select="true" 
  data-detail-view="true" 
  data-detail-formatter="detailFormatter" 
  data-id-field="id" 
  data-response-handler="responseHandler" 
  data-sort-name="<?php echo $sortCol; ?>"
  data-sort-order="desc"
  data-pagination="true"
  class="table" >
</div>

<script>
  var $table = $('#table')
  var $remove = $('#remove')
  var selections = []

  function getIdSelections() {
    return $.map($table.bootstrapTable('getSelections'), function (row) {
      return row.id
    })
  }

  function responseHandler(res) {
    $.each(res.rows, function (i, row) {
      row.state = $.inArray(row.id, selections) !== -1
    })
    return res
  }

  function detailFormatter(index, row) {
    var html = []
    $.each(row, function (key, value) {
      html.push('<p><b>' + key + ':</b> ' + value + '</p>')
    })
    return html.join('')
  }

  function timeFormatter(value) {
    // Check if value is numeric and 8 characters long (YYYYMMDD)
    if (/^(19|20)\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])$/.test(value)) {
      const year = parseInt(value.slice(0, 4), 10);
      const month = parseInt(value.slice(4, 6), 10) - 1; // JS month is 0-indexed
      const day = parseInt(value.slice(6, 8), 10);
      const date = new Date(year, month, day);
      return date.toLocaleDateString(); // Format date to locale string
    } else {
      const date = new Date(value.slice(0, 10) * 1000); // Multiply by 1000 to convert to milliseconds

      // Format the date into a human-readable string
      const formattedDate = date.toLocaleString();

      return formattedDate 
    }

    // If value does not match above formats, return as is (hex or unknown)
    return value;
  }

  function operateFormatter(value, row, index) {
    return [
      '<a class="remove" href="javascript:void(0)" title="Remove">',
      '<i class="fa fa-trash"></i>',
      '</a>'
    ].join('')
  }

  function siteEditer(value) {
    return [
      '<a class="edit" target="_blank" href="siteops.php?eid='+ value +'" title="Editor">',
      '<i class="fa fa-edit"></i>',
      '</a>'
    ].join('')
  }

  window.operateEvents = {
    'click .remove': function (e, value, row, index) {
      $table.bootstrapTable('remove', {
        field: 'id',
        values: [row.id]
      })
    }
  }

  function initTable() {
    $table.bootstrapTable('destroy').bootstrapTable({
      // height: 850,
      locale : 'zh-CN',
      data : <?php echo $reportData;?>,
      exportTypes: ['csv', 'excel'],
      locale: $('#locale').val(),
      columns: [
        [{
          field: 'state',
          checkbox: true,
          align: 'center',
          valign: 'middle'
        }, <?php 
switch ($_GET['reporttype']) {
  // total|hostip|type|time|status
  case 'ytupload':
  case 'tkupload':
    echo "{
          title: '时间',
          field: 'time',
          align: 'left',
          valign: 'center',
          sortable: true,
        }, {
          field: 'hostip',
          title: '主机IP',
          sortable: true,
          align: 'left'
        },{
          field: 'type',
          title: '操作程序',
          sortable: true,
          align: 'left',
        }, {
          field: 'total',
          title: '上传量',
          sortable: true,
          align: 'left',
        }, {
          field: 'status',
          title: '上传状态',
          sortable: true,
          align: 'left',
        }, ";
    break;

  case 'relateword':
    echo "{
          title: '创建时间',
          field: 'createtime',
          align: 'left',
          valign: 'center',
          sortable: true,
          formatter: 'timeFormatter',
        }, {
          field: 'lang',
          title: '语言',
          sortable: true,
          align: 'left'
        },{
          field: 'mainword',
          title: '关键词',
          sortable: true,
          align: 'left',
        }, {
          field: 'subword',
          title: '关联词',
          sortable: true,
          align: 'left',
        },";
    break;

  case 'wordlist':
    // 'createtime','mainword','status','gitname','pubdir','lang','jsondata'
    echo "{field: 'createtime', title: '创建时间', sortable: true, align: 'left', formatter: 'timeFormatter'}, 
          {field: 'mainword', title: '关键词', sortable: true, align: 'left', }, 
          {field: 'status', title: '状态', sortable: true, align: 'left', }, 
          {field: 'gitname', title: '代码库名', sortable: true, align: 'left'}, 
          {field: 'pubdir', title: '发布目录', sortable: true, align: 'left', }, 
          {field: 'lang', title: '语言', align: 'left', valign: 'center', sortable: true, },
          {field: 'lasttask', title: '最近采集', align: 'left', valign: 'center', sortable: true,formatter: 'timeFormatter' },
          "; 
          break;

  case 'sitelist':
    // array('createtime', 'gitname', 'status', 'themetype', 'lang', 'domain', 'themename', 'keyword', 'sitetitle', 'sitedesc', 'json')
    echo "{field: 'createtime', title: '时间', align: 'left', valign: 'center', sortable: true, formatter: 'timeFormatter'}, 
          {field: 'gitname', title: '代码库名', sortable: true, align: 'left'},
          {field: 'status', title: '状态', sortable: true, align: 'left'},
          {field: 'themetype', title: '建站模板', sortable: true, align: 'left', visible: false},
          {field: 'lang', title: '语言', sortable: true, align: 'left', },
          {field: 'domain', title: '域名', sortable: true, align: 'left', },
          {field: 'themename', title: '模板名', sortable: true, align: 'left', visible: false},
          {field: 'sitetitle', title: '站点名', sortable: true, align: 'left'},
          {field: 'keyword', title: '导航菜单', sortable: true, align: 'left', visible: false},
          {field: 'sitedesc', title: '站点描述', sortable: true, align: 'left', visible: false},
          {field: 'json', title: '元数据', sortable: true, align: 'left', visible: false},
          {field: 'createtime', title: '编辑', align: 'center', valign: 'center', formatter: 'siteEditer'}, 
          ";
    break;

  default:
    echo "{
          title: '时间',
          field: 'time',
          align: 'left',
          valign: 'center',
          sortable: true,
        }, {
          field: 'hostip',
          title: '主机IP',
          sortable: true,
          align: 'left'
        },{
          field: 'type',
          title: '类目',
          sortable: true,
          align: 'left'
        }, {
          field: 'views',
          title: '观看量',
          sortable: true,
          align: 'left',
        }, {
          field: 'profileviews',
          title: '个人页访问量',
          sortable: true,
          align: 'left',
        }, {
          field: 'likes',
          title: '点赞量',
          sortable: true,
          align: 'left',
        }, {
          field: 'comments',
          title: '评论量',
          sortable: true,
          align: 'left',
        }, {
          field: 'shares',
          title: '分享量',
          sortable: true,
          align: 'left',
        }, {
          field: 'uniqueviewers',
          title: '唯一用户',
          sortable: true,
          align: 'left',
        },{
          field: 'period',
          title: '对比周期',
          sortable: true,
          align: 'left',
        },";
    break;
}
        if ($_GET['reporttype'] == "usercreate") { 
        } else { 
        }?> {
          field: 'operate',
          title: '操作项',
          align: 'center',
          clickToSelect: false,
          events: window.operateEvents,
          formatter: operateFormatter
        }]
      ]
    })
    $table.on('check.bs.table uncheck.bs.table ' + 'check-all.bs.table uncheck-all.bs.table',
    function () {
      $remove.prop('disabled', !$table.bootstrapTable('getSelections').length)

      // save your data, here just save the current page
      selections = getIdSelections()
      // push or splice the selections if you want to save all data selections
    })
    $table.on('all.bs.table', function (e, name, args) {
      console.log(name, args)
    })
    $remove.click(function () {
      var ids = getIdSelections()
      $table.bootstrapTable('remove', {
        field: 'id',
        values: ids
      })
      $remove.prop('disabled', true)
    })
  }

  $(function() {
    initTable()

    $('#locale').change(initTable)
  })
</script>
    </div>
    <?php } ?>
<?php };?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.1/dist/js/bootstrap.bundle.min.js" integrity="sha384-u1OknCvxWvY5kfmNBILK2hRnQC3Pr17a+RTT6rIHI7NnikvbZlHgTPOOmMi466C8" crossorigin="anonymous"></script>

</body>
</html>