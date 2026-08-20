<?php
/**
 * install.php — 安装 / 迁移程序
 *
 * 功能：
 *   1. 环境检查（PHP 版本、SQLite3 扩展、目录可写性）
 *   2. 数据库配置：目标数据库文件名（写入 global_config.php base.database）
 *   3. 数据表新建：系统表（users/roles/permissions/...）+ 业务表
 *      （siteops / serverlist / sitetopic / keywordmonitorlist / article）
 *   4. 数据迁移：检测已有旧库，缺失列自动补列（ALTER TABLE ADD COLUMN），数据保留
 *   5. 可选：创建管理员账号、配置静态 API token（api_csrf_tokens）
 *
 * 安全：
 *   - 表单携带 CSRF token（基于 csrf secret 的 HMAC）
 *   - 安装完成后生成 install.lock；存在 lock 时拒绝重新安装
 *     如需重装/迁移，请手工删除 install.lock 后刷新页面（操作幂等，数据保留）
 */

define('APP_PATH', __DIR__);
require __DIR__ . '/app/bootstrap.php';

use App\Config;
use App\Database;

$root         = APP_PATH;
$lockFile     = $root . '/install.lock';
$configFile   = Config::configFile();
$request      = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

/**
 * 视图辅助
 */
function ih($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function installToken()
{
    return hash_hmac('sha256', 'siteops-install-form', Config::csrfSecret());
}

/**
 * 环境检查
 */
function envChecks()
{
    $checks = [];
    $checks['php']    = ['ok' => version_compare(PHP_VERSION, '8.0.0', '>='), 'label' => 'PHP ' . PHP_VERSION . '（需要 >= 8.0）'];
    $checks['sqlite'] = ['ok' => class_exists('SQLite3'), 'label' => 'SQLite3 扩展（php-sqlite3）'];
    $checks['root']   = ['ok' => is_writable(APP_PATH), 'label' => '程序目录 ' . APP_PATH . ' 可写'];
    $checks['config'] = ['ok' => is_file(Config::configFile()) && is_writable(Config::configFile()), 'label' => 'global_config.php 可写'];
    $varDir          = Config::varDir();
    $checks['var']    = ['ok' => is_writable($varDir) || is_writable(APP_PATH), 'label' => 'var 目录可写（' . $varDir . '）'];
    return $checks;
}

/**
 * 解析数据库路径：相对路径基于程序根目录
 */
function dbPath($name)
{
    $name = trim((string)$name);
    if ($name === '') {
        $name = 'sitedata.sqlite';
    }
    if (isset($name[0]) && ($name[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $name))) {
        return $name;
    }
    return APP_PATH . '/' . ltrim($name, '/');
}

/**
 * 业务表结构（唯一定义处；与既有生产库保持一致）
 */
function businessTables()
{
    return [
        'siteops' => 'CREATE TABLE IF NOT EXISTS "siteops" (
            "id" INTEGER NOT NULL UNIQUE,
            "ctx_id" VARCHAR NOT NULL UNIQUE,
            "git_name" VARCHAR NOT NULL UNIQUE,
            "domain" VARCHAR NOT NULL UNIQUE,
            "site_title" VARCHAR, "site_subtitle" VARCHAR, "site_logo" VARCHAR,
            "languages" VARCHAR, "sns_id" VARCHAR, "topnav_menus" VARCHAR,
            "keyword" VARCHAR, "theme_name" VARCHAR, "theme_type" VARCHAR,
            "sitedir" VARCHAR, "deploy" VARCHAR, "hostip" VARCHAR,
            "local_deploy" VARCHAR, "local_hostip" VARCHAR, "status" VARCHAR,
            "json" VARCHAR, "time" DATETIME, "git_account" VARCHAR,
            PRIMARY KEY("id" AUTOINCREMENT))',
        'serverlist' => 'CREATE TABLE IF NOT EXISTS "serverlist" (
            "id" INTEGER NOT NULL,
            "ctx_id" VARCHAR NOT NULL UNIQUE,
            "git_name" TEXT UNIQUE,
            "domain" VARCHAR, "site_title" VARCHAR, "site_subtitle" VARCHAR,
            "site_logo" VARCHAR, "languages" VARCHAR, "sns_id" VARCHAR,
            "topnav_menus" VARCHAR, "keyword" VARCHAR, "theme_name" VARCHAR,
            "theme_type" VARCHAR, "sitedir" VARCHAR, "deploy" VARCHAR,
            "hostip" VARCHAR, "local_deploy" VARCHAR, "local_hostip" VARCHAR,
            "status" VARCHAR, "json" VARCHAR, "time" DATETIME,
            PRIMARY KEY("id" AUTOINCREMENT))',
        'article' => 'CREATE TABLE IF NOT EXISTS "article" (
            "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            "ctx_id" VARCHAR UNIQUE NOT NULL,
            "url" VARCHAR, "title" VARCHAR, "keyword" VARCHAR, "tags" VARCHAR,
            "description" VARCHAR, "static_thumbnail" VARCHAR, "iframesrc" VARCHAR,
            "lang" VARCHAR, "series" VARCHAR, "pubdir" VARCHAR, "savename" VARCHAR,
            "globalpublish" VARCHAR, "pubdomain" VARCHAR, "translate_to_langs" VARCHAR,
            "content" TEXT, "json" TEXT, "json_file" VARCHAR,
            "time" DATETIME, "update_date" DATETIME)',
    ];
}

