<?php

ini_set('memory_limit', '256M');

// Include the function
function getEditRecordList($logFile) {
    if (!file_exists($logFile) || !is_readable($logFile)) {
        return []; // Early return if file not accessible
    }

    $alldata = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $output = [];

    foreach ($alldata as $lineNum => $lineCnt) {
        $lineCnt = trim($lineCnt);
        if ($lineCnt === '') continue;

        $parts = explode('|', $lineCnt, 8);
        if (count($parts) < 8) {
            // Skip malformed lines
            continue;
        }

        list($eid, $keyword, $status, $gitname, $domain, $pubdir, $lang, $jsonStr) = $parts;

        $postData = null;
        if ($jsonStr !== '' && ($decoded = json_decode($jsonStr, true)) !== null) {
            // JSON decoded correctly
            $postData = $decoded;
        } elseif (file_exists($jsonStr) && is_readable($jsonStr)) {
            // If jsonStr is a file path, try reading from it
            $jsonContent = file_get_contents($jsonStr);
            if ($jsonContent !== false) {
                $decoded = json_decode($jsonContent, true);
                if ($decoded !== null) {
                    $postData = $decoded;
                }
            }
        }

        // If postData is an array and has keys, use keyword and title fallback
        // title might be missing, so fall back to keyword
        $title = $keyword;
        if (is_array($postData)) {
            if (isset($postData['title']) && $postData['title'] !== '') {
                $title = $postData['title'];
            } elseif (isset($postData['keyword']) && $postData['keyword'] !== '') {
                $title = $postData['keyword'];
            }
        }
        
        // Process static thumbnail safely if exists
        // $static_thumbnail = '';
        // if (is_array($postData) && isset($postData['static_thumbnail']['text'][0])) {
        //     $static_thumbnail = preg_replace(
        //         '/(https?:\/\/)(?!(?:i[0-9]\.wp\.com\/))(.*)/iu',
        //         '$1i1.wp.com/$2',
        //         $postData['static_thumbnail']['text'][0]
        //     );
        // }

        $lasttask = is_array($postData) && isset($postData['lasttask']) ? $postData['lasttask'] : "";
        $geo = is_array($postData) && isset($postData['geo']) ? $postData['geo'] : "";

        // Build output array - add fields you actually want to return
        $output[] = [
            "eid" => $eid,
            "keyword" => $keyword,
            "title" => $title,
            "gitname" => $gitname,
            "domain" => $domain,
            "pubdir" => $pubdir,
            "status" => $status,
            "lang" => $lang,
            "geo" => $geo,
            "lasttask" => $lasttask,
        ];
    }

    return $output;
}


// $config = include 'global_config.php';
// $logFile = $config['base']['log_file'] ?? null;

$logFile = 'topic_monitor_list.txt';

if ($logFile === null) {
    die('Log file not set in config.');
}

// $logFile = 'editor_poker_allpost_list.txt';
if (file_exists($logFile) && !empty($logFile) ) {
    $data = getEditRecordList($logFile);
} else {
    $data = array();
}

// var_dump($data);

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <title>站点文章编辑列表</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="./css/bootstrap.min.css" rel="stylesheet">
  <script src="./js/bootstrap.min.js"></script>
  <!-- table -->
   <link rel="stylesheet" href="./font/bootstrap-icons.css">
    <link rel="stylesheet" href="./css/bootstrap-table.min.css">
</head>
<body>
<body>
<div class="container mt-5">
    <h2>文章列表</h2>
    <table
      data-toggle="table"
      data-search="true"
      data-show-columns="true"
      data-sort-name="eid"
      data-sort-order="desc"
      data-pagination="true">
      <thead>
        <tr class="tr-class-1">
          <th data-field="eid" data-custom-attribute="eid" data-valign="middle" data-sortable="true" data-formatter="timeFormatter">日期</th>
          <th data-field="keyword" data-custom-attribute="keyword" data-sortable="true" data-valign="middle">文章与话题</th>
          <th data-field="status" data-custom-attribute="status" data-sortable="true" data-valign="middle">状态</th>
          <th data-field="gitname" data-custom-attribute="gitname" data-sortable="true" data-valign="middle">发布GIT</th>
          <th data-field="domain" data-custom-attribute="domain" data-sortable="true" data-valign="middle">发布域名</th>
          <th data-field="pubdir" data-custom-attribute="pubdir" data-sortable="true" data-valign="middle">发布目录</th>
          <th data-field="lasttask" data-custom-attribute="lasttask" data-sortable="true" data-valign="middle" data-visible="true" >发布时间</th>
          <th data-field="lang" data-custom-attribute="lang" data-sortable="true" data-valign="middle" data-visible="true" >语言</th>
          <th data-field="geo" data-custom-attribute="geo" data-sortable="true" data-valign="middle" data-visible="true" >区域</th>
          <th data-field="description">操作</th>
        </tr>
      </thead>
      <tbody>
        
        <?php foreach ($data as $record): ?>
        <tr id="tr-id-2" class="tr-class-2">
          <td > <?php echo htmlspecialchars($record['eid']); ?></td>
          <td > <?php echo htmlspecialchars($record['keyword']); ?></td>
          <td > <?php echo htmlspecialchars($record['status']); ?></td>
          <td > <?php echo htmlspecialchars($record['gitname']); ?></td>
          <td > <?php echo htmlspecialchars($record['domain']); ?></td>
          <td > <?php echo htmlspecialchars($record['pubdir']); ?></td>
          <td > <?php echo htmlspecialchars($record['lasttask']); ?></td>
          <td > <?php echo htmlspecialchars($record['lang']); ?></td>
          <td > <?php echo htmlspecialchars($record['geo']); ?></td>
          <td>
            <?php if (file_exists("topicedit.php")) :?>
            <a target="_blank" href="topicedit.php?eid=<?php echo htmlspecialchars($record['eid']); ?>">编辑</a>
            <?php endif ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
</div>
<script>
    function timeFormatter(value) {

    const date = new Date(value.slice(0, 10) * 1000); // Multiply by 1000 to convert to milliseconds

    // Format the date into a human-readable string
    const formattedDate = date.toLocaleString();

    return formattedDate 
    };
    function dateFormatter(value) {
      const date = new Date(value);
      return date.toLocaleString('zh-TW', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false
      });
    }
</script>
    <script src="./js/jquery.min.js"></script>
    <script src="./js/bootstrap.bundle.min.js"></script>
    <script src="./js/bootstrap-table.min.js"></script>
</body>
</html>