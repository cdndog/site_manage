<?php
if (!file_exists('global_config.php')) {
    die('file global_config.php not found.');
}

$config = include 'global_config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>V2 站点生成文章与关键词</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="css/bootstrap.min.css">
  <link rel="stylesheet" href="css/bootstrap-icons.css" />
  <link rel="stylesheet" href="css/bootstrap-select.min.css" />
  <link rel="stylesheet" href="css/bootstrap-table.min.css">
</head>
<body>
<script>
function refreshPicture(el_id) {
    var text_raw = document.getElementById('textarea_'+el_id).value.split("|");
    // alert(text_raw[3]);
    if (text_raw[3].length > 0) {document.getElementById('image_'+el_id).src = text_raw[3];} else {alert("picture not found.");}
}
</script>
<?php

error_reporting(0);

define("LocalPATH", dirname(__FILE__));

function renewDBtable($db_name, $table_name, $sitedatas, $query_column, $renew_columns) {

    $db = new SQLite3($db_name, SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);

    // Errors are emitted as warnings by default, enable proper error handling.
    $db->enableExceptions(true);

    foreach ($sitedatas as $site) {

        // $git_name = $site['git_name'];

        $statement = $db->prepare('SELECT * FROM "'.$table_name.'" WHERE "'.$query_column.'" = :query_value');
        $statement->bindValue(':query_value', $site[$query_column]);
        $result = $statement->execute()->fetchArray(SQLITE3_ASSOC);

        if (isset($result[$query_column])) {
            // echo "{$site[$query_column]} exist {$result['ctx_id']}, updating".PHP_EOL;
            $SQL = 'UPDATE "'.$table_name.'" SET ';
            foreach ($renew_columns as $column) {
                $SQL .= '"'.$column.'" = :'.$column.', ';
            }
            $SQL = rtrim($SQL, ', ');
            $SQL .= ' WHERE "'.$query_column.'" = :'.$query_column;
            // echo $SQL;
            // $SQL = 'UPDATE "'.$table_name.'" SET "git_name" = :git_name, "domain" = :domain, "site_title" = :site_title, "site_subtitle" = :site_subtitle, "site_logo" = :site_logo, "languages" = :languages, "sns_id" = :sns_id, "topnav_menus" = :topnav_menus, "keyword" = :keyword, "theme_name" = :theme_name, "theme_type" = :theme_type, "sitedir" = :sitedir, "status" = :status, "json" = :json, "time" = :time WHERE "ctx_id" = :ctx_id';
            
            $statement = $db->prepare($SQL);
            foreach ($renew_columns as $column) {
                if ($column == 'ctx_id') {
                    $ctx_id = str_replace('.', '', uniqid(time(), true));
                    $site['ctx_id'] = !empty($result['ctx_id']) ? $result['ctx_id'] : $site['ctx_id'];
                    $statement->bindValue(':'.$column, isset($site[$column]) ? $site[$column] : $ctx_id );
                } else {
                    $statement->bindValue(':'.$column, isset($site[$column]) ? $site[$column] : "");
                }
            }

            $statement->bindValue(':time', date("Y-m-d H:i:s"));
            $statement->execute(); // you can reuse the statement with different values
        } else {
            $ctx_id = !empty($site['ctx_id']) ? $site['ctx_id'] : str_replace('.','',uniqid(time(), true));
            // echo "{$site[$query_column]} not found, inserting {$ctx_id}".PHP_EOL;
            $insertColumns = '';
            $insertValue = '';
            $SQL = 'INSERT INTO "'.$table_name.'" ';
            foreach ($renew_columns as $column) {
                $insertColumns .= '"'.$column.'", ';
                $insertValue .= ':'.$column.', ';
            }
            $insertColumns = ' ('.rtrim($insertColumns, ", ").') ';
            $insertValue = ' ('.rtrim($insertValue, ", ").') ';
            $SQL .= $insertColumns . ' VALUES ' . $insertValue;
            // echo $SQL.PHP_EOL;
            // $SQL = 'INSERT INTO "'.$table_name.'" ("ctx_id", "git_name", "domain", "site_title", "site_subtitle", "site_logo", "time", "languages", "sns_id", "topnav_menus", "keyword", "theme_name", "theme_type", "sitedir", "status", "json")
            // VALUES (:ctx_id, :git_name, :domain, :site_title, :site_subtitle, :site_logo, :time, :languages, :sns_id, :topnav_menus, :keyword, :theme_name, :theme_type, :sitedir, :status, :json)';
            // echo $SQL.PHP_EOL;
            $statement = $db->prepare($SQL);
            foreach ($renew_columns as $column) {
                if ($column == 'ctx_id') {
                    $ctx_id = str_replace('.', '', uniqid(time(), true));
                    $statement->bindValue(':'.$column, isset($site[$column]) ? $site[$column] : $ctx_id );
                } else {
                    $statement->bindValue(':'.$column, isset($site[$column]) ? $site[$column] : "");
                }
            }
            // $ctx_id = str_replace('.','',uniqid(time(), true));
            // $statement->bindValue(':ctx_id', isset($site['ctx_id']) ? $site['ctx_id'] : $ctx_id );
            // $statement->bindValue(':git_name', isset($site['git_name']) ? $site['git_name'] : "");
            // $statement->bindValue(':domain', isset($site['domain']) ? $site['domain'] : "");
            // $statement->bindValue(':site_title', isset($site['site_title']) ? $site['site_title'] : "");
            // $statement->bindValue(':site_subtitle', isset($site['site_subtitle']) ? $site['site_subtitle'] : "");
            // $statement->bindValue(':site_logo', isset($site['site_logo']) ? $site['site_logo'] : "");
            // $statement->bindValue(':languages', isset($site['languages']) ? $site['languages'] : "");
            // $statement->bindValue(':sns_id', isset($site['sns_id']) ? $site['sns_id'] : "");
            // $statement->bindValue(':topnav_menus', isset($site['topnav_menus']) ? $site['topnav_menus'] : "");
            // $statement->bindValue(':keyword', isset($site['keyword']) ? $site['keyword'] : "");
            // $statement->bindValue(':theme_name', isset($site['theme_name']) ? $site['theme_name'] : "");
            // $statement->bindValue(':theme_type', isset($site['theme_type']) ? $site['theme_type'] : "");
            // $statement->bindValue(':sitedir', isset($site['sitedir']) ? $site['sitedir'] : "" );
            // $statement->bindValue(':status', isset($site['status']) ? $site['status'] : "");
            // $statement->bindValue(':json', isset($site['json']) ? $site['json'] : "") ;
            $statement->bindValue(':time', date("Y-m-d H:i:s"));
            $statement->execute(); // you can reuse the statement with different values
        } 

    }
    $db->close();
}