/**
 * 各表期望列（迁移时缺失自动补列）
 */
function expectedColumns()
{
    return [
        'siteops' => [
            'id', 'ctx_id', 'git_name', 'domain', 'site_title', 'site_subtitle',
            'site_logo', 'languages', 'sns_id', 'topnav_menus', 'keyword',
            'theme_name', 'theme_type', 'sitedir', 'deploy', 'hostip',
            'local_deploy', 'local_hostip', 'status', 'json', 'time', 'git_account',
        ],
        'serverlist' => [
            'id', 'ctx_id', 'git_name', 'domain', 'site_title', 'site_subtitle',
            'site_logo', 'languages', 'sns_id', 'topnav_menus', 'keyword',
            'theme_name', 'theme_type', 'sitedir', 'deploy', 'hostip',
            'local_deploy', 'local_hostip', 'status', 'json', 'time',
        ],
        'sitetopic' => [
            'id', 'ctx_id', 'git_name', 'domain', 'keyword', 'pubdir',
            'status', 'lang', 'geo', 'lasttask', 'json', 'time',
        ],
        'keywordmonitorlist' => [
            'id', 'ctx_id', 'git_name', 'keyword', 'pubdir', 'status',
            'lang', 'geo', 'lasttask', 'json', 'time',
        ],
        'article' => [
            'id', 'ctx_id', 'url', 'title', 'keyword', 'tags', 'description',
            'static_thumbnail', 'iframesrc', 'lang', 'series', 'pubdir',
            'savename', 'globalpublish', 'pubdomain', 'translate_to_langs',
            'content', 'json', 'json_file', 'time', 'update_date',
        ],
    ];
}

function tableExists(SQLite3 $db, $table)
{
    $list = $db->query("SELECT 1 FROM \"sqlite_master\" WHERE \"type\" = 'table' AND \"name\" = '" . $db->escapeString($table) . "'");
    return $list !== false && $list->fetchArray() !== false;
}

function missingColumns(SQLite3 $db, $table, $expected)
{
    if (!tableExists($db, $table)) {
        return [];
    }
    $result = $db->query('PRAGMA table_info("' . $db->escapeString($table) . '")');
    $have = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $have[$row['name']] = true;
    }
    $missing = [];
    foreach ($expected as $column) {
        if (!isset($have[$column])) {
            $missing[] = $column;
        }
    }
    return $missing;
}

/**
 * 各表性能索引（迁移时自动补建；与 Repository::ensureTable 保持一致）
 */
function expectedIndexes()
{
    return [
        'sitetopic' => [
            'idx_sitetopic_status'  => 'CREATE INDEX IF NOT EXISTS "idx_sitetopic_status" ON "sitetopic" ("status")',
            'idx_sitetopic_keyword' => 'CREATE INDEX IF NOT EXISTS "idx_sitetopic_keyword" ON "sitetopic" ("keyword")',
            'idx_sitetopic_domain'  => 'CREATE INDEX IF NOT EXISTS "idx_sitetopic_domain" ON "sitetopic" ("domain")',
        ],
    ];
}

