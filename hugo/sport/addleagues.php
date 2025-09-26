<!DOCTYPE html>
<html lang="en">
<head>
  <title>V2 站点监控关键词录入</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="css/bootstrap.min.css">
  <link rel="stylesheet" href="css/bootstrap-icons.css" />
  <link rel="stylesheet" href="css/bootstrap-select.min.css" />
  <!-- <script src="https://cdn.ckeditor.com/4.16.2/standard/ckeditor.js"></script> -->
  <!-- <script src="https://cdn.ckeditor.com/4.22.1/standard/ckeditor.js"></script> -->
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
    $db->enableExceptions(true);

    try {
        foreach ($sitedatas as $site) {
            // Build the WHERE clause for SELECT
            $queryString = implode(' AND ', array_map(function($column) {
                return "\"$column\" = :$column";
            }, $query_column));

            // Prepare and execute the SELECT statement
            $statement = $db->prepare("SELECT * FROM \"$table_name\" WHERE $queryString");
            foreach ($query_column as $query_item) {
                $statement->bindValue(":$query_item", $site[$query_item]);
            }
            $result = $statement->execute()->fetchArray(SQLITE3_ASSOC);

            if ($result) {
                // Update logic
                $updateColumns = implode(', ', array_map(function($column) {
                    return "\"$column\" = :$column";
                }, $renew_columns));

                $updateQuery = "UPDATE \"$table_name\" SET $updateColumns WHERE \"{$query_column[0]}\" = :{$query_column[0]}";
                $statement = $db->prepare($updateQuery);

                foreach ($renew_columns as $column) {
                    if ($column === 'ctx_id') {
                        $ctx_id = str_replace('.', '', uniqid(time(), true));
                        $site['ctx_id'] = $result['ctx_id'] ?? $site['ctx_id'];
                        $statement->bindValue(":$column", $site[$column] ?? $ctx_id);
                    } else {
                        $statement->bindValue(":$column", $site[$column] ?? "");
                    }
                }
                $statement->bindValue(':time', date("Y-m-d H:i:s"));
                $statement->execute();

            } else {
                // Insert logic
                $ctx_id = !empty($site['ctx_id']) ? $site['ctx_id'] : str_replace('.', '', uniqid(time(), true));
                $insertColumns = implode(', ', array_map(function($column) {
                    return "\"$column\"";
                }, $renew_columns));
                $insertValues = implode(', ', array_map(function($column) {
                    return ":$column";
                }, $renew_columns));

                $insertQuery = "INSERT INTO \"$table_name\" ($insertColumns) VALUES ($insertValues)";
                $statement = $db->prepare($insertQuery);

                foreach ($renew_columns as $column) {
                    if ($column === 'ctx_id') {
                        $statement->bindValue(":$column", $site[$column] ?? $ctx_id);
                    } else {
                        $statement->bindValue(":$column", $site[$column] ?? "");
                    }
                }
                $statement->bindValue(':time', date("Y-m-d H:i:s"));
                $statement->execute();
            }
        }
    } catch (Exception $e) {
        // Handle exception (log it, rethrow it, etc.)
        // echo "Error: " . $e->getMessage();
    } finally {
        $db->close();
    }
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

if (!file_exists('global_config.php')) {
    die('file global_config.php not found.');
}

$config = include 'global_config.php';
$leagues = include 'leagues_datas.php';

$db_name = 'sitedata.sqlite';
$table_name = 'leaguesmonitorlist';
$output_name = 'leagues_monitor_list.txt';
$keyword_columns = ['ctx_id', 'keyword', 'status', 'git_name', 'pubdir', 'lang', 'json'];

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


$savedir = "leaguesmonitor";
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

// if (!empty($_GET['t'])) {
//     $extract_url = $_GET['t'];

//     if ( preg_match('/[0-9]{10}/', $extract_url) && check_keyword_in_file($extract_url, $logFile)  ) {
//         $lines = file($logFile, FILE_IGNORE_NEW_LINES);

//         foreach ($lines as $key => $line) {
//             if (preg_match("/" . preg_quote($extract_url . '|', '/') . "/", $line)) {
//                 list($ctx_id, $oldurl, $olddatajson) = explode('|', $line);
//                 $updateLine = str_replace('#', '', $ctx_id) . '|' . $oldurl . '|' . $concat_col .'|'. $extrat_contentjson;
//                 echo $olddatajson;
//                 break;
//             }
//         }
//     }
// }

