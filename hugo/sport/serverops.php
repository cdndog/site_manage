<!DOCTYPE html>
<html lang="en">
<head>
  <title>V2 服务器手工录入</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css"> -->
  <link rel="stylesheet" href="https://cdn.bootcdn.net/ajax/libs/twitter-bootstrap/3.4.1/css/bootstrap.min.css">
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

function renewDBtable($db_name, $table_name, $sitedatas, $query_column, $renew_columns) {

    $db = new SQLite3($db_name, SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);

    // Errors are emitted as warnings by default, enable proper error handling.
    $db->enableExceptions(true);

    // Create a table.

    $db->query('CREATE TABLE IF NOT EXISTS "'.$db_name.'" (
        "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
        "ctx_id" VARCHAR UNIQUE NOT NULL,
        "git_name" VARCHAR UNIQUE,
        "domain" VARCHAR ,
        "site_title" VARCHAR,
        "site_subtitle" VARCHAR,
        "site_logo" VARCHAR,
        "languages" VARCHAR,
        "sns_id" VARCHAR,
        "topnav_menus" VARCHAR,
        "keyword" VARCHAR,
        "theme_name" VARCHAR,
        "theme_type" VARCHAR,
        "sitedir" VARCHAR,
        "deploy" VARCHAR,
        "hostip" VARCHAR,
        "local_deploy" VARCHAR,
        "local_hostip" VARCHAR,
        "status" VARCHAR,
        "json" VARCHAR,
        "time" DATETIME
    )');

    foreach ($sitedatas as $site) {

        // $git_name = $site['git_name'];

        $statement = $db->prepare('SELECT * FROM "'.$table_name.'" WHERE "'.$query_column.'" = :query_value');
        $statement->bindValue(':query_value', $site[$query_column]);
        $result = $statement->execute()->fetchArray(SQLITE3_ASSOC);

        if (isset($result[$query_column])) {
            echo "{$site[$query_column]} exist {$result['ctx_id']}, updating".PHP_EOL;
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
        } else {
            $ctx_id = !empty($site['ctx_id']) ? $site['ctx_id'] : str_replace('.','',uniqid(time(), true));
            echo "{$site[$query_column]} not found, inserting {$ctx_id}".PHP_EOL;
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

    // Create a table.

    // $db->query('CREATE TABLE IF NOT EXISTS "'.$db_name.'" (
    //     "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    //     "ctx_id" VARCHAR UNIQUE NOT NULL,
    //     "git_name" VARCHAR UNIQUE,
    //     "domain" VARCHAR ,
    //     "site_title" VARCHAR,
    //     "site_subtitle" VARCHAR,
    //     "site_logo" VARCHAR,
    //     "languages" VARCHAR,
    //     "sns_id" VARCHAR,
    //     "topnav_menus" VARCHAR,
    //     "keyword" VARCHAR,
    //     "theme_name" VARCHAR,
    //     "theme_type" VARCHAR,
    //     "sitedir" VARCHAR,
    //     "deploy" VARCHAR,
    //     "hostip" VARCHAR,
    //     "local_deploy" VARCHAR,
    //     "local_hostip" VARCHAR,
    //     "status" VARCHAR,
    //     "json" VARCHAR,
    //     "time" DATETIME
    // )');

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

    return $output_text;
}

function queryDB2Array($db_name, $table_name) {

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
        // $rowData = '';
        // foreach ($columns as $column) {
        //     $rowData .= $row[$column] . '|';
        // }
        // $output_text .= rtrim($rowData, '|') . PHP_EOL;
    }

    $db->close();

    return $rows;
}

function initDBlite($db_name, $table_name) {
    $db = new SQLite3($db_name, SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);

    // Errors are emitted as warnings by default, enable proper error handling.
    $db->enableExceptions(true);

    // Create a table.

    $db->query('CREATE TABLE IF NOT EXISTS "serverlist" (
        "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
        "ctx_id" VARCHAR UNIQUE NOT NULL,
        "git_name" INTEGER UNIQUE,
        "domain" VARCHAR,
        "site_title" VARCHAR,
        "site_subtitle" VARCHAR,
        "site_logo" VARCHAR,
        "languages" VARCHAR,
        "sns_id" VARCHAR,
        "topnav_menus" VARCHAR,
        "keyword" VARCHAR,
        "theme_name" VARCHAR,
        "theme_type" VARCHAR,
        "sitedir" VARCHAR,
        "deploy" VARCHAR,
        "hostip" VARCHAR,
        "local_deploy" VARCHAR,
        "local_hostip" VARCHAR,
        "status" VARCHAR,
        "json" VARCHAR,
        "time" DATETIME
    )');

    $db->close();
}

$db_name = 'sitedata.sqlite';
$table_name = 'serverlist';
$output_name = 'serverlist_demo_v2.txt';
$site_columns = ['ctx_id', 'git_name', 'status', 'theme_type', 'languages', 'domain', 'sns_id', 'topnav_menus', 'site_title', 'site_subtitle', 'json'];

$query_column = 'git_name';

// initDBlite($db_name, $table_name);

// $renew_columns = ['ctx_id', 'git_name', 'domain', 'site_title', 'site_subtitle', 'site_logo', 'languages', 'sns_id', 'topnav_menus', 'keyword', 'theme_name', 'theme_type', 'sitedir', 'status', 'json', 'time'];


define("LocalPATH", dirname(__FILE__));

function randomIcons($theme_type, $limit) {
    $theme_type = "game"; // Replace "your_theme_type" with the actual theme type you want

    $url = "https://icons8.com/icons/set/{$theme_type}--static--icons8";

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); // Ignore SSL verification (remove this line if not needed)

    $userAgent = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36";
    curl_setopt($curl, CURLOPT_USERAGENT, $userAgent);

    $response = curl_exec($curl);

    curl_close($curl);

    $matches = [];

    preg_match_all('/class="app-grid-icon__link" href="\/icon\/[^\/]*\//', $response, $matches);

    $icons = [];
    foreach ($matches[0] as $match) {
        preg_match('/\/icon\/[^\/]*\//', $match, $icon_match);
        $icon_id = trim($icon_match[0], '/icon/');
        $icons[] = "https://img.icons8.com/?size=100&id=".$icon_id."&format=png";
    }

    // Randomly select 20 icons
    $randomIcons = array_rand($icons, $limit);
    // Output the selected icons
    foreach ($randomIcons as $iconIndex) {
        $output[] =  $icons[$iconIndex];
    }

    return $output;
}