function queryDB2text($db_name, $table_name, $output_name, $columns) {

    $db = new SQLite3($db_name, SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);

    // Errors are emitted as warnings by default, enable proper error handling.
    $db->enableExceptions(true);


    $statement = $db->prepare('SELECT * FROM "'.$table_name.'" WHERE "*" = "*"');

    $result = $statement->execute();

    // Fetch all rows from the result set
    $rows = array();
    $output_text = '';
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
        $rowData = '';
        foreach ($columns as $column) {
            $rowData .= $row[$column] . '|';
        }
        $output_text .= rtrim($rowData, '|') . PHP_EOL;
    }

    file_put_contents($output_name, $output_text);

    $db->close();
}

function queryDB2Array($db_name, $table_name, $SQL) {

    $db = new SQLite3($db_name, SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);

    // Errors are emitted as warnings by default, enable proper error handling.
    $db->enableExceptions(true);

    if (empty($SQL)) {
        $SQL = 'SELECT * FROM "'.$table_name.'" WHERE "*" = "*"';
    } 
    $statement = $db->prepare($SQL);

    $result = $statement->execute();

    // Fetch all rows from the result set
    $rows = array();
    $output_text = '';
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }

    $db->close();

    return $rows;
}

function initDBlite($db_name, $table_name, $SQL) {
    $db = new SQLite3($db_name, SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);

    // Errors are emitted as warnings by default, enable proper error handling.
    $db->enableExceptions(true);

    // Create a table.

    $db->query($SQL);

    $db->close();
}

// $db_name = 'sitedata.sqlite';
// $table_name = 'sitetopic';
// $output_name = 'sitetopic_monitor_list.txt';
// $keyword_columns = ['ctx_id', 'keyword', 'status', 'git_name', 'pubdir', 'lang', 'json'];

$db_name = 'sitedata.sqlite';
$table_name = 'sitetopic';
$output_name = 'topic_monitor_list.txt';
$keyword_columns = ['ctx_id', 'git_name', 'domain', 'keyword', 'pubdir', 'status', 'lang', 'geo', 'json'];
$query_column = 'git_name';