function missingIndexes(SQLite3 $db, $table, array $indexes)
{
    if (!tableExists($db, $table)) {
        return [];
    }
    $result = $db->query("SELECT \"name\" FROM \"sqlite_master\" WHERE \"type\" = 'index' AND \"tbl_name\" = '" . $db->escapeString($table) . "'");
    $have = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $have[$row['name']] = true;
    }
    $missing = [];
    foreach ($indexes as $name => $sql) {
        if (!isset($have[$name])) {
            $missing[$name] = $sql;
        }
    }
    return $missing;
}

/**
 * 写入 global_config.php：base.database 与 base.api_csrf_tokens
 */
function writeConfig($dbName, array $apiTokens)
{
    $errors = [];
    $content = @file_get_contents(Config::configFile());
    if ($content === false) {
        $errors[] = '无法读取 global_config.php';
        return $errors;
    }
    $changed = [];
    $escaped = addslashes($dbName);
    $patched = preg_replace("/('database'\s*=>\s*)'[^']*'/", '$1\'' . $escaped . '\'', $content, 1, $count);
    if ($count === 1) {
        $content = $patched;
        $changed[] = 'database';
    } else {
        $errors[] = 'global_config.php 中未找到 base.database 配置项';
    }
    if (count($apiTokens) > 0) {
        $listExpr = var_export(array_values($apiTokens), true);
        $hasActive = (bool)preg_match("/^\s{4}'api_csrf_tokens'\s*=>\s*\[/m", $content);
        $patched = '';
        if ($hasActive) {
            $patched = preg_replace(
                "/(\s*'api_csrf_tokens'\s*=>\s*\[)[^\]]*(\])/",
                '$1' . $listExpr . '$2',
                $content,
                1,
                $count2
            );
        } else {
            $commented = preg_replace(
                "/\/\/\s*'api_csrf_tokens'\s*=>\s*\[[^\]]*\/\/\s*\],/",
                "    'api_csrf_tokens' => " . $listExpr . ',',
                $content,
                1,
                $count2
            );
            if ($count2 === 0) {
                $insert = "    'api_csrf_tokens' => " . $listExpr . ",\n";
                $commented = preg_replace("/(\s*'base'\s*=>\s*\[\n)/", '$1' . $insert, $content, 1, $count2);
            }
        }
        if ($count2 === 1) {
            $content = $commented;
            $changed[] = 'api_csrf_tokens';
        }
    }
    if (count($changed) > 0 && @file_put_contents(Config::configFile(), $content) === false) {
        $errors[] = '写入 global_config.php 失败，请检查文件权限';
    }
    if (count($changed) > 0) {
        Config::reset();
    }
    return $errors;
}

/**
 * 执行安装/迁移
 */