$savedir = "keywordmonitor";
$logFile = 'keyword_monitor_list.txt';
$siteconfig = 'sitebulkops_setting_v2.txt';
$sitedata = queryDB2text($db_name, $table_name, $output_name, $site_columns);

// foreach ($sitedata as $sitesingle) {
//     $site = explode('|', $sitesingle);
//     $json = end($site);
//     if (!empty($json)) {
//          $gitname[] = json_decode($json, true);
//     }
// }

// if (file_exists($siteconfig) && empty($sitedata)) {
//     $sitedata = file($siteconfig, FILE_IGNORE_NEW_LINES);
//     foreach ($sitedata as $sitesingle) {
//         $site = explode('|', $sitesingle);
//         $json = end($site);
//         if (!empty($json)) {
//              $gitname[] = json_decode($json, true);
//         }
//     }
// }

// $gitname = queryDB2Array($db_name, $table_name);

define("SiteServerList", $gitname);

function clean_html($html) {
    // Remove unnecessary whitespace
    $html = preg_replace('/\s{2,}/', ' ', $html);
    $html = preg_replace('/<!--.*?-->/', '', $html);
    $html = preg_replace('/>\s+</', '><', $html);
    
    // Remove inline CSS styles
    $html = preg_replace('/(<[^>]+) style=".*?"/i', '$1', $html);

    // Remove classes
    $html = preg_replace('/(<[^>]+) class=".*?"/i', '$1', $html);
    $html = preg_replace('/(<[^>]+) id=".*?"/i', '$1', $html);
    
    return $html;
}

function check_keyword_in_file($keyword, $file_path) {
    // Check if the post ID already exists in the file
    if ( empty($keyword)) return true;
    if (file_exists($file_path) && strpos(file_get_contents($file_path), $keyword) !== false) {
        return true;
    } else {
        return false;
    }
}