// $initSQL = 'CREATE TABLE IF NOT EXISTS "'.$table_name.'" (
//         "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
//         "ctx_id" VARCHAR UNIQUE NOT NULL,
//         "git_name" VARCHAR,
//         "keyword" VARCHAR,
//         "pubdir" VARCHAR,
//         "status" VARCHAR,
//         "lang" VARCHAR,
//         "geo" VARCHAR,
//         "lasttask" VARCHAR,
//         "json" VARCHAR,
//         "time" DATETIME
//     )';

// initDBlite($db_name, $table_name, $initSQL);


$savedir = "topicmonitor";
// $logFile = 'keyword_monitor_list.txt';
$siteconfig = 'siteops_setting.txt';
$siteconfigtable = 'siteops';

$site_table_name = 'siteops';
$sitedata = queryDB2Array($db_name, $site_table_name, $SQL = '');

define("SiteGitnameList", $sitedata);

function check_keyword_in_file($keyword, $file_path) {
    // Check if the post ID already exists in the file
    if ( empty($keyword)) return true;
    if (file_exists($file_path) && strpos(file_get_contents($file_path), $keyword) !== false) {
        return true;
    } else {
        return false;
    }
}

if ($_SERVER["REQUEST_METHOD"] == "GET") {

if (!empty($_GET['eid'])) {
    $ctx_id = $_GET['eid'];
    $topic_table_name = "sitetopic";
    $topic_data = queryDB2Array($db_name, $site_table_name, $SQL = 'select * from '. $topic_table_name .' where ctx_id = '.'"'. $ctx_id .'"');
    $topic_data = $topic_data[0];
} else {
    // $post_uuid = str_replace('.','',uniqid(time(), true));
    $topic_data = [
        "id" => "",
        "ctx_id" => str_replace('.','',uniqid(time(), true)),
        "git_name" => "",
        "domain" => "",
        "keyword" => "",
        "pubdir" => "",
        "status" => "",
        "lang" => "",
        "geo" => "",
        "lasttask" => "",
        "json" => '',
        "time" => ""
    ];
}


if ($topic_data) { ?>

<div class="container">
<div class="py-4 text-center">
    <h3>站点生成文章与关键词</h3>
</div>
<form action="<?php echo $_SERVER['PHP_SELF'];?>" method="post" id="formtable" class="needs-validation">
    <div class="form-group">
        <div class="row" hidden>
            <div class="col-md-12 col-xs-12">
            <label for="post_uuid">文章与关键词ID</label>
            <input type="text" class="form-control form-control-sm" id="post_uuid" name="post_uuid" value="<?php echo htmlspecialchars($topic_data['ctx_id']) ;?>">
            <div class="invalid-feedback"> 生成文章ID </div>
            </div>
         </div>
    </div>
    <div class="form-group">
        <div class="row">
            <div class="col-md-9 col-xs-12">
            <label for="post_gitname">生成文章及关键词</label>
            <input type="text" class="form-control form-control-sm" id="post_keyword" name="post_keyword" required placeholder="录入需要批量生成文章及关键词" value="<?php echo htmlspecialchars($topic_data['keyword']) ;?>">
            <div class="invalid-feedback"> 录入需要生成文章及关键词 </div>
            </div>
            <div class="col-md-3 col-xs-12">
            <label for="">启用批量</label>
            <div class="checkbox">
              <label>
                <input type="checkbox" value="enable" id="post_bulkkeyword" name="post_bulkkeyword">
              </label>
            </div>
            </div>
         </div>
         <div class="row">
            <div class="col-md-6 col-xs-12">
                <label for="post_gitname">发布到站点(github仓库名)</label>
                <select class="selectpicker form-control form-control-sm" id="post_gitname" name="post_gitname" size="1" data-show-subtext="true" data-live-search="true" required>
                    <option data-subtext="">Choose a gitname</option>
                <?php foreach ( SiteGitnameList as $sitedata ) :?>
                <option data-subtext="<?php if (!empty($sitedata['languages']) ) { echo $sitedata['git_name'].'('.$sitedata['languages'].')';} else {echo $sitedata['git_name']; } ?>" <?php if ($sitedata['git_name'] == $topic_data["git_name"]) {echo "selected";} ?> ><?php echo $sitedata['git_name'];?></option>
                <?php endforeach ?>
                </select>
            </div>
            <div class="col-md-6 col-xs-12">
                <label for="post_gitname">发布到站点(域名)</label>
                <select class="selectpicker form-control form-control-sm" id="post_domain" name="post_domain" size="1" data-show-subtext="true" data-live-search="true" required>
                    <option data-subtext="">Choose a Domain</option>
                <?php foreach ( SiteGitnameList as $sitedata ) :?>
                <option data-subtext="<?php if (!empty($sitedata['languages']) ) { echo $sitedata['domain'].'('.$sitedata['languages'].')';} else {echo $sitedata['domain']; } ?>" <?php if ($sitedata['domain'] == $topic_data["domain"]) {echo "selected";} ?>><?php echo $sitedata['domain'];?></option>
                <?php endforeach ?>
                </select>
            </div>

        </div>
        <input type="text" class="form-control form-control-sm sr-readonly" id="setupNum" name="setupNum" size=60 value="ckeditorFormated" readonly style="display: none;">

    </div>

    <div class="form-group">
      <div class="row">
        <div class="col-md-3 col-xs-12">
          <label for="post_lang">站点语言</label>
          <select data-show-subtext="true" data-live-search="true" class="selectpicker form-control form-control-sm" id="post_lang" name="post_lang" required>
            <?php foreach ($config['languages'] as $label => $value ): ?>
            <option data-subtext="<?php echo $label; ?>" <?php echo strtolower($value) === strtolower($topic_data["lang"]) ? 'selected' : ''; ?>>
                <?php echo $value; ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 col-xs-12">
          <label for="post_geo">国家地区</label>
          <select data-show-subtext="true" data-live-search="true" class="selectpicker form-control form-control-sm" id="post_geo" name="post_geo" required>
             <?php foreach ($config['countries'] as $label => $value ): ?>
            <option data-subtext="<?php echo $label; ?>" <?php echo strtolower($value) === strtolower($topic_data["geo"]) ? 'selected' : ''; ?>>
                <?php echo $value; ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 col-xs-12">
          <label for="post_pubdir">发布目录</label>
          <select class="selectpicker form-control form-control-sm" id="post_pubdir" name="post_pubdir" required>
             <?php foreach ($config['pubdir'] as $label => $value ): ?>
            <option data-subtext="<?php echo $label; ?>" <?php echo strtolower($value) === strtolower($topic_data["pubdir"]) ? 'selected' : ''; ?>>
                <?php echo $value; ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 col-xs-12">
          <label for="post_status">状态</label>
          <select data-show-subtext="true" data-live-search="true" class="selectpicker form-control form-control-sm" id="post_status" name="post_status" required>
             <?php foreach ($config['statuses'] as $label => $value ): ?>
            <option data-subtext="<?php echo $label; ?>" <?php echo strtolower($value) === strtolower($topic_data["status"]) ? 'selected' : ''; ?>>
                <?php echo $value; ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
   
    <button type="submit" class="btn btn-primary btn-block">提交入库</button>
</form>

<script>
    // Get the form element
    var form = document.getElementById('formtable');

    // Add submit event listener to the form
    form.addEventListener('submit', function(event) {
        // Get all inputs with the required attribute
        var requiredInputs = form.querySelectorAll('input[required], select[required]');

        // Check each required input
        for (var i = 0; i < requiredInputs.length; i++) {
            var input = requiredInputs[i];

            // Check if the input is empty
            if (!input.value.trim()) {
                // Get the associated label
                var label = form.querySelector('label[for="' + input.id + '"]');
                var labelText = label ? label.textContent : 'This field';

                // Prevent form submission
                event.preventDefault();

                // Display an alert with the label value
                alert('Please fill in all required fields. ' + labelText + ' is required.');

                // Optionally, you can focus on the empty input field
                input.focus();

                // Exit the loop, as there is no need to check the remaining inputs
                return;
            }
        }

        // Check the required textarea
        var textarea = document.getElementById('post_contents');

        if (!textarea.value.trim()) {
            // Get the associated label
            var label = form.querySelector('label[for="' + textarea.id + '"]');
            var labelText = label ? label.textContent : 'This field';

            // Prevent form submission
            event.preventDefault();

            // Display an alert with the label value
            alert('Please fill in all required fields. ' + labelText + ' is required.');

            // Optionally, you can focus on the empty textarea
            textarea.focus();
        }
    });
</script>

</div>
<?php }} ?>

