<?php

if (!file_exists('global_config.php')) {
    die('file global_config.php not found.');
}

$config = include 'global_config.php';

ini_set('memory_limit', '256M');

// Database configuration
$dbFile = $config['base']['database'] ? $config['base']['database'] : 'sitedata.sqlite';

// Initialize data arrays
$data = [];
$summaryByDomain = [];
$summaryByDate = [];
$summaryByStatus = [];

if (file_exists($dbFile) && is_readable($dbFile)) {
    try {
        $db = new SQLite3($dbFile);
        $db->enableExceptions(true);

        // Fetch all records from sitetopic table
        $query = "SELECT id, ctx_id, git_name, domain, keyword, pubdir, status, lang, geo, lasttask, json, time
                  FROM sitetopic
                  ORDER BY lasttask DESC, domain ASC";
        $result = $db->query($query);

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $data[] = $row;

            // Summary by domain
            $domain = $row['domain'];
            if (!isset($summaryByDomain[$domain])) {
                $summaryByDomain[$domain] = ['total' => 0, 'aidone' => 0, 'enable' => 0, 'other' => 0];
            }
            $summaryByDomain[$domain]['total']++;
            $status = $row['status'];
            if ($status === 'aidone') {
                $summaryByDomain[$domain]['aidone']++;
            } elseif ($status === 'enable') {
                $summaryByDomain[$domain]['enable']++;
            } else {
                $summaryByDomain[$domain]['other']++;
            }

            // Summary by date (lasttask) - only process valid YYYYMMDD dates
            $taskDate = '';
            if (!empty($row['lasttask']) && strlen($row['lasttask']) >= 8) {
                $taskDate = $row['lasttask'];
                // $taskDate = substr($row['lasttask'], 0, 8);
                // Validate it's a proper date format (YYYYMMDD)
                // if (!preg_match('/^\d{8}$/', $taskDate)) {
                //     $taskDate = '';
                // }
            }
            if ($taskDate !== '') {
                if (!isset($summaryByDate[$taskDate])) {
                    $summaryByDate[$taskDate] = ['total' => 0, 'aidone' => 0, 'enable' => 0, 'other' => 0];
                }
                $summaryByDate[$taskDate]['total']++;
                if ($status === 'aidone') {
                    $summaryByDate[$taskDate]['aidone']++;
                } elseif ($status === 'enable') {
                    $summaryByDate[$taskDate]['enable']++;
                } else {
                    $summaryByDate[$taskDate]['other']++;
                }
            }

            // Summary by status
            if (!isset($summaryByStatus[$status])) {
                $summaryByStatus[$status] = 0;
            }
            $summaryByStatus[$status]++;
        }

        $result->finalize();
        $db->close();

        // Sort summary arrays
        ksort($summaryByDate);
        $summaryByDate = array_reverse($summaryByDate, true); // Most recent first, preserve keys
        arsort($summaryByDomain);

    } catch (SQLite3Exception $e) {
        $error = "数据库错误：" . $e->getMessage();
    }
} else {
    $error = "数据库文件不存在或不可读：" . $dbFile;
}

// Pagination for detail table
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20;
$totalRecords = count($data);
$totalPages = ceil($totalRecords / $perPage);
$currentPage = max(1, min($currentPage, $totalPages));
$offset = ($currentPage - 1) * $perPage;
$paginatedData = array_slice($data, $offset, $perPage);

// Search functionality - search all fields
$searchKeyword = isset($_GET['search']) ? trim($_GET['search']) : '';
if ($searchKeyword !== '') {
    $filteredData = array_filter($data, function($record) use ($searchKeyword) {
        return stripos($record['keyword'], $searchKeyword) !== false ||
               stripos($record['domain'], $searchKeyword) !== false ||
               stripos($record['status'], $searchKeyword) !== false ||
               stripos($record['git_name'], $searchKeyword) !== false ||
               stripos($record['pubdir'], $searchKeyword) !== false ||
               stripos($record['lang'], $searchKeyword) !== false ||
               stripos($record['geo'], $searchKeyword) !== false ||
               stripos($record['lasttask'], $searchKeyword) !== false ||
               stripos((string)$record['id'], $searchKeyword) !== false;
    });
    $paginatedData = array_slice($filteredData, $offset, $perPage);
    $totalRecords = count($filteredData);
    $totalPages = ceil($totalRecords / $perPage);
}

// Get current tab from URL parameter
$currentTab = isset($_GET['tab']) ? $_GET['tab'] : 'date';

?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <title>话题任务数据表</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            primary: '#3b82f6',
            success: '#22c55e',
            warning: '#f59e0b',
            danger: '#ef4444',
          }
        }
      }
    }
  </script>