if ( empty($_GET) ) { ?>

<div class="container">
<div class="py-4 text-center">
    <h3>赛事网站关联配置</h3>
</div>
<form action="<?php echo $_SERVER['PHP_SELF'];?>" method="post" id="formtable">
    <div class="form-group">
        <div class="row">
            <div class="col-md-6 col-xs-12">
                <label for="post_keyword">关联赛事</label>
                <select class="selectpicker form-control form-control-sm" id="post_keyword" name="post_keyword" size="1" data-show-subtext="true" data-live-search="true" required>
                <option data-subtext="Choose a gitname" disabled selected></option>
                <?php foreach ($leagues as $league_item): ?>
                    <?php
                    $lastSeasons = array_slice($league_item['seasons'], -5);
                    $leagueName = $league_item['league']['name'] ?? '';
                    $leagueId = $league_item['league']['id'];
                    $countryName = $league_item['country']['name'] ?? '';
                    $leagueType = $league_item['league']['type'] ?? '';
                    ?>
                    <?php foreach ($lastSeasons as $season_item): ?>
                    <?php $year = $season_item['year'];?>
                    <option data-subtext="<?php echo !empty($leagueName) ? "$year - $countryName - $leagueName - $leagueType" : ''; ?>"> <?php echo "$leagueId - $year - $countryName - $leagueName - $leagueType"; ?> </option>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 col-xs-12">
            <label for="">启用批量方式</label>
            <div class="checkbox">
              <label>
                <input type="checkbox" value="enable" id="post_bulkkeyword" name="post_bulkkeyword" disabled>
                启用
              </label>
            </div>
            </div>
            <div class="col-md-3 col-xs-12">
                <label for="post_gitname">发布到站点(github仓库名)</label>
                <select class="selectpicker form-control form-control-sm" id="post_gitname" name="post_gitname" size="1" data-show-subtext="true" data-live-search="true" required>
                    <option data-subtext="Choose a gitname" disabled selected></option>
                    <option data-subtext="global">global</option>
                <?php foreach ( SiteGitnameList as $gitname ) :?>
                <?php if ( $gitname['status'] !== "delete" ) :?>
                <option data-subtext="<?php if (!empty($gitname['languages']) ) { echo $gitname['git_name'].'('.$gitname['languages'].')';} else {echo $gitname['git_name']; } ?>"><?php echo $gitname['git_name'];?></option>
                <?php endif ?>
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
            <option data-subtext="<?php echo $label; ?>" <?php echo strtolower($value) === strtolower($post_lang) ? 'selected' : ''; ?>>
                <?php echo $value; ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 col-xs-12">
          <label for="post_geo">国家地区</label>
          <select data-show-subtext="true" data-live-search="true" class="selectpicker form-control form-control-sm" id="post_geo" name="post_geo" required>
             <?php foreach ($config['countries'] as $label => $value ): ?>
            <option data-subtext="<?php echo $label; ?>" <?php echo strtolower($value) === strtolower($post_lang) ? 'selected' : ''; ?>>
                <?php echo $value; ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 col-xs-12">
          <label for="post_categoryname">发布目录</label>
          <select class="form-control form-control-sm" id="post_pubdir" name="post_pubdir" required>
            <option value="article">article</option>
            <option value="home">home</option>
            <option value="news">news</option>
            <option value="blog">blog</option>
            <option value="game">game</option>
          </select>
        </div>
        <div class="col-md-3 col-xs-12">
          <label for="post_status">状态</label>
          <select class="form-control form-control-sm" id="post_status" name="post_status" required>
            <option value="enable" selected>enable</option>
            <option value="disable">disable</option>
            <option value="draft">draft</option>
          </select>
        </div>
      </div>
    </div>
   
    <button type="submit" class="btn btn-bg btn-primary btn-block">提交</button>
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
        $sitejson['post_uuid'] = isset( $_POST['post_uuid'] ) ? trim($_POST['post_uuid']) : str_replace('.','',uniqid(time(), true));
        $sitejson['git_name'] = trim($_POST['post_gitname']);
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
                <label for="post_keyword">关联赛事:</label>
                <input type="text" class="form-control" id="post_keyword" name="post_keyword" value="<?php echo $content_array['keyword']; ?>" readonly>
                </div>
                <div class="col-md-6 col-xs-12">
                <label for="post_gitname">发布到站点:</label>
                <input type="text" class="form-control" id="post_gitname" name="post_gitname" value="<?php echo $content_array['git_name']; ?>" readonly>
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
        $ctx_id = isset($content_array['ctx_id']) ? $content_array['ctx_id'] : str_replace('.','',uniqid(time(), true));

        // save record in local 
        file_put_contents(LocalPATH .'/'. $savedir .'/'. $ctx_id . '.json', $_POST['post_json']);

        // $wholeLogFile = file_get_contents($logFile);
        // $extract_url = $content_array['keyword'];
        // $concat_col = $content_array['status'].'|'.$content_array['git_name'].'|'.$content_array['pubdir'].'|'.$content_array['lang'];

        $db_name = 'sitedata.sqlite';
        $table_name = 'leaguesmonitorlist';
        $output_name = 'leagues_monitor_list.txt';
        $keyword_columns = ['ctx_id', 'keyword', 'status', 'git_name', 'pubdir', 'lang', 'json'];

        $query_column = ['keyword','git_name'];

        $renew_columns = ['ctx_id', 'keyword', 'status', 'git_name', 'pubdir', 'lang', 'geo', 'lasttask', 'json', 'time'];
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