function runInstall($dbName, $adminUser, $adminPass, array $apiTokens)
{
    $dbPath      = dbPath($dbName);
    $notes       = [];
    $migrated    = [];

    $errors = writeConfig($dbName, $apiTokens);

    Config::overrideDbFile($dbPath);
    Database::reset();
    try {
        $db = Database::connection();
    } catch (\Throwable $e) {
        $errors[] = '打开数据库失败：' . $e->getMessage();
        return [$errors, $notes, $migrated, $dbPath];
    }

    foreach (businessTables() as $sql) {
        $db->exec($sql);
    }
    \App\Repositories\TopicRepository::ensureTable();
    \App\Repositories\KeywordRepository::ensureTable();
    \App\Repositories\ArticleRepository::ensureTable();

    $preSites     = 0;
    $preTopics    = 0;
    $preArticles  = 0;
    if (tableExists($db, 'siteops')) {
        $preSites = (int)$db->querySingle('SELECT COUNT(*) FROM "siteops"');
    }
    if (tableExists($db, 'sitetopic')) {
        $preTopics = (int)$db->querySingle('SELECT COUNT(*) FROM "sitetopic"');
    }
    if (tableExists($db, 'article')) {
        $preArticles = (int)$db->querySingle('SELECT COUNT(*) FROM "article"');
    }

    foreach (expectedColumns() as $table => $columns) {
        if (!tableExists($db, $table)) {
            continue;
        }
        $missing = missingColumns($db, $table, $columns);
        foreach ($missing as $column) {
            $db->exec('ALTER TABLE "' . $db->escapeString($table) . '" ADD COLUMN "' . $db->escapeString($column) . '" VARCHAR');
        }
        if (count($missing) > 0) {
            $migrated[$table] = $missing;
        }
    }

    $addedIndexes = [];
    foreach (expectedIndexes() as $table => $indexes) {
        if (!tableExists($db, $table)) {
            continue;
        }
        $missing = missingIndexes($db, $table, $indexes);
        foreach ($missing as $name => $sql) {
            $db->exec($sql);
            $addedIndexes[$table][] = $name;
        }
    }

    if ($preSites > 0 || $preTopics > 0 || $preArticles > 0) {
        $notes[] = '检测到已有数据：siteops ' . $preSites . ' 行，sitetopic ' . $preTopics . ' 行，article ' . $preArticles . ' 行，已保留并迁移。';
    }

    if ($adminUser !== '' && $adminPass !== '') {
        $count = (int)$db->querySingle('SELECT COUNT(*) FROM "users"');
        if ($count === 0) {
            $hash = password_hash($adminPass, PASSWORD_DEFAULT);
            $now  = date('Y-m-d H:i:s');
            $stmt = $db->prepare('INSERT INTO "users" ("username", "password_hash", "display_name", "status", "created_at", "updated_at") VALUES (:u, :p, :d, :s, :c, :u2)');
            $stmt->bindValue(':u', $adminUser);
            $stmt->bindValue(':p', $hash);
            $stmt->bindValue(':d', $adminUser);
            $stmt->bindValue(':s', 'active');
            $stmt->bindValue(':c', $now);
            $stmt->bindValue(':u2', $now);
            $stmt->execute();
            $userId = (int)$db->lastInsertRowID();
            $adminRoleId = $db->querySingle("SELECT \"id\" FROM \"roles\" WHERE \"name\" = 'admin'");
            if ($adminRoleId) {
                $db->exec('INSERT OR IGNORE INTO "user_roles" ("user_id", "role_id") VALUES (' . (int)$userId . ', ' . (int)$adminRoleId . ')');
            }
            $notes[] = '管理员账号 ' . $adminUser . ' 已创建（拥有 admin 角色）。';
        } else {
            $notes[] = '数据库已存在 ' . $count . ' 个用户，跳过管理员创建（保留原账号）。';
        }
    } elseif ($adminUser === '') {
        $notes[] = '未创建管理员账号（可稍后在 users.php 添加，或通过 env APP_AUTH_USER / APP_AUTH_PASSWORD 启用登录）。';
    }

    if (count($errors) === 0) {
        @file_put_contents(APP_PATH . '/install.lock', 'installed at ' . date('Y-m-d H:i:s') . PHP_EOL);
    }

    return [$errors, $notes, $migrated, $dbPath, $addedIndexes];
}

$errors    = [];
$notes     = [];
$migrated  = [];
$addedIndexes = [];
$dbPath    = '';
$wasInstalled = false;
$installedAt  = is_file($lockFile) ? (string)@file_get_contents($lockFile) : '';

if ($request === 'POST' && isset($_POST['do']) && $_POST['do'] === 'install') {
    $token = isset($_POST['install_token']) ? (string)$_POST['install_token'] : '';
    if (!hash_equals(installToken(), $token)) {
        $errors[] = '表单校验失败（CSRF token 无效），请刷新页面重试。';
    } elseif ($installedAt !== '') {
        $errors[] = '系统已安装（install.lock 存在）。如需重新安装/迁移，请先手工删除 ' . APP_PATH . '/install.lock 后刷新页面。';
    } elseif (!in_array(false, array_column(envChecks(), 'ok'), true)) {
        $dbName    = isset($_POST['db_file']) ? trim((string)$_POST['db_file']) : 'sitedata.sqlite';
        $adminUser = isset($_POST['admin_user']) ? trim((string)$_POST['admin_user']) : '';
        $adminPass = isset($_POST['admin_pass']) ? (string)$_POST['admin_pass'] : '';
        $rawTokens = isset($_POST['api_tokens']) ? (string)$_POST['api_tokens'] : '';
        $apiTokens = [];
        foreach (explode(',', str_replace(['，', "\n", ' '], ',', $rawTokens)) as $t) {
            $t = trim($t);
            if ($t !== '') {
                $apiTokens[] = $t;
            }
        }
        list($errors, $notes, $migrated, $dbPath, $addedIndexes) = runInstall($dbName, $adminUser, $adminPass, $apiTokens);
        $wasInstalled = true;
    }
}