</head>
<body class="bg-gray-50 min-h-screen">
<div class="container mx-auto px-4 py-8 max-w-7xl">
    <h1 class="text-3xl font-bold text-gray-800 mb-6">话题任务数据表</h1>

    <?php if (isset($error)): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6">
        <?php echo htmlspecialchars($error); ?>
    </div>
    <?php else: ?>

    <!-- 汇总统计卡片 -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-xl shadow-sm p-6 text-center border border-gray-100">
            <h5 class="text-sm font-medium text-gray-500 mb-2">总记录数</h5>
            <div class="text-3xl font-bold text-primary"><?php echo number_format(count($data)); ?></div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-6 text-center border border-gray-100">
            <h5 class="text-sm font-medium text-gray-500 mb-2">已完成 (aidone)</h5>
            <div class="text-3xl font-bold text-success"><?php echo number_format($summaryByStatus['aidone'] ?? 0); ?></div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-6 text-center border border-gray-100">
            <h5 class="text-sm font-medium text-gray-500 mb-2">待处理 (enable)</h5>
            <div class="text-3xl font-bold text-primary"><?php echo number_format($summaryByStatus['enable'] ?? 0); ?></div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-6 text-center border border-gray-100">
            <h5 class="text-sm font-medium text-gray-500 mb-2">域名数量</h5>
            <div class="text-3xl font-bold text-warning"><?php echo number_format(count($summaryByDomain)); ?></div>
        </div>
    </div>

    <!-- 选项卡导航 -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 mb-6">
        <div class="border-b border-gray-200">
            <nav class="flex -mb-px">
                <a href="?tab=date<?php echo $searchKeyword !== '' ? '&search=' . urlencode($searchKeyword) : ''; ?>" 
                   id="tab-btn-date" 
                   class="tab-btn px-6 py-4 text-sm font-medium border-b-2 <?php echo $currentTab === 'date' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
                    按日期汇总
                </a>
                <a href="?tab=domain<?php echo $searchKeyword !== '' ? '&search=' . urlencode($searchKeyword) : ''; ?>" 
                   id="tab-btn-domain" 
                   class="tab-btn px-6 py-4 text-sm font-medium border-b-2 <?php echo $currentTab === 'domain' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
                    按域名汇总
                </a>
                <a href="?tab=detail<?php echo $searchKeyword !== '' ? '&search=' . urlencode($searchKeyword) : ''; ?>" 
                   id="tab-btn-detail" 
                   class="tab-btn px-6 py-4 text-sm font-medium border-b-2 <?php echo $currentTab === 'detail' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
                    详细数据
                </a>
            </nav>
        </div>

        <div class="p-6">
            <!-- 按日期汇总 -->
            <div id="tab-content-date" class="tab-content <?php echo $currentTab !== 'date' ? 'hidden' : ''; ?>">
                <div class="mb-4 flex items-center justify-between">
                    <input type="text" id="search-date" onkeyup="filterTable('table-date', this.value)" 
                           placeholder="搜索日期..." 
                           class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                </div>
                <div class="overflow-x-auto">
                    <table id="table-date" class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" onclick="sortTable('table-date', 0, 'date')">
                                    日期
                                    <span class="ml-1">↕</span>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" onclick="sortTable('table-date', 1, 'number')">
                                    总数
                                    <span class="ml-1">↕</span>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" onclick="sortTable('table-date', 2, 'number')">
                                    已完成
                                    <span class="ml-1">↕</span>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" onclick="sortTable('table-date', 3, 'number')">
                                    待处理
                                    <span class="ml-1">↕</span>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" onclick="sortTable('table-date', 4, 'number')">
                                    其他
                                    <span class="ml-1">↕</span>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    完成率
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($summaryByDate as $date => $stats): ?>
                            <?php
                                $completionRate = $stats['total'] > 0
                                    ? round(($stats['aidone'] + $stats['enable']) / $stats['total'] * 100, 2)
                                    : 0;
                            ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($date); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $stats['total']; ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-success font-medium"><?php echo $stats['aidone']; ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-primary font-medium"><?php echo $stats['enable']; ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $stats['other']; ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <div class="flex items-center">
                                        <div class="flex-1 w-32 bg-gray-200 rounded-full h-2.5 mr-2">
                                            <div class="bg-success h-2.5 rounded-full" style="width: <?php echo round($stats['aidone'] / $stats['total'] * 100, 2); ?>%"></div>
                                        </div>
                                        <span class="text-xs text-gray-600"><?php echo $completionRate; ?>%</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 按域名汇总 -->
            <div id="tab-content-domain" class="tab-content <?php echo $currentTab !== 'domain' ? 'hidden' : ''; ?>">
                <div class="mb-4 flex items-center justify-between">
                    <input type="text" id="search-domain" onkeyup="filterTable('table-domain', this.value)" 
                           placeholder="搜索域名..." 
                           class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                </div>
                <div class="overflow-x-auto">
                    <table id="table-domain" class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" onclick="sortTable('table-domain', 0, 'string')">
                                    域名
                                    <span class="ml-1">↕</span>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" onclick="sortTable('table-domain', 1, 'number')">
                                    总数
                                    <span class="ml-1">↕</span>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" onclick="sortTable('table-domain', 2, 'number')">
                                    已完成
                                    <span class="ml-1">↕</span>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" onclick="sortTable('table-domain', 3, 'number')">
                                    待处理
                                    <span class="ml-1">↕</span>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" onclick="sortTable('table-domain', 4, 'number')">
                                    其他
                                    <span class="ml-1">↕</span>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    完成率
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($summaryByDomain as $domain => $stats): ?>
                            <?php
                                $completionRate = $stats['total'] > 0
                                    ? round(($stats['aidone'] + $stats['enable']) / $stats['total'] * 100, 2)
                                    : 0;
                            ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($domain); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $stats['total']; ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-success font-medium"><?php echo $stats['aidone']; ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-primary font-medium"><?php echo $stats['enable']; ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $stats['other']; ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <div class="flex items-center">
                                        <div class="flex-1 w-32 bg-gray-200 rounded-full h-2.5 mr-2">
                                            <div class="bg-success h-2.5 rounded-full" style="width: <?php echo round($stats['aidone'] / $stats['total'] * 100, 2); ?>%"></div>
                                        </div>
                                        <span class="text-xs text-gray-600"><?php echo $completionRate; ?>%</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 详细数据 -->
            <div id="tab-content-detail" class="tab-content <?php echo $currentTab !== 'detail' ? 'hidden' : ''; ?>">
                <div class="mb-4 flex items-center justify-between flex-wrap gap-4">
                    <form id="form-detail-search" method="get" class="flex items-center gap-2">
                        <input type="hidden" name="tab" value="detail">
                        <input type="text" id="search-detail" name="search" value="<?php echo htmlspecialchars($searchKeyword); ?>"
                               placeholder="搜索关键词、域名、状态、GIT 名称、发布目录、语言、区域、ID..."
                               class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent w-96">
                        <button type="submit" class="px-4 py-2 bg-primary text-white rounded-lg hover:bg-blue-600">
                            搜索
                        </button>
                        <?php if ($searchKeyword !== ''): ?>
                        <a href="?tab=detail" class="px-4 py-2 text-gray-600 hover:text-gray-800">
                            清除
                        </a>
                        <?php endif; ?>
                    </form>
                    <span id="search-status" class="text-sm text-gray-500 hidden">搜索中...</span>
                </div>
                <div class="overflow-x-auto">
                    <table id="table-detail" class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" onclick="sortTable('table-detail', 0, 'number')">
                                    ID
                                    <span class="ml-1">↕</span>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" onclick="sortTable('table-detail', 1, 'string')">
                                    话题关键词
                                    <span class="ml-1">↕</span>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" onclick="sortTable('table-detail', 2, 'string')">
                                    域名
                                    <span class="ml-1">↕</span>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" onclick="sortTable('table-detail', 3, 'string')">
                                    状态
                                    <span class="ml-1">↕</span>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" onclick="sortTable('table-detail', 4, 'string')">
                                    GIT 名称
                                    <span class="ml-1">↕</span>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" onclick="sortTable('table-detail', 5, 'string')">
                                    发布目录
                                    <span class="ml-1">↕</span>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" onclick="sortTable('table-detail', 6, 'date')">
                                    最后任务
                                    <span class="ml-1">↕</span>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" onclick="sortTable('table-detail', 7, 'string')">
                                    语言
                                    <span class="ml-1">↕</span>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" onclick="sortTable('table-detail', 8, 'string')">
                                    区域
                                    <span class="ml-1">↕</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($paginatedData as $record): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($record['id']); ?></td>
                                <td class="px-6 py-4 text-sm text-gray-900 max-w-xs truncate" title="<?php echo htmlspecialchars($record['keyword']); ?>"><a href="<?php echo 'topicops.php?eid='.urlencode($record['ctx_id']);?>" target="_blank">
                                    <?php echo htmlspecialchars($record['keyword']); ?></a></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($record['domain']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <?php if ($record['status'] === 'aidone'): ?>
                                        <span class="px-2 py-1 text-xs font-medium bg-green-100 text-green-800 rounded-full">已完成</span>
                                    <?php elseif ($record['status'] === 'enable'): ?>
                                        <span class="px-2 py-1 text-xs font-medium bg-blue-100 text-blue-800 rounded-full">待处理</span>
                                    <?php else: ?>
                                        <span class="px-2 py-1 text-xs font-medium bg-gray-100 text-gray-800 rounded-full"><?php echo htmlspecialchars($record['status']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($record['git_name']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($record['pubdir']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php
                                        $lasttask = $record['lasttask'];
                                        if (is_numeric($lasttask) && strlen($lasttask) >= 8) {
                                            echo htmlspecialchars(substr($lasttask, 0, 8));
                                        } else {
                                            echo htmlspecialchars($lasttask);
                                        }
                                    ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($record['lang']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($record['geo']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 分页 -->
                <?php if ($totalPages > 1): ?>
                <div class="mt-4 flex items-center justify-between">
                    <div class="text-sm text-gray-600">
                        显示 <?php echo $offset + 1; ?> - <?php echo min($offset + $perPage, $totalRecords); ?> 条，共 <?php echo $totalRecords; ?> 条记录
                    </div>
                    <div class="flex gap-1">
                        <?php if ($currentPage > 1): ?>
                        <a href="?tab=detail&page=<?php echo $currentPage - 1; ?><?php echo $searchKeyword !== '' ? '&search=' . urlencode($searchKeyword) : ''; ?>"
                           class="px-3 py-1 text-sm border border-gray-300 rounded hover:bg-gray-50">上一页</a>
                        <?php endif; ?>

                        <?php
                        $startPage = max(1, $currentPage - 2);
                        $endPage = min($totalPages, $currentPage + 2);
                        for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                        <a href="?tab=detail&page=<?php echo $i; ?><?php echo $searchKeyword !== '' ? '&search=' . urlencode($searchKeyword) : ''; ?>"
                           class="px-3 py-1 text-sm border rounded <?php echo $i === $currentPage ? 'bg-primary text-white border-primary' : 'border-gray-300 hover:bg-gray-50'; ?>">
                            <?php echo $i; ?>
                        </a>
                        <?php endfor; ?>

                        <?php if ($currentPage < $totalPages): ?>
                        <a href="?tab=detail&page=<?php echo $currentPage + 1; ?><?php echo $searchKeyword !== '' ? '&search=' . urlencode($searchKeyword) : ''; ?>"
                           class="px-3 py-1 text-sm border border-gray-300 rounded hover:bg-gray-50">下一页</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>

<script>
    // 防抖搜索 - 输入停止 2 秒后自动提交
    var searchTimeout;
    var searchInput = document.getElementById('search-detail');
    var searchForm = document.getElementById('form-detail-search');
    var searchStatus = document.getElementById('search-status');
    
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            var inputValue = this.value;
            
            // 清除之前的定时器
            clearTimeout(searchTimeout);
            
            // 显示搜索状态
            if (searchStatus) {
                searchStatus.classList.remove('hidden');
            }
            
            // 设置新的定时器，2 秒后提交
            searchTimeout = setTimeout(function() {
                if (searchForm) {
                    searchForm.submit();
                }
            }, 2000);
        });
    }

    // 表格搜索 - 只搜索第一列（用于按日期、域名 tab 的简单筛选）
    function filterTable(tableId, searchText) {
        var table = document.getElementById(tableId);
        var tr = table.getElementsByTagName('tr');
        var filter = searchText.toUpperCase();

        for (var i = 1; i < tr.length; i++) {
            var td = tr[i].getElementsByTagName('td')[0];
            if (td) {
                var txtValue = td.textContent || td.innerText;
                if (txtValue.toUpperCase().indexOf(filter) > -1) {
                    tr[i].style.display = '';
                } else {
                    tr[i].style.display = 'none';
                }
            }
        }
    }

    // 表格排序
    var sortDirections = {};
    function sortTable(tableId, n, type) {
        var table = document.getElementById(tableId);
        var rows = table.rows;
        var switched = false;
        var direction = sortDirections[tableId + '-' + n] || 'asc';

        for (var i = 1; i < (rows.length - 1); i++) {
            var shouldSwitch = false;
            var x = rows[i].getElementsByTagName('TD')[n];
            var y = rows[i + 1].getElementsByTagName('TD')[n];

            var xValue = x.textContent || x.innerText;
            var yValue = y.textContent || y.innerText;

            var compareResult;
            if (type === 'number') {
                compareResult = parseFloat(xValue) - parseFloat(yValue);
            } else if (type === 'date') {
                compareResult = parseInt(xValue) - parseInt(yValue);
            } else {
                compareResult = xValue.localeCompare(yValue, 'zh-CN');
            }

            if (direction === 'asc') {
                shouldSwitch = compareResult > 0;
            } else {
                shouldSwitch = compareResult < 0;
            }

            if (shouldSwitch) {
                rows[i].parentNode.insertBefore(rows[i + 1], rows[i]);
                switched = true;
            }
        }

        // Toggle direction
        sortDirections[tableId + '-' + n] = direction === 'asc' ? 'desc' : 'asc';
    }
</script>
</body>
</html>