<?php if ($_SERVER["REQUEST_METHOD"] == "POST" && $_POST["setupNum"] == "ckeditorFormated" ) { ?>
<div class="container">
    <?php

        // var_dump($_POST);
        array_walk($_POST, function (&$item) {
            $item = str_replace('|', '', $item);
        });
        $sitejson = json_decode($_POST['post_json'], true);
        $sitejson['post_uuid'] = trim($_POST['post_uuid']);
        $sitejson['git_name'] = trim($_POST['post_gitname']);
        $sitejson['domain'] = trim($_POST['post_domain']);
        $sitejson['keyword'] = trim($_POST['post_keyword']);
        $sitejson['pubdir'] = trim($_POST['post_pubdir']);
        $sitejson['status'] = trim($_POST['post_status']);
        $sitejson['lang'] = trim($_POST['post_lang']);
        $sitejson['geo'] = trim($_POST['post_geo']);
        $sitejson['lasttask'] = trim($_POST['post_lasttask']);
        // unset($sitejson['json']);
        // $sitejson['json'] = json_encode($sitejson);
        $sitejson['bulkkeyword'] = trim($_POST['post_bulkkeyword']);

        $_POST['post_json'] = json_encode($sitejson);

        foreach ($_POST as $key => $value) {
            switch ($key) {
                case 'post_uuid':
                    $content_array['post_uuid'] = trim($value);
                    $content_array['ctx_id'] = trim($value);
                    break;

                case 'post_gitname':
                    $content_array['git_name'] = trim($value);
                    break;
                    
                case 'post_domain':
                    $content_array['domain'] = trim($value);
                    break;
                    
                case 'post_keyword':
                    $content_array['keyword'] = trim($value);
                    break;
                    
                case 'post_pubdir':
                    $content_array['pubdir'] = trim($value);
                    break;
                    
                case 'post_status':
                    $content_array['status'] = trim($value);
                    break;
                    
                case 'post_lasttask':
                    $content_array['lasttask'] = trim($value);
                    break;
                    
                case 'post_lang':
                    $content_array['lang'] = trim($value);
                    break;
                    
                case 'post_geo':
                    $content_array['geo'] = trim($value);
                    break;
                    
                case 'post_bulkkeyword':
                    $content_array['bulkkeyword'] = trim($value);
                    break;
                    
                case 'setupNum':
                    $content_array['setupNum'] = trim($value);
                    break;
                
                default:
                    # code...
                    break;
            }
        }
        ?>

        <div class="form-group">
            <div class="row">
                <div class="col-md-6 col-xs-12">
                <label for="post_keyword">监控关键词:</label>
                <input type="text" class="form-control" id="post_keyword" name="post_keyword" value="<?php echo $content_array['keyword']; ?>" readonly>
                </div>
                <div class="col-md-3 col-xs-12">
                <label for="post_gitname">发布到站点:</label>
                <input type="text" class="form-control" id="post_gitname" name="post_gitname" value="<?php echo $content_array['git_name']; ?>" readonly>
                </div>
                <div class="col-md-3 col-xs-12">
                <label for="post_domain">发布到域名:</label>
                <input type="text" class="form-control" id="post_domain" name="post_domain" value="<?php echo $content_array['domain']; ?>" readonly>
                </div>
            </div>
        </div>

        <div class="form-group">
          <div class="row">
            <div class="col-md-3 col-xs-12">
                <label for="post_lang">站点语言:</label>
                <input type="text" class="form-control" id="post_lang" name="post_lang" value="<?php echo $content_array['lang']; ?>" readonly>
            </div>
            <div class="col-md-3 col-xs-12">
                <label for="post_geo">国家地区:</label>
                <input type="text" class="form-control" id="post_geo" name="post_geo" value="<?php echo $content_array['geo']; ?>" readonly>
            </div>
            <div class="col-md-3 col-xs-12">
                <label for="post_pubdir">发布目录:</label>
                <input type="text" class="form-control" id="post_pubdir" name="post_pubdir" value="<?php echo $content_array['pubdir']; ?>" readonly>
            </div>
            <div class="col-md-3 col-xs-12">
                <label for="post_status">状态:</label>
                <input type="text" class="form-control" id="post_status" name="post_status" value="<?php echo $content_array['status']; ?>" readonly>
            </div>
          </div>
        </div>

        <div class="form-group">
            <label for="json" >Json:</label>
            <textarea class="form-control" rows="4" readonly ><?php echo $_POST['post_json']; ?></textarea>
        </div>
        <div class="form-group" hidden >
            <label for="title" hidden >setupNum:</label>
            <input type="text" class="form-control" id="title" name="title" value="<?php echo $content_array['setupNum']; ?>" readonly style="display: none;">
        </div>
        <hr>

        <?php

        if ( !is_dir($savedir) ) { mkdir($savedir, 0755, true); }

        if (isset($content_array['ctx_id']) && !empty($content_array['ctx_id'])) {
            $query_column = 'ctx_id';
        } else {
            $query_column = 'keyword';
        }
        $query_column = 'ctx_id';
        $ctx_id = isset($content_array['ctx_id']) ? $content_array['ctx_id'] : str_replace('.','',uniqid(time(), true));

        // save record in local 
        file_put_contents(LocalPATH .'/'. $savedir .'/'. $ctx_id . '.json', $_POST['post_json']);

        // $wholeLogFile = file_get_contents($logFile);
        // $extract_url = $content_array['keyword'];
        // $concat_col = $content_array['status'].'|'.$content_array['git_name'].'|'.$content_array['pubdir'].'|'.$content_array['lang'];

        $db_name = 'sitedata.sqlite';
        $table_name = 'sitetopic';
        $output_name = 'topic_monitor_list.txt';
        $keyword_columns = ['ctx_id', 'keyword', 'status', 'git_name','domain', 'pubdir', 'lang', 'json'];

        // $query_column = !empty($content_array['ctx_id']) ? 'ctx_id' : 'keyword';

        $renew_columns = ['ctx_id', 'keyword', 'status', 'git_name', 'domain', 'pubdir', 'lang', 'geo', 'lasttask', 'json'];
        // $content_array['json'] = $_POST['post_json'];

        if ( $content_array['bulkkeyword'] == "enable" ) {
            $keywords = explode(',', $content_array['keyword']);
        } else {
            $keywords = [trim($content_array['keyword'])];
        }

        foreach ($keywords as $key => $keyword) {
            if (!strlen(trim($keyword))) continue;
            $temp_post = json_decode($_POST['post_json'], true);
            $temp_post['keyword'] = $keyword;
            if(isset($temp_post['bulkkeyword'])) {
                unset($temp_post['bulkkeyword']);
            }
            if(isset($temp_post['post_uuid'])) {
                unset($temp_post['post_uuid']);
            }
            $content_array['json'] = json_encode($temp_post);
            $content_array['keyword'] = trim($keyword);

            $sitedatas[] = $content_array;
        }

        // var_dump($sitedatas);

        renewDBtable($db_name, $table_name, $sitedatas, $query_column, $renew_columns);

        queryDB2text($db_name, $table_name, $output_name, $keyword_columns);

        $SQL = 'SELECT * FROM '.$table_name.' WHERE "git_name" = "'.$content_array['git_name'].'"';

        // var_dump($SQL);

        $output = queryDB2Array ($db_name, $table_name, $SQL);

    ?>
    <?php if (!empty($output)) : ?>
    <div id="displaytable">
    <table 
        data-toggle="table"
        data-search="true"
        data-show-columns="true">
      <thead>
        <tr>
          <th scope="col">#id</th>
          <th scope="col">git_name</th>
          <th scope="col">domain</th>
          <th scope="col">keyword</th>
          <th scope="col">pubdir</th>
          <th scope="col">status</th>
          <th scope="col">lang</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($output as $siteitem) :?>
        <tr>
          <th scope="row"><?php echo $siteitem['id'];?></th>
          <td><?php echo $siteitem['git_name'];?></td>
          <td><?php echo $siteitem['domain'];?></td>
          <td><?php echo $siteitem['keyword'];?></td>
          <td><?php echo $siteitem['pubdir'];?></td>
          <td><?php echo $siteitem['status'];?></td>
          <td><?php echo $siteitem['lang'];?></td>
        </tr>
        <?php endforeach ?>
      </tbody>
    </table>
    </div>
    <?php endif ?>
</div>
<?php } ?>

</body>
  <script src="js/jquery.min.js"></script>
  <script src="js/bootstrap.bundle.min.js"></script>
  <script src="js/bootstrap-table.min.js"></script>
  <!-- <script src="https://cdn.bootcdn.net/ajax/libs/jquery/3.5.1/jquery.min.js"></script> -->
  <!-- <script src="https://cdn.bootcdn.net/ajax/libs/jquery/3.5.1/jquery.min.js"></script> -->
  <!-- <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.2/js/bootstrap.min.js"></script> -->
  <script src="js/bootstrap-select.min.js"></script>
</html>