$checks   = envChecks();
$allOk    = !in_array(false, array_column($checks, 'ok'), true);
$postName = isset($_POST['db_file']) ? trim((string)$_POST['db_file']) : '';
$dbName   = $postName !== '' ? $postName : 'sitedata.sqlite';
$existing = is_file(dbPath($dbName));
$token    = installToken();
$envDb    = getenv('APP_DB_FILE');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>安装 · HUGO 站点管理</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, "PingFang SC", "Microsoft YaHei", sans-serif; background: #f6f7f8; color: #1f2937; line-height: 1.6; }
.wrap { max-width: 720px; margin: 40px auto; padding: 0 16px; }
.card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 28px 32px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
h1 { font-size: 22px; margin-bottom: 6px; }
h2 { font-size: 16px; margin-bottom: 14px; color: #374151; border-bottom: 1px solid #eee; padding-bottom: 8px; }
.sub { color: #6b7280; font-size: 13px; margin-bottom: 18px; }
table.checks { width: 100%; border-collapse: collapse; }
table.checks td { padding: 6px 0; border-bottom: 1px dashed #eee; font-size: 14px; }
.ok { color: #16a34a; font-weight: 600; }
.bad { color: #dc2626; font-weight: 600; }
label { display: block; font-size: 14px; font-weight: 600; margin: 14px 0 4px; }
input[type=text], input[type=password] { width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; }
.hint { font-size: 12px; color: #6b7280; margin-top: 4px; }
button { margin-top: 22px; padding: 10px 28px; font-size: 15px; font-weight: 600; color: #fff; background: #2563eb; border: 0; border-radius: 6px; cursor: pointer; }
button:hover { background: #1d4ed8; }
.checkbox { display: flex; align-items: center; gap: 8px; margin-top: 14px; font-size: 14px; }
.checkbox input { accent-color: #2563eb; }
.alert { border-radius: 8px; padding: 12px 16px; font-size: 14px; margin-bottom: 16px; }
.alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
.alert-ok { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
.alert-info { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
ul { margin: 8px 0 0 20px; }
li { font-size: 14px; margin: 3px 0; }
.mono { font-family: Menlo, Consolas, monospace; font-size: 13px; background: #f3f4f6; padding: 1px 5px; border-radius: 4px; }
.success-icon { font-size: 40px; text-align: center; color: #16a34a; margin-bottom: 8px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>HUGO 站点管理 · 安装程序</h1>
    <div class="sub">安装 / 数据库配置 / 数据表新建 / 旧库迁移。安装完成后请删除本站点的 install.php。</div>

    <?php if ($wasInstalled): ?>
      <div class="success-icon">&#10004;</div>
      <?php if (count($errors) > 0): ?>
        <div class="alert alert-error"><strong>安装失败：</strong><ul><?php foreach ($errors as $e) { echo '<li>' . ih($e) . '</li>'; } ?></ul></div>
        <p style="font-size:14px;margin-top:10px">可返回 <a href="install.php" style="color:#2563eb">重新填写</a>。</p>
      <?php else: ?>
        <div class="alert alert-ok"><strong>安装完成！</strong> 数据库文件位于 <span class="mono"><?php echo ih($dbPath); ?></span></div>
        <?php if (count($notes) > 0): ?>
          <ul><?php foreach ($notes as $n) { echo '<li>' . ih($n) . '</li>'; } ?></ul>
        <?php endif; ?>
        <?php if (count($migrated) > 0): ?>
          <div class="alert alert-info" style="margin-top:14px"><strong>迁移补列：</strong>
            <?php foreach ($migrated as $table => $cols) { echo '<br>表 <span class="mono">' . ih($table) . '</span> 新增列：' . ih(implode(', ', $cols)); } ?>
          </div>
        <?php endif; ?>
        <?php if (count($addedIndexes) > 0): ?>
          <div class="alert alert-info" style="margin-top:14px"><strong>迁移补索引：</strong>
            <?php foreach ($addedIndexes as $table => $idxs) { echo '<br>表 <span class="mono">' . ih($table) . '</span> 新增索引：' . ih(implode(', ', $idxs)); } ?>
          </div>
        <?php endif; ?>
        <p style="font-size:14px;margin-top:14px">
          下一步：删除 <span class="mono">install.php</span>（可选），然后访问
          <a href="siteops.php" style="color:#2563eb">siteops.php</a> 开始使用。
          <?php if ($envDb !== false && $envDb !== ''): ?>
            <br><span class="hint">注意：环境变量 APP_DB_FILE=<?php echo ih($envDb); ?> 当前优先级高于配置文件，如需使用安装所选的数据库，请移除该环境变量。</span>
          <?php endif; ?>
        </p>
      <?php endif; ?>
    <?php else: ?>

      <?php if ($installedAt !== ''): ?>
        <div class="alert alert-info">
          <strong>系统已安装</strong>（<span class="mono">install.lock</span> 存在于 <?php echo ih($installedAt); ?>）。
          如需重新安装或对既有库执行迁移，请先在服务器上手工删除 <span class="mono">install.lock</span> 文件，然后刷新本页（操作幂等，已有数据保留）。
        </div>
      <?php endif; ?>

      <?php if (count($errors) > 0): ?>
        <div class="alert alert-error"><strong>无法安装：</strong><ul><?php foreach ($errors as $e) { echo '<li>' . ih($e) . '</li>'; } ?></ul></div>
      <?php endif; ?>

      <?php if ($existing): ?>
        <div class="alert alert-info">检测到数据库 <span class="mono"><?php echo ih($dbName); ?></span> 已存在（旧库），安装时将保留现有数据并自动迁移/补列。</div>
      <?php endif; ?>

      <h2>1. 环境检查</h2>
      <table class="checks">
        <?php foreach ($checks as $c): ?>
          <tr>
            <td><?php echo ih($c['label']); ?></td>
            <td style="text-align:right"><?php echo $c['ok'] ? '<span class="ok">通过</span>' : '<span class="bad">未通过</span>'; ?></td>
          </tr>
        <?php endforeach; ?>
      </table>

      <?php if (!$allOk): ?>
        <div class="alert alert-error" style="margin-top:14px">环境不满足安装条件，请修复后刷新重试。</div>
      <?php elseif ($installedAt !== ''): ?>
        <div class="alert alert-error" style="margin-top:14px">安装入口已锁定（install.lock 存在），请删除 <span class="mono">install.lock</span> 后刷新本页继续。</div>
      <?php else: ?>
        <h2 style="margin-top:26px">2. 数据库配置</h2>
        <form method="post" action="install.php">
          <input type="hidden" name="do" value="install">
          <input type="hidden" name="install_token" value="<?php echo ih($token); ?>">
          <label for="db_file">数据库文件（相对本站目录，或绝对路径）</label>
          <input type="text" id="db_file" name="db_file" value="<?php echo ih($dbName); ?>">
          <div class="hint">建议保持默认 sitedata.sqlite。若选择新文件名将创建全新数据库。</div>

          <h2 style="margin-top:18px">3. 管理员账号（可选）</h2>
          <label for="admin_user">管理员用户名</label>
          <input type="text" id="admin_user" name="admin_user" placeholder="例如 admin，留空则跳过">
          <label for="admin_pass">管理员密码</label>
          <input type="password" id="admin_pass" name="admin_pass" placeholder="留空则跳过（需与用户名一起填写）">

          <h2 style="margin-top:18px">4. 静态 API token（可选）</h2>
          <label for="api_tokens">api_csrf_tokens（逗号分隔，可多个）</label>
          <input type="text" id="api_tokens" name="api_tokens" placeholder="例如 a-very-long-random-token-1, token-2，留空则不启用">
          <div class="hint">配置后所有入口（页面/任务脚本）要求携带有效 token（<span class="mono">csrf_token</span> 字段或 <span class="mono">X-CSRF-Token</span> 头）或已登录会话；git 服务器 IP 白名单除外。</div>

          <button type="submit">开 始 安 装</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
</body>
</html>