function imgformat ($html) {

    $sourceEncoding = "UTF-8"; // Assuming the source encoding is UTF-8
    $targetEncoding = "UTF-8"; // Change this to your desired target encoding
    // Convert the HTML content to the target encoding
    $html = @mb_convert_encoding($html, $targetEncoding, $sourceEncoding);

    // Create a new DOMDocument instance and load the HTML content
    $dom = new DOMDocument();
    // $dom->loadHTML($html);
    libxml_use_internal_errors(true); // Disable libxml errors
    $dom->loadHTML(@mb_convert_encoding($html, 'HTML-ENTITIES', $targetEncoding));
    libxml_use_internal_errors(false); // Enable libxml errors

    // Find all image elements in the document
    $images = $dom->getElementsByTagName('img');

    // Loop through each image element
    foreach ($images as $image) {
        // Get the value of the data-src attribute
        $dataSrc = $image->getAttribute('data-src');

        // Update the src attribute with the data-src value
        $image->setAttribute('src', $dataSrc);

        $attributes = $image->attributes;
        foreach ($attributes as $attribute) {
            $name = $attribute->name;
            if ($name !== 'src') {
                $image->removeAttribute($name);
            }
        }
    }
    return $dom->saveHTML();
}

if ($_SERVER["REQUEST_METHOD"] == "GET") {

if ( empty($_GET['wx_post_url']) ) { ?>

<div class="container">
<form action="<?php echo $_SERVER['PHP_SELF'];?>" method="post" id="wechatpost">
    <div class="form-group">
        <div class="row">
            <div class="col-md-6 col-xs-12">
            <label for="post_gitname">服务器管理IP:</label>
            <input type="text" class="form-control" id="post_gitname" name="post_gitname" value="" required placeholder="服务器唯一标识，规则：公网IP地址 8.8.8.8">
            </div>
            <div class="col-md-6 col-xs-12">
            <label for="post_domain">关联域名（用于建站）:</label>
            <input type="text" class="form-control" id="post_domain" name="post_domain" value="" placeholder="不要加https，如域名:abc.com，或子域名：ja.abc.com，可留空自动关联">
            </div>
        </div>
        <label hidden for="post_sitetitle">站点标题:</label>
        <input style="display:none;" type="text" class="form-control" id="post_sitetitle" name="post_sitetitle" value="" placeholder="字数控制在100字内，不包含（｜）">

        <label hidden for="post_description" >站点描述:</label>
        <input style="display:none;" type="text" class="form-control" id="post_description" name="post_description" value="" placeholder="字数控制在200字内，不包含（｜）" style="display:none;">

        <label hidden for="post_sitelogo" hidden>站点图标:</label>
        <input style="display:none;" type="text" class="form-control" id="post_sitelogo" name="post_sitelogo" value="" placeholder="文章的封面图片网址，只支持外部图床链接，不包含（｜）">

        <hr>
        <div class="row">
            <div class="col-md-6 col-xs-12" hidden>
                <label for="post_sitedeploy">部署模式:</label>
                <select class="form-control" id="post_sitedeploy" name="post_sitedeploy">
                <option value="" selected>null</option>
                <option value="cloudflare">cloudflare</option>
                <option value="linux">linux</option>
                </select>
                <div style="color:red"> 选择CloudFlare时为无服务器模式，只在选择Linux模式时需要配置Linux服务器IP。</div>
            </div>
            <div class="col-md-12 col-xs-12">
                <label for="local_hostip">服务器公网IP（用于建站）:</label>
                <input type="text" class="form-control" id="local_hostip" name="local_hostip" value="" placeholder="公网IP 如：8.8.8.8" >
            </div>
        </div>
        <input type="text" class="form-control sr-readonly" id="setupNum" name="setupNum" size=60 value="ckeditorFormated" readonly style="display: none;">
        <div hidden class="row">
            <div class="col-md-4 col-xs-12">
            <label for="post_sns_id">SNS ID:</label>
            <input type="text" class="form-control" id="post_sns_id" name="post_sns_id" value="" placeholder="Facebook，Twitter，YouTube ID，不包含（｜）">
            </div>
            <div class="col-md-4 col-xs-12">
            <label for="post_topnavmenus">顶部菜单项:</label>
            <input type="text" class="form-control" id="post_topnavmenus" name="post_topnavmenus" value="" placeholder="SEO关键词，英文逗号（,）分隔，不包含（｜）">
            </div>
            <div class="col-md-4 col-xs-12">
            <label for="post_keyword">SEO关键词:</label>
            <input type="text" class="form-control" id="post_keyword" name="post_keyword" value="" placeholder="SEO关键词，英文逗号（,）分隔，不包含（｜）">
            </div>
        </div>
    </div>

    <div hidden class="form-group">
      <div class="row">
        <div class="col-md-4 col-xs-12">
          <label for="post_lang">站点语言:</label>
          <select class="form-control" id="post_lang" name="post_lang">
            <option value="" selected>null</option>
            <option value="en">en-US</option>
            <option value="ja">ja-JP</option>
            <option value="zh">zh-CN</option>
            <option value="es">es-MX</option>
            <option value="ko">ko-KR</option>
          </select>
        </div>
        <div class="col-md-4 col-xs-12">
          <label for="post_themetype">模板:</label>
          <select class="form-control" id="post_themetype" name="post_themetype">
            <option value="" selected>null</option>
            <option value="poker">poker</option>
            <option value="game">game</option>
            <option value="blog">blog</option>
            <option value="software">software</option>
          </select>
        </div>
        <div class="col-md-4 col-xs-12">
          <label for="post_status">状态:</label>
          <select class="form-control" id="post_status" name="post_status">
            <option value="online" selected>online</option>
            <option value="offline">offline</option>
            <option value="draft">draft</option>
          </select>
        </div>
      </div>
    </div>
   
    <button type="submit" class="btn btn-default">Next</button>
</form>

<script>
    // Get the form element
    var form = document.getElementById('wechatpost');

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
        var textarea = document.getElementById('post_ckeditor_contents');

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
        array_walk($_POST, function (&$item) {
            $item = str_replace('|', '', $item);
        });
        $sitejson = json_decode($_POST["post_json"], true);
        $sitejson['post_uuid'] = $_POST['post_uuid'];
        $sitejson['git_name'] = $_POST['post_gitname'];
        $sitejson['domain'] = $_POST['post_domain'];
        $sitejson['site_title'] = $_POST['post_sitetitle'];
        $sitejson['site_logo'] = $_POST['post_sitelogo'];
        $sitejson['languages'] = $_POST['post_lang'];
        $sitejson['sns_id'] = $_POST['post_sns_id'];
        $sitejson['topnav_menus'] = $_POST['post_topnavmenus'];
        $sitejson['keyword'] = $_POST['post_keyword'];
        $sitejson['theme_name'] = $_POST['post_gitname'];
        $sitejson['theme_type'] = $_POST['post_themetype'];
        $sitejson['sitedir'] = $_POST['post_sitedir'];
        $sitejson['deploy'] = $_POST['post_sitedeploy'];
        $sitejson['hostip'] = $_POST['post_sitehostip'];
        $sitejson['local_deploy'] = $_POST['local_deploy'];
        $sitejson['local_hostip'] = $_POST['local_hostip'];
        $sitejson['site_subtitle'] = $_POST['post_description'];
        $sitejson['status'] = $_POST['post_status'];
        // $alljson['content']['oldhtml'] = $alljson['content']['html'];
        // $alljson['content']['html'] = array();

        $_POST['post_json'] = json_encode($sitejson);

        if (empty($_POST['post_themename'])) {
            $_POST['post_themename'] = $_POST['post_gitname'];
        }

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
                    
                case 'post_sitetitle':
                    $content_array['site_title'] = trim($value);
                    break;
                    
                case 'post_description':
                    $content_array['site_subtitle'] = trim($value);
                    break;
                    
                case 'post_sitelogo':
                    $content_array['site_logo'] = trim($value);
                    break;
                    
                case 'post_sitedir':
                    $content_array['sitedir'] = trim($value);
                    break;
                    
                case 'post_sitedeploy':
                    $content_array['deploy'] = trim($value);
                    break;
                    
                case 'local_deploy':
                    $content_array['local_deploy'] = trim($value);
                    break;
                    
                case 'local_hostip':
                    $content_array['local_hostip'] = trim($value);
                    break;
                    
                case 'post_lang':
                    $content_array['languages'] = trim($value);
                    break;
                    
                case 'post_sns_id':
                    $content_array['sns_id'] = trim($value);
                    break;
                    
                case 'post_topnavmenus':
                    $content_array['topnav_menus'] = trim($value);
                    break;
                    
                case 'post_themename':
                    $content_array['theme_name'] = trim($value);
                    break;
                    
                case 'post_themetype':
                    $content_array['theme_type'] = trim($value);
                    break;
                    
                case 'post_status':
                    $content_array['status'] = trim($value);
                    break;
                    
                case 'setupNum':
                    $content_array['setupNum'] = trim($value);
                    break;
                    
                case 'post_json':
                    $content_array['json'] = trim($value);
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
                <label for="post_gitname">服务器IP:</label>
                <input type="text" class="form-control" id="post_gitname" name="post_gitname" value="<?php echo $content_array['git_name']; ?>" readonly>
                </div>
                <div class="col-md-6 col-xs-12">
                <label for="post_domain">关联域名:</label>
                <input type="text" class="form-control" id="post_domain" name="post_domain" value="<?php echo $content_array['domain']; ?>" readonly>
                </div>
            </div>
            <label hidden for="post_sitetitle">站点标题:</label>
            <input style="display:none;" type="text" class="form-control" id="post_sitetitle" name="post_sitetitle" value="<?php echo $content_array['site_title']; ?>" readonly>

            <label hidden for="post_description">站点描述:</label>
            <input style="display:none;" type="text" class="form-control" id="post_description" name="post_description" value="<?php echo $content_array['site_subtitle']; ?>" readonly>

            <label hidden for="post_sitelogo">站点图标:</label>
            <input style="display:none;" type="text" class="form-control" id="post_sitelogo" name="post_sitelogo" value="<?php echo $content_array['site_logo']; ?>" readonly>
            <div class="row">
                <div hidden class="col-md-6 col-xs-12">
                <label for="post_sitedeploy">部署模式:</label>
                <input type="text" class="form-control" id="post_sitedeploy" name="post_sitedeploy" value="<?php echo $content_array['deploy']; ?>" readonly>
                </div>
                <div class="col-md-12 col-xs-12">
                <label for="local_hostip">部署服务器IP:</label>
                <input type="text" class="form-control" id="local_hostip" name="local_hostip" value="<?php echo $content_array['local_hostip']; ?>" readonly>
                </div>
            </div>
            <div hidden class="row">
                <div class="col-md-4 col-xs-12">
                <label for="post_sns_id">SNS ID:</label>
                <input type="text" class="form-control" id="post_sns_id" name="post_sns_id" value="<?php echo $content_array['sns_id']; ?>" readonly>
                </div>
                <div class="col-md-4 col-xs-12">
                <label for="post_topnavmenus">顶部菜单项:</label>
                <input type="text" class="form-control" id="post_topnavmenus" name="post_topnavmenus" value="<?php echo $content_array['topnav_menus']; ?>" readonly>
                </div>
                <div class="col-md-4 col-xs-12">
                <label for="post_keyword">SEO关键词:</label>
                <input type="text" class="form-control" id="post_keyword" name="post_keyword" value="<?php echo $content_array['keyword']; ?>" readonly>
                </div>
            </div>
        </div>

        <div hidden class="form-group">
          <div class="row">
            <div class="col-md-4 col-xs-12">
                <label for="post_lang">站点语言:</label>
                <input type="text" class="form-control" id="post_lang" name="post_lang" value="<?php echo $content_array['languages']; ?>" readonly>
            </div>
            <div class="col-md-4 col-xs-12">
                <label for="post_themetype">模板:</label>
                <input type="text" class="form-control" id="post_themetype" name="post_themetype" value="<?php echo $content_array['theme_type']; ?>" readonly>
            </div>
            <div class="col-md-4 col-xs-12">
                <label for="post_status">状态:</label>
                <input type="text" class="form-control" id="post_status" name="post_status" value="<?php echo $content_array['status']; ?>" readonly>
            </div>
          </div>
        </div>
        <div class="form-group">
            <label for="json" >Json:</label>
            <textarea class="form-control" rows="4" readonly ><?php echo $content_array['json']; ?></textarea>
        </div>
        <div class="form-group" hidden >
            <label for="title" hidden >setupNum:</label>
            <input type="text" class="form-control" id="title" name="title" value="<?php echo $content_array['setupNum']; ?>" readonly style="display: none;">
        </div>
        <hr>

        <?php

        define("LocalPATH", dirname(__FILE__));
        if ( !is_dir('sitebulkops') ) { mkdir('sitebulkops', 0755, true); }
        $ctx_id = isset($content_array['trans_id']) ? $content_array['trans_id'] : str_replace('.','',uniqid(time(), true));

        // save record in local 
        // $logFile = 'siteops_setting.txt';
        // $wholeLogFile = file_get_contents($logFile);
        // $extract_url = $content_array['git_name'];
        // $concat_col = $content_array['status'].'|'.$content_array['theme_type'].'|'.$content_array['languages'].'|'.$content_array['domain'].'|'.$content_array['sns_id'].'|'.$content_array['topnav_menus'].'|'.$content_array['site_title'].'|'.$content_array['site_subtitle'];
        // $extrat_contentjson = $_POST['post_json'];

        $db_name = 'sitedata.sqlite';
        $table_name = 'serverlist';
        $output_name = 'sitebulkops_setting_v2.txt';
        $site_columns = ['ctx_id', 'git_name', 'status', 'theme_type', 'languages', 'domain', 'sns_id', 'topnav_menus', 'site_title', 'site_subtitle', 'json'];

        $query_column = 'git_name';

        $renew_columns = ['ctx_id', 'git_name', 'domain', 'site_title', 'site_subtitle', 'site_logo', 'languages', 'sns_id', 'topnav_menus', 'keyword', 'theme_name', 'theme_type', 'sitedir', 'deploy', 'hostip', 'local_deploy', 'local_hostip', 'status', 'json', 'time'];

        // $content_array['json'] = $_POST['post_json'];
        $content_array['json'] = json_encode($content_array);

        $sitedatas[] = $content_array;

        var_dump($sitedatas);

        renewDBtable($db_name, $table_name, $sitedatas, $query_column, $renew_columns);

        queryDB2text($db_name, $table_name, $output_name, $site_columns);

        // if ( !check_keyword_in_file($extract_url, $logFile) ) {
        //     file_put_contents($logFile, $ctx_id.'|'.$extract_url .'|'. $concat_col .'|'. $extrat_contentjson.PHP_EOL, FILE_APPEND );
        //     file_put_contents( LocalPATH . '/sitebulkops/'.$ctx_id.'.json', $_POST['post_json']);
        // } else {
        //     $lines = file($logFile, FILE_IGNORE_NEW_LINES);

        //     foreach ($lines as $key => $line) {
        //         if (preg_match("/" . preg_quote($extract_url . '|', '/') . "/", $line)) {
        //             list($ctx_id, $oldurl, $olddatajson) = explode('|', $line);
        //             $updateLine = str_replace('#', '', $ctx_id) . '|' . $oldurl . '|' . $concat_col .'|'. $extrat_contentjson;

        //             // Remove the matched line from the array
        //             unset($lines[$key]);

        //             // Append the updated line to the end of the array
        //             $lines[] = $updateLine;

        //             file_put_contents(LocalPATH . '/sitebulkops/' . $ctx_id . '.json', $_POST['post_json']);
        //         }
        //     }

        //     // Write the updated lines back to the file
        //     file_put_contents($logFile, implode(PHP_EOL, $lines).PHP_EOL);
        // }

    ?>
</div>
<?php } ?>

</body>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.2/js/bootstrap.min.js"></script>
</html>