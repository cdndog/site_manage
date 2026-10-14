<?php

declare(strict_types=1);

define('SOPS_TESTING', true);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ob_start();

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';
$tmpDir = __DIR__ . '/tmp';
$testDb = $tmpDir . '/test.sqlite';
$dataDir = $tmpDir . '/data';

if (is_dir($tmpDir)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
}
mkdir($dataDir, 0755, true);

putenv('APP_DB_FILE=' . $testDb);
putenv('APP_DATA_DIR=' . $dataDir);
putenv('APP_CSRF_SECRET=testcsrfsecret');
putenv('APP_API_CSRF_TOKENS=');
putenv('APP_DB_DSN=');
if (getenv('APP_DB_DSN') !== false && getenv('APP_DB_DSN') !== '') {
    // PG 模式：由 Database::connection() 负责建表，此处仅确保目录
    $db = null;
} else {
    $db = new SQLite3($testDb);
    $db->enableExceptions(true);
    $db->exec('CREATE TABLE "siteops" (
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
    PRIMARY KEY("id" AUTOINCREMENT))');
$db->exec('CREATE TABLE "serverlist" (
    "id" INTEGER NOT NULL,
    "ctx_id" VARCHAR NOT NULL UNIQUE,
    "git_name" TEXT UNIQUE,
    "domain" VARCHAR, "site_title" VARCHAR, "site_subtitle" VARCHAR,
    "site_logo" VARCHAR, "languages" VARCHAR, "sns_id" VARCHAR,
    "topnav_menus" VARCHAR, "keyword" VARCHAR, "theme_name" VARCHAR,
    "theme_type" VARCHAR, "sitedir" VARCHAR, "deploy" VARCHAR,
    "hostip" VARCHAR, "local_deploy" VARCHAR, "local_hostip" VARCHAR,
    "status" VARCHAR, "json" VARCHAR, "time" DATETIME,
    PRIMARY KEY("id" AUTOINCREMENT))');
$db->close();
}

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;

function test($name, $cond, $detail = '')
{
    if ($cond) {
        $GLOBALS['pass']++;
        echo "PASS  {$name}\n";
    } else {
        $GLOBALS['fail']++;
        echo "FAIL  {$name}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    }
}

function resetRequest()
{
    $_GET = [];
    $_POST = [];
    $_COOKIE = [];
    $_FILES = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['PHP_SELF'] = '/siteops.php';
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    App\Config::reset();
    App\Database::reset();
    App\Support\Security::reset();
}

function runRequest(array $get = [], array $post = [], $method = 'GET', array $cookie = [], $entry = '/siteops.php', array $files = [], $xCsrfToken = '')
{
    resetRequest();
    $_GET = $get;
    $_POST = $post;
    $_COOKIE = $cookie;
    $_FILES = $files;
    $_SERVER['REQUEST_METHOD'] = $method;
    if ($xCsrfToken !== '') {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $xCsrfToken;
    }
    ob_start();
    include dirname(__DIR__) . $entry;
    return ob_get_clean();
}

function db($sql)
{
    if (getenv('APP_DB_DSN') !== false && getenv('APP_DB_DSN') !== '') {
        if (preg_match('/^\s*(INSERT|UPDATE|DELETE|CREATE|DROP|ALTER)/i', $sql)) {
            \App\Database::execute($sql);
            return [];
        }
        return \App\Database::fetchAll($sql);
    }
    $db = new SQLite3($GLOBALS['testDb']);
    if (preg_match('/^\s*(INSERT|UPDATE|DELETE|CREATE|DROP|ALTER)/i', $sql)) {
        $db->exec($sql);
        $db->close();
        return [];
    }
    $result = $db->query($sql);
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    $db->close();
    return $rows;
}

function exportLines()
{
    $file = $GLOBALS['dataDir'] . '/siteops_setting.txt';
    if (!file_exists($file)) {
        return [];
    }
    return array_values(array_filter(file($file, FILE_IGNORE_NEW_LINES), function ($line) {
        return trim($line) !== '';
    }));
}

function validPost(array $overrides = [])
{
    $base = [
        'post_uuid' => 'TESTUUID001',
        'post_gitname' => 'testpoker',
        'post_gitaccount' => 'felangel79',
        'post_domain' => 'testpoker.com',
        'post_sitetitle' => 'Test Poker Site',
        'post_description' => 'Test site description',
        'post_sitelogo' => 'https://img.example.com/logo.png',
        'post_sitedeploy' => 'cloudflare',
        'post_sitehostip' => '',
        'post_sns_id' => 'testpoker',
        'post_topnavmenus' => 'poker,texas',
        'post_keyword' => 'poker,texas holdem',
        'post_lang' => 'en',
        'post_sitetype' => 'cta',
        'post_themename' => 'testpoker',
        'post_themetype' => 'poker',
        'post_status' => 'done',
        'post_json' => '{"content":{"oldhtml":"keep-me"},"extra":"kept"}',
        'setupNum' => 'ckeditorFormated',
    ];
    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($base[$key]);
        } else {
            $base[$key] = $value;
        }
    }
    return $base;
}

echo "== GET: default form ==\n";
$html = runRequest();
test('GET renders form container', strpos($html, '<form action="/siteops.php" method="post" id="wechatpost"') !== false);
test('GET default themetype=poker selected', preg_match('/id="post_themetype"[^>]*>.*?<option data-subtext="poker"[^>]*>\s*poker/s', $html) === 1);
test('GET default sitetype=cta selected', strpos($html, strtolower('cta') . ' ? \'selected\'') !== false || preg_match('/<option data-subtext="cta"\s+selected\s*>\s*cta/', $html) === 1);
test('GET default deploy=cloudflare selected', strpos($html, 'value="cloudflare" selected') !== false);
preg_match('/id="post_uuid"[^>]*value="([^"]+)"/', $html, $m1);
test('GET default uuid generated', isset($m1[1]) && strlen($m1[1]) > 10, $m1[1] ?? 'no uuid');
preg_match('/id="post_uuid"[^>]*value="([^"]+)"/', runRequest(), $m2);
test('GET uuid differs between requests', $m1[1] !== $m2[1], ($m1[1] ?? '') . ' vs ' . ($m2[1] ?? ''));
test('GET with empty serverlist works', strpos($html, 'select deploy server ip') !== false);

echo "== header/footer modules ==\n";
$mods = App\Config::headerModules();
$flattenModules = function ($list) use (&$flattenModules) {
    $out = [];
    foreach ($list as $m) {
        $out[] = $m;
        if (isset($m['children']) && is_array($m['children'])) {
            $out = array_merge($out, $flattenModules($m['children']));
        }
    }
    return $out;
};
$flatMods = $flattenModules($mods);
$flatUrls = array_map(function ($m) { return isset($m['url']) ? $m['url'] : ''; }, $flatMods);
test('headerModules grouped by 7 domains', is_array($mods) && count($mods) === 7 && $mods[0]['title'] === '数据看板' && $mods[0]['url'] === 'dash.php' && $mods[0]['perm'] === 'report.view' && $mods[1]['title'] === '站点管理' && $mods[2]['title'] === '关键词管理' && $mods[3]['title'] === '话题管理' && $mods[4]['title'] === '文章管理' && $mods[5]['title'] === '发布管理' && $mods[6]['title'] === '系统管理');
test('headerModules contains all entries', in_array('dash.php', $flatUrls, true) && in_array('siteops.php', $flatUrls, true) && in_array('seo_report.php?reporttype=sitelist', $flatUrls, true) && in_array('keywordops.php', $flatUrls, true) && in_array('seo_report.php?reporttype=wordlist', $flatUrls, true) && in_array('seo_report.php?reporttype=relateword', $flatUrls, true) && in_array('topicops.php', $flatUrls, true) && in_array('topiclist.php', $flatUrls, true) && in_array('topictable.php', $flatUrls, true) && in_array('article_new.php', $flatUrls, true) && in_array('article_list.php', $flatUrls, true) && in_array('publish_list.php', $flatUrls, true));
$html = runRequest();
test('header module nav renders', strpos($html, 'sops-sidebar-link') !== false);
foreach ($flatUrls as $url) {
    if ($url === 'publish_edit.php') continue; // hidden breadcrumb only
    test('header lists module entry: ' . $url, strpos($html, 'href="' . $url . '"') !== false);
}
test('header active state on current page', preg_match('/active" href="siteops\.php"/', $html) === 1, 'no active class');
test('header escapes module title', strpos($html, e($mods[0]['title'])) !== false);
test('footer module renders', strpos($html, '<footer class="footer footer-sops') !== false);
test('form wrapped in card module', strpos($html, 'class="card card-sops"') !== false && strpos($html, '站点录入') !== false);
test('icons font asset present', file_exists($root . '/css/fonts/bootstrap-icons.woff2') && file_exists($root . '/css/fonts/bootstrap-icons.woff') && strpos((string)file_get_contents($root . '/css/bootstrap-icons.css'), 'url("./fonts/bootstrap-icons.woff2') !== false);
test('GET form container still after header', strpos($html, '<form action="/siteops.php" method="post" id="wechatpost"') !== false);

echo "== POST: insert ==\n";
$html = runRequest([], validPost(), 'POST');
test('POST renders confirm page', strpos($html, '录入网站文章标题') !== false && strpos($html, 'id="post_domain"') !== false);
$rows = db("SELECT * FROM siteops WHERE ctx_id = 'TESTUUID001'");
test('POST inserts row', count($rows) === 1);
if (count($rows) === 1) {
    $row = $rows[0];
    test('inserted git_name', $row['git_name'] === 'testpoker', $row['git_name']);
    test('inserted domain', $row['domain'] === 'testpoker.com', $row['domain']);
    test('inserted theme_name = git_name', $row['theme_name'] === 'testpoker', $row['theme_name']);
    test('inserted status done', $row['status'] === 'done', $row['status']);
    test('inserted time set', preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\+\d{2}(:\d{2})?)?$/', (string)$row['time']) === 1, $row['time']);
    $json = json_decode($row['json'], true);
    test('json round-trip', is_array($json));
    if (is_array($json)) {
        test('json keeps unknown keys', ($json['extra'] ?? '') === 'kept' && isset($json['content']['oldhtml']));
        test('json theme_name', ($json['theme_name'] ?? '') === 'testpoker');
        test('json site_type', ($json['site_type'] ?? '') === 'cta');
    }
}
$backupFile = $dataDir . '/sitebulkops/TESTUUID001.json';
test('backup file written', file_exists($backupFile));
$exported = exportLines();
test('export file has 1 line', count($exported) === 1);
if (count($exported) === 1) {
    $parts = explode('|', $exported[0]);
    test('export 12 columns', count($parts) === 12, (string)count($parts));
    test('export col ctx_id', $parts[0] === 'TESTUUID001', $parts[0]);
    test('export col git_account idx2', $parts[2] === 'felangel79', $parts[2]);
    test('export col status idx3', $parts[3] === 'done', $parts[3]);
    test('export col theme_type idx4', $parts[4] === 'poker', $parts[4]);
    test('export col languages idx5', $parts[5] === 'en', $parts[5]);
    test('export col domain idx6', $parts[6] === 'testpoker.com', $parts[6]);
    test('export col json idx12 valid', json_decode($parts[11], true) !== null);
}
test('json column equals backup file', file_exists($backupFile) && json_decode((string)file_get_contents($backupFile), true) == json_decode((string)($rows[0]['json'] ?? ''), true));
test('json column shown in confirm textarea', strpos($html, 'testpoker.com') !== false && strpos($html, 'TESTUUID001') !== false);

echo "== POST: pipe cleaning + html strip ==\n";
$html = runRequest([], validPost([
    'post_uuid' => 'TESTUUID002',
    'post_gitname' => 'pipe|name',
    'post_domain' => 'pipe.com',
    'post_sitetitle' => "<b>Bold|Title</b>&more",
    'post_lang' => null,
    'local_deploy' => null,
    'local_hostip' => null,
]), 'POST');
$row = db("SELECT * FROM siteops WHERE domain = 'pipe.com'");
test('insert with pipe-stripped git_name', count($row) === 1 && $row[0]['git_name'] === 'pipename', $row[0]['git_name'] ?? '');
if (count($row) === 1) {
    test('html stripped+escaped title', $row[0]['site_title'] === 'BoldTitle&amp;more', $row[0]['site_title']);
    $json = json_decode($row[0]['json'], true);
    test('json title stripped+escaped too', ($json['site_title'] ?? '') === 'BoldTitle&amp;more');
    test('missing lang and local_deploy become empty string', $row[0]['languages'] === '' && $row[0]['local_deploy'] === '' && $row[0]['local_hostip'] === '');
}
test('confirm page title decoded for display', strpos($html, 'value="BoldTitle&amp;more"') !== false);

echo "== POST: no trim for json title, trim for column ==\n";
$html = runRequest([], validPost([
    'post_uuid' => 'TESTUUID003',
    'post_gitname' => 'spacetitle',
    'post_domain' => 'space.com',
    'post_sitetitle' => '  Spaced Title  ',
]), 'POST');
$row = db("SELECT site_title, json FROM siteops WHERE domain = 'space.com'");
if (count($row) === 1) {
    $json = json_decode($row[0]['json'], true);
    test('column title trimmed', $row[0]['site_title'] === 'Spaced Title', $row[0]['site_title']);
    test('json title untrimmed (legacy quirk)', ($json['site_title'] ?? '') === '  Spaced Title  ', $json['site_title'] ?? '');
}

echo "== POST: upsert by domain ==\n";
$row0 = db("SELECT * FROM siteops WHERE domain = 'testpoker.com'");
$beforeCount = count(db("SELECT * FROM siteops"));
runRequest([], validPost([
    'post_gitname' => 'testpoker',
    'post_domain' => 'testpoker.com',
    'post_uuid' => 'NEWUUID002',
    'post_sitetitle' => 'Updated Title',
    'post_status' => 'draft',
]), 'POST');
$after = db("SELECT * FROM siteops");
test('same domain updates, no new row', count($after) === $beforeCount);
$row1 = db("SELECT * FROM siteops WHERE domain = 'testpoker.com'");
test('update keeps original ctx_id', $row1[0]['ctx_id'] === 'TESTUUID001', $row1[0]['ctx_id']);
test('update refreshes title/status', $row1[0]['site_title'] === 'Updated Title' && $row1[0]['status'] === 'draft');
test('backup written under new post_uuid', file_exists($dataDir . '/sitebulkops/NEWUUID002.json'));
$json = json_decode($row1[0]['json'], true);
test('updated json keeps extra keys', ($json['extra'] ?? '') === 'kept');

echo "== GET: eid backfill ==\n";
$html = runRequest(['eid' => 'TESTUUID001']);
test('GET eid backfills git_name', strpos($html, 'value="testpoker"') !== false);
test('GET eid backfills domain', strpos($html, 'value="testpoker.com"') !== false);
test('GET eid backfills title', strpos($html, 'value="Updated Title"') !== false);
test('GET eid keeps uuid', strpos($html, 'value="TESTUUID001"') !== false);
test('GET eid sitetype from json', strpos($html, strtolower('cta')) !== false);

echo "== GET: eid injection payload ==\n";
$html = runRequest(['eid' => "1' OR '1'='1"]);
test('injection payload no crash and no data leak', strpos($html, 'value="testpoker"') === false);
test('injection payload uuid is payload (escaped)', strpos($html, "value=\"1&#039; OR &#039;1&#039;=&#039;1\"") !== false);

echo "== POST: domain empty -> no db write, export still runs ==\n";
$html = runRequest([], validPost([
    'post_uuid' => 'TESTUUID004',
    'post_gitname' => 'nodomain',
    'post_domain' => '',
]), 'POST');
test('empty domain skips db write', count(db("SELECT * FROM siteops WHERE git_name = 'nodomain'")) === 0);
test('empty domain still renders confirm', strpos($html, 'nodomain') !== false);
$beforeCount = count(exportLines());
runRequest([], validPost([
    'post_uuid' => 'TESTUUID005',
    'post_gitname' => 'nodomain2',
    'post_domain' => '',
]), 'POST');
test('export still regenerated', count(exportLines()) === $beforeCount + 0);

echo "== POST: invalid setupNum ==\n";
$html = runRequest([], validPost(['setupNum' => 'nope']), 'POST');
test('invalid POST renders no form', strpos($html, 'id="wechatpost"') === false);
test('invalid POST renders no confirm json', strpos($html, 'Json:</label>') === false);
test('invalid POST still renders chrome', strpos($html, '<title>站点录入 · HUGO 站点管理</title>') !== false);

echo "== POST: text2database_merge.sh contract ==\n";
$html = runRequest([], [
    'post_uuid' => 'SHUUID003',
    'post_gitname' => 'shpoker',
    'post_gitaccount' => 'andarile',
    'post_domain' => 'shpoker.jp',
    'post_sitedeploy' => 'cloudflare',
    'post_sitehostip' => '47.88.54.48',
    'post_lang' => 'ja',
    'post_sitetype' => 'cta',
    'post_themename' => 'shpoker',
    'post_themetype' => 'poker',
    'post_status' => 'done',
    'post_sitetitle' => 'sh site title',
    'post_description' => 'sh site desc',
    'post_sitelogo' => 'https://img.example.com/sh.png',
    'post_keyword' => 'ポーカー',
    'post_sns_id' => 'shpoker',
    'post_topnavmenus' => 'poker,game',
    'local_deploy' => 'linux',
    'local_hostip' => '1.2.3.4',
    'post_json' => '{"site_type":"traffic","mainword":"shpoker"}',
    'setupNum' => 'ckeditorFormated',
], 'POST');
$row = db("SELECT * FROM siteops WHERE ctx_id = 'SHUUID003'");
test('merge script row inserted', count($row) === 1);
if (count($row) === 1) {
    test('merge script local_deploy stored', $row[0]['local_deploy'] === 'linux', $row[0]['local_deploy']);
    test('merge script local_hostip stored', $row[0]['local_hostip'] === '1.2.3.4', $row[0]['local_hostip']);
    $json = json_decode($row[0]['json'], true);
    test('merge script json keeps mainword', ($json['mainword'] ?? '') === 'shpoker');
    test('merge script json site_type cta override', ($json['site_type'] ?? '') === 'cta');
    test('merge script json lang ja', ($json['languages'] ?? '') === 'ja');
    test('merge script title no l10n issue', strpos($row[0]['site_title'], '|') === false);
}

$exported = exportLines();
test('export count matches db rows', count($exported) === count(db('SELECT * FROM siteops')));
$parts = explode('|', $exported[0]);
test('export every row has 12 columns', count($parts) === 12);

echo "== CSRF ==\n";
$html = runRequest([], [], 'GET', ['siteops_uid' => 'client1']);
preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m);
$expect = substr(hash_hmac('sha256', 'csrf|client1', 'testcsrfsecret'), 0, 32);
test('csrf token deterministic and present', isset($m[1]) && $m[1] === $expect, $m[1] ?? 'missing');
$html = runRequest([], validPost([
    'post_uuid' => 'CSRFUUID1',
    'post_gitname' => 'csrfok',
    'post_domain' => 'csrfok.com',
    'csrf_token' => $m[1] ?? '',
]), 'POST', ['siteops_uid' => 'client1']);
test('csrf valid token accepted', count(db("SELECT * FROM siteops WHERE domain = 'csrfok.com'")) === 1);
$html = runRequest([], validPost([
    'post_uuid' => 'CSRFUUID2',
    'post_gitname' => 'csrfbad',
    'post_domain' => 'csrfbad.com',
]), 'POST', ['siteops_uid' => 'client1']);
test('csrf missing token rejected', strpos($html, 'CSRF token invalid') !== false);
test('csrf rejection writes nothing', count(db("SELECT * FROM siteops WHERE domain = 'csrfbad.com'")) === 0);
$html = runRequest([], validPost([
    'post_uuid' => 'CSRFUUID3',
    'post_gitname' => 'csrfwrong',
    'post_domain' => 'csrfwrong.com',
    'csrf_token' => 'deadbeefdeadbeefdeadbeefdeadbeef',
]), 'POST', ['siteops_uid' => 'client1']);
test('csrf wrong token rejected', strpos($html, 'CSRF token invalid') !== false);
$html = runRequest([], validPost([
    'post_uuid' => 'CSRFUUID4',
    'post_gitname' => 'csrfapi',
    'post_domain' => 'csrfapi.com',
]), 'POST');
test('cookie-less POST still allowed (merge script contract)', count(db("SELECT * FROM siteops WHERE domain = 'csrfapi.com'")) === 1);

echo "== API csrf tokens ==\n";
putenv('APP_API_CSRF_TOKENS=apitoken-1,apitoken-2');
App\Config::reset();
$html = runRequest([], validPost([
    'post_uuid' => 'APICS RFUUID1',
    'post_gitname' => 'apitok',
    'post_domain' => 'apitok.com',
    'csrf_token' => 'apitoken-1',
]), 'POST', ['siteops_uid' => 'client1']);
test('api csrf token accepted via POST field', count(db("SELECT * FROM siteops WHERE domain = 'apitok.com'")) === 1, substr($html, 0, 120));
$html = runRequest([], validPost([
    'post_uuid' => 'APICS RFUUID2',
    'post_gitname' => 'apihdr',
    'post_domain' => 'apihdr.com',
]), 'POST', ['siteops_uid' => 'client1'], '/siteops.php', [], 'apitoken-2');
test('api csrf token accepted via X-CSRF-Token header', count(db("SELECT * FROM siteops WHERE domain = 'apihdr.com'")) === 1, substr($html, 0, 120));
$html = runRequest([], validPost([
    'post_uuid' => 'APICS RFUUID3',
    'post_gitname' => 'apibad',
    'post_domain' => 'apibad.com',
    'csrf_token' => 'apitoken-wrong',
]), 'POST', ['siteops_uid' => 'client1']);
test('api csrf wrong token still rejected', strpos($html, 'CSRF token invalid') !== false && count(db("SELECT * FROM siteops WHERE domain = 'apibad.com'")) === 0, substr($html, 0, 120));
$html = runRequest([], [], 'GET');
test('api token required on page entry without session', strpos($html, 'forbidden: missing or invalid API token') !== false, substr($html, 0, 120));
$html = runRequest([], [], 'GET', [], '/siteops.php', [], 'apitoken-1');
test('api token allows page entry via header', strpos($html, 'id="wechatpost"') !== false, substr($html, 0, 120));
$html = runRequest([], [], 'GET', ['siteops_uid' => 'client1'], '/siteops.php');
test('cookie-only session still allowed when auth disabled', strpos($html, 'id="wechatpost"') !== false, substr($html, 0, 120));
$html = runRequest([], [], 'GET', [], '/api/topic_task.php');
test('api token required on task script without session', strpos($html, 'forbidden: missing or invalid API token') !== false, substr($html, 0, 120));
echo "DEBUG after apitoken task\n";
db("CREATE TABLE IF NOT EXISTS \"sitetopic\" (
    \"id\" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    \"ctx_id\" VARCHAR UNIQUE NOT NULL,
    \"git_name\" VARCHAR, \"domain\" VARCHAR, \"keyword\" VARCHAR,
    \"pubdir\" VARCHAR, \"status\" VARCHAR, \"lang\" VARCHAR, \"geo\" VARCHAR,
    \"lasttask\" VARCHAR, \"json\" VARCHAR, \"time\" DATETIME)");
echo "DEBUG after create sitetopic\n";
db("INSERT INTO sitetopic (ctx_id, git_name, domain, keyword, pubdir, status, lang, geo, json, time) VALUES ('APITOK1', 'apitok', 'apitok.com', 'poker api token', 'article', 'enable', 'en', 'US', '{}', '2026-08-01 09:30:00')");
echo "DEBUG after insert APITOK1\n";
$html = runRequest(['t' => 'poker api token'], [], 'GET', [], '/api/topic_task.php', [], 'apitoken-2');
echo "DEBUG after apitoken2 request, html len: " . strlen($html) . " contains poker: " . (strpos($html, 'poker')!==false ? "YES" : "NO") . " html: " . substr($html,0,100) . "\n";
test('api token allows task script via header', strpos($html, 'poker') !== false, substr($html, 0, 120));
echo "DEBUG before delete APITOK1\n";
db("DELETE FROM sitetopic WHERE ctx_id = 'APITOK1'");
echo "DEBUG after delete APITOK1\n";
$html = runRequest([], [], 'GET', [], '/config_list.php');
test('api token required on config page without session', strpos($html, 'forbidden: missing or invalid API token') !== false, substr($html, 0, 120));
$html = runRequest([], [], 'GET', [], '/config_list.php', [], 'apitoken-1');
test('api token allows config page via header', strpos($html, 'name="login_action"') !== false || strpos($html, '配置') !== false, substr($html, 0, 120));
$html = runRequest([], [], 'GET', [], '/keywordquery.php');
test('api token required on keyword query without session', strpos($html, 'forbidden: missing or invalid API token') !== false, substr($html, 0, 120));
db("CREATE TABLE IF NOT EXISTS \"keywordmonitorlist\" (
    \"id\" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    \"ctx_id\" VARCHAR UNIQUE NOT NULL,
    \"git_name\" VARCHAR, \"keyword\" VARCHAR, \"pubdir\" VARCHAR,
    \"status\" VARCHAR, \"lang\" VARCHAR, \"geo\" VARCHAR,
    \"lasttask\" VARCHAR, \"json\" VARCHAR, \"time\" DATETIME)");
db("INSERT INTO keywordmonitorlist (ctx_id, keyword, status, lang, geo, json, time) VALUES ('APITOKK1', 'kw api token', 'enable', 'en', 'US', '{\"keyword\":\"kw api token\"}', '2026-08-01 09:30:00')");
$html = runRequest(['t' => 'kw api token'], [], 'GET', [], '/keywordquery.php', [], 'apitoken-1');
test('api token allows keyword query via header', strpos($html, 'APITOKK1') !== false, substr($html, 0, 120));
db("DELETE FROM keywordmonitorlist WHERE ctx_id = 'APITOKK1'");
putenv('APP_API_CSRF_TOKENS=');
App\Config::reset();
test('api csrf tokens absent by default', App\Config::apiCsrfTokens() === [], App\Config::csrfSecret());

echo "== Auth (optional) ==\n";
putenv('APP_AUTH_USER=admin');
putenv('APP_AUTH_PASSWORD=secretpw');
putenv('APP_AUTH_SECRET=authsecret123');
$html = runRequest();
test('auth enabled: cookie-less request allowed (API mode)', strpos($html, 'id="wechatpost"') !== false);
$html = runRequest([], [], 'GET', ['siteops_uid' => 'client1']);
test('auth enabled: browser without auth cookie sees login', strpos($html, 'name="login_action"') !== false);
putenv('APP_API_CSRF_TOKENS=authapikey1');
App\Config::reset();
$html = runRequest();
test('auth+token: session-less browser sees login page', strpos($html, 'name="login_action"') !== false, substr($html, 0, 120));
$html = runRequest([], [], 'GET', [], '/siteops.php', [], 'authapikey1');
test('auth+token: api token renders data page', strpos($html, 'id="wechatpost"') !== false, substr($html, 0, 120));
$html = runRequest([], [], 'GET', [], '/api/topic_task.php');
test('auth+token: task script still 403 for session-less request', strpos($html, 'forbidden: missing or invalid API token') !== false, substr($html, 0, 120));
$html = runRequest([], [], 'GET', ['siteops_uid' => 'client1']);
test('auth+token: uid cookie without auth cookie sees login', strpos($html, 'name="login_action"') !== false, substr($html, 0, 120));
putenv('APP_API_CSRF_TOKENS=');
App\Config::reset();
$html = runRequest([], validPost([
    'post_uuid' => 'AUTHUUID1',
    'post_gitname' => 'authok',
    'post_domain' => 'authok.com',
]), 'POST');
test('auth enabled: cookie-less API POST still allowed', count(db("SELECT * FROM siteops WHERE domain = 'authok.com'")) === 1);
$html = runRequest([], ['login_action' => 'login', 'auth_user' => 'admin', 'auth_password' => 'wrong'], 'POST', ['siteops_uid' => 'client1']);
test('login wrong password rejected', strpos($html, 'invalid credentials') !== false);
$html = runRequest([], ['login_action' => 'login', 'auth_user' => 'admin', 'auth_password' => 'secretpw'], 'POST', ['siteops_uid' => 'client1']);
test('login success yields empty body redirect', $html === '');
$expiry = time() + 3600;
$mac = substr(hash_hmac('sha256', 'auth|admin|' . $expiry, 'authsecret123'), 0, 32);
$authCookie = 'admin|' . $expiry . '|' . $mac;
$html = runRequest([], [], 'GET', ['siteops_uid' => 'client1', 'siteops_auth' => $authCookie]);
test('valid auth cookie grants form', strpos($html, 'id="wechatpost"') !== false);
$html = runRequest([], [], 'GET', ['siteops_uid' => 'client1', 'siteops_auth' => 'admin|' . ($expiry + 10) . '|' . $mac]);
test('tampered auth cookie rejected', strpos($html, 'id="wechatpost"') === false);
$c = App\Support\Security::buildAuthCookie('admin', time() + 3600);
test('Security::buildAuthCookie/verifyAuthCookie roundtrip', App\Support\Security::verifyAuthCookie($c) === true);
test('Security rejects garbage auth cookie', App\Support\Security::verifyAuthCookie('garbage') === false);
test('Security plain password verify', App\Support\Security::verifyPassword('secretpw') === true);
test('Security wrong password verify', App\Support\Security::verifyPassword('nope') === false);
putenv('APP_AUTH_USER');
putenv('APP_AUTH_PASSWORD');
putenv('APP_AUTH_SECRET');
$seedAdmin = App\Repositories\UserRepository::findByUsername('admin');
if ($seedAdmin !== null) {
    App\Repositories\UserRepository::delete((int)$seedAdmin['id']);
}
App\Support\Security::reset();

echo "== 登录态失效 → 统一跳转登录页 ==\n";
putenv('APP_AUTH_USER=admin');
putenv('APP_AUTH_PASSWORD=secretpw');
putenv('APP_AUTH_SECRET=authsecret123');
putenv('APP_API_CSRF_TOKENS=');
App\Config::reset();
App\Support\Security::reset();
$expiredAuth = time() - 60;
$expiredAuthMac = substr(hash_hmac('sha256', 'auth|admin|' . $expiredAuth, 'authsecret123'), 0, 32);
$expiredSession = ['siteops_uid' => 'expireuser', 'siteops_auth' => 'admin|' . $expiredAuth . '|' . $expiredAuthMac];
$expiredEntries = [
    '/publish_list.php', '/publish_edit.php', '/article_list.php', '/article_new.php',
    '/topiclist.php', '/topictable.php', '/topicops.php', '/siteops.php', '/keywordops.php',
    '/users.php', '/user_edit.php', '/roles.php', '/role_edit.php', '/config_list.php',
    '/config_edit.php', '/cache_manage.php', '/seo_report.php', '/wechat_cookie.php',
];
foreach ($expiredEntries as $expiredEntry) {
    $html = runRequest([], [], 'GET', $expiredSession, $expiredEntry);
    test(
        'expired session renders login page: ' . $expiredEntry,
        strpos($html, 'name="login_action"') !== false
            && strpos($html, 'forbidden') === false
            && strpos($html, 'Internal Server Error') === false,
        substr($html, 0, 150)
    );
}
$html = runRequest([], [], 'GET', $expiredSession, '/publish_list.php');
test('expired session login page shows notice', strpos($html, '登录状态已失效，请登录后再操作') !== false, substr($html, 0, 200));
$_SERVER['REQUEST_URI'] = '/publish_list.php?search=poker';
$html = runRequest([], [], 'GET', $expiredSession, '/publish_list.php');
test('login form posts back to original url', strpos($html, 'action="/publish_list.php?search=poker"') !== false, substr($html, 0, 200));
$html = runRequest([], ['login_action' => 'login', 'auth_user' => 'admin', 'auth_password' => 'wrong'], 'POST', $expiredSession, '/publish_list.php');
test('expired session login failure keeps notice', strpos($html, 'invalid credentials') !== false && strpos($html, '登录状态已失效，请登录后再操作') !== false, substr($html, 0, 200));
$html = runRequest([], ['login_action' => 'login', 'auth_user' => 'admin', 'auth_password' => 'secretpw'], 'POST', ['siteops_uid' => 'expireuser'], '/publish_list.php');
test('expired session login success redirects to original url', $html === '', var_export($html, true));
unset($_SERVER['REQUEST_URI']);
$authExpiry = time() + 3600;
$authMac = substr(hash_hmac('sha256', 'auth|admin|' . $authExpiry, 'authsecret123'), 0, 32);
$validSession = ['siteops_uid' => 'expireuser', 'siteops_auth' => 'admin|' . $authExpiry . '|' . $authMac];
$html = runRequest([], [], 'GET', $validSession, '/publish_list.php');
test('relogin session grants publish list again', strpos($html, '发布列表') !== false && strpos($html, 'name="login_action"') === false, substr($html, 0, 150));
$html = runRequest(['format' => 'json'], [], 'GET', $expiredSession, '/publish_list.php');
$payload = json_decode($html, true);
test('expired session json endpoint returns 401 payload', is_array($payload) && (int)($payload['code'] ?? 0) === 401 && strpos((string)($payload['message'] ?? ''), '登录') !== false, substr($html, 0, 150));
$html = runRequest(['format' => 'json'], [], 'GET', $expiredSession, '/topiclist.php');
$payload = json_decode($html, true);
test('expired session topic json endpoint returns 401 payload', is_array($payload) && (int)($payload['code'] ?? 0) === 401, substr($html, 0, 150));
resetRequest();
$_POST = ['action' => 'delete', 'ctx_id' => 'CTX-EXPIRED'];
$_COOKIE = $expiredSession;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['PHP_SELF'] = '/publish_list.php';
$_SERVER['HTTP_ORIGIN'] = 'http://localhost/publish_list.php';
$_SERVER['HTTP_ACCEPT'] = '*/*';
ob_start();
include dirname(__DIR__) . '/publish_list.php';
$html = ob_get_clean();
unset($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_ACCEPT']);
$payload = json_decode($html, true);
test('expired session xhr action returns 401 payload', is_array($payload) && (int)($payload['code'] ?? 0) === 401 && count(db("SELECT * FROM aigc_status WHERE ctx_id = 'CTX-EXPIRED'")) === 0, substr($html, 0, 150));
putenv('APP_API_CSRF_TOKENS=sessiontoken');
App\Config::reset();
App\Support\Security::reset();
$html = runRequest([], [], 'GET', $expiredSession, '/publish_list.php', [], 'sessiontoken');
test('api token + stale session cookie keeps 403 contract', strpos($html, 'forbidden') !== false && strpos($html, 'name="login_action"') === false, substr($html, 0, 150));
putenv('APP_API_CSRF_TOKENS=');
App\Config::reset();
App\Support\Security::reset();
$html = runRequest([], [], 'GET', $validSession, '/publish_list.php');
test('footer shows logout button after login', strpos($html, 'logout.php?csrf_token=') !== false && strpos($html, '登出') !== false, substr($html, (int)strpos($html, 'sops-topbar-actions'), 300));
test('footer shows current username', strpos($html, 'bi-person-circle') !== false && strpos($html, '>admin</span>') !== false, substr($html, (int)strpos($html, 'sops-topbar-actions'), 300));
$topbarAt = (int)strpos($html, '<div class="sops-topbar-actions">');
$footerSeg = '';
$fPos = strpos($html, '<footer');
if ($fPos !== false) {
    $footerSeg = substr($html, $fPos, strpos($html, '</footer>') - $fPos);
}
test('user and logout moved into topbar right side', $topbarAt > 0 && $topbarAt < strpos($html, '<footer') && substr_count($html, 'sops-topbar-actions') === 1, 'topbarAt=' . $topbarAt);
test('footer keeps only copyright no user or logout', strpos($footerSeg, 'logout.php') === false && strpos($footerSeg, 'bi-person-circle') === false && strpos($footerSeg, '&copy;') !== false, substr($footerSeg, 0, 200));
$html = runRequest([], [], 'GET', [], '/siteops.php');
test('footer hides logout when not logged in', strpos($html, 'logout.php') === false, substr($html, -400));
$logoutCsrf = substr(hash_hmac('sha256', 'csrf|expireuser', 'testcsrfsecret'), 0, 32);
$html = runRequest(['csrf_token' => $logoutCsrf], [], 'GET', $validSession, '/logout.php');
test('logout redirects with empty body', $html === '', var_export($html, true));
$html = runRequest(['csrf_token' => 'badtokenbadtokenbadtokenbadtoken'], [], 'GET', $validSession, '/logout.php');
test('logout bad csrf rejected', strpos($html, 'CSRF token invalid') !== false, substr($html, 0, 150));
$themeCss = (string)file_get_contents($root . '/css/theme.css');
test('sidebar foot and footer share height token', strpos($themeCss, '--sops-foot-h') !== false && substr_count($themeCss, 'var(--sops-foot-h)') >= 2, 'count=' . substr_count($themeCss, 'var(--sops-foot-h)'));
test('active pagination page matches outline-primary hover', strpos($themeCss, '.bootstrap-table .pagination .page-item.active .page-link { background: var(--sops-indigo); border-color: var(--sops-indigo); color: #fff; }') !== false && strpos($themeCss, '.btn-outline-primary:hover { background-color: var(--sops-indigo); border-color: var(--sops-indigo); color: #fff;') !== false, substr($themeCss, (int)strpos($themeCss, '.page-item.active'), 160));
putenv('APP_AUTH_USER');
putenv('APP_AUTH_PASSWORD');
putenv('APP_AUTH_SECRET');
$sessionAdmin = App\Repositories\UserRepository::findByUsername('admin');
if ($sessionAdmin !== null) {
    App\Repositories\UserRepository::delete((int)$sessionAdmin['id']);
}
App\Config::reset();
App\Support\Security::reset();

echo "== imgbb proxy ==\n";
$html = runRequest([], [], 'GET', [], '/imgbb_proxy.php');
test('imgbb GET rejected 405', strpos($html, 'method not allowed') !== false);
$html = runRequest([], [], 'POST', [], '/imgbb_proxy.php');
test('imgbb no file rejected', strpos($html, 'upload failed') !== false);
$fakeFile = ['name' => 'x.png', 'type' => 'image/png', 'tmp_name' => '/nonexistent', 'error' => UPLOAD_ERR_OK, 'size' => 100];
$html = runRequest([], [], 'POST', [], '/imgbb_proxy.php', ['image' => $fakeFile]);
test('imgbb invalid file rejected', strpos($html, 'not an image') !== false);
$html = runRequest([], [], 'POST', ['siteops_uid' => 'client1'], '/imgbb_proxy.php');
test('imgbb CSRF protected for browser', strpos($html, 'csrf token invalid') !== false);
$validToken = substr(hash_hmac('sha256', 'csrf|client1', 'testcsrfsecret'), 0, 32);
$html = runRequest([], [], 'POST', ['siteops_uid' => 'client1'], '/imgbb_proxy.php', ['image' => $fakeFile], $validToken);
test('imgbb CSRF ok continues to validation', strpos($html, 'not an image') !== false);

echo "== imgbb error paths (upload_max_filesize=2M sandbox) ==\n";
$iniFile = ['name' => 'big.png', 'type' => 'image/png', 'tmp_name' => '/x', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 99999999];
$html = runRequest([], [], 'POST', [], '/imgbb_proxy.php', ['image' => $iniFile]);
test('imgbb INI_SIZE gets explicit limit message', strpos($html, 'upload limit') !== false && strpos($html, ini_get('upload_max_filesize')) !== false);
$_SERVER['CONTENT_LENGTH'] = '5242880';
$html = runRequest([], [], 'POST', [], '/imgbb_proxy.php');
unset($_SERVER['CONTENT_LENGTH']);
test('imgbb oversized post gets post_max_size hint', strpos($html, 'post_max_size') !== false);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$tinyPng = $dataDir . '/tiny.png';
file_put_contents($tinyPng, $png);
$okFile = ['name' => 'tiny.png', 'type' => 'image/png', 'tmp_name' => $tinyPng, 'error' => UPLOAD_ERR_OK, 'size' => strlen($png)];
putenv('IMGBB_API_URL=http://127.0.0.1:65534/');
putenv('NO_PROXY=127.0.0.1');
putenv('no_proxy=127.0.0.1');
$html = runRequest([], [], 'POST', [], '/imgbb_proxy.php', ['image' => $okFile]);
putenv('IMGBB_API_URL');
putenv('NO_PROXY');
putenv('no_proxy');
$parsed = json_decode($html, true);
test('imgbb curl network failure returns JSON error', is_array($parsed) && $parsed['success'] === false && strpos($parsed['error']['message'] ?? '', 'imgbb request failed') === 0);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$fakeMissing = ['name' => 'x.png', 'type' => 'image/png', 'tmp_name' => '/nonexistent', 'error' => UPLOAD_ERR_OK, 'size' => 100];
resetRequest();
$_FILES = ['image' => $fakeMissing];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_COOKIE = ['siteops_uid' => 'client1'];
$_SERVER['HTTP_X_CSRF_TOKEN'] = $validToken;
ob_start();
include dirname(__DIR__) . '/imgbb_proxy.php';
$html = ob_get_clean();
restore_error_handler();
$parsed = json_decode($html, true);
test('imgbb uncaught exception returns JSON 500', is_array($parsed) && $parsed['success'] === false && strpos($parsed['error']['message'] ?? '', 'server error: ') === 0, substr($html, 0, 400));
$sub = <<<'PHP'
require 'APPROOT/app/bootstrap.php';
putenv('APP_CSRF_SECRET=testcsrfsecret');
putenv('IMGBB_API_URL=http://127.0.0.1:65534/');
putenv('NO_PROXY=127.0.0.1');
putenv('no_proxy=127.0.0.1');
$ok = App\Services\ImgBBService::upload(['name' => 'tiny.png', 'type' => 'image/png', 'tmp_name' => 'TINY', 'error' => UPLOAD_ERR_OK, 'size' => SIZ]);
echo json_encode($ok);
PHP;
$subCode = str_replace(
    ['APPROOT', 'TINY', 'SIZ'],
    [dirname(__DIR__), $tinyPng, strlen($png)],
    $sub
);
$out = shell_exec('php -d display_errors=0 -d disable_classes=finfo -r ' . escapeshellarg($subCode) . ' 2>&1');
$parsed = json_decode((string)$out, true);
test('imgbb works without fileinfo (getimagesize fallback)', is_array($parsed) && strpos($parsed['error']['message'] ?? '', 'imgbb request failed') === 0, (string)$out);
$garbage = $dataDir . '/garbage.bin';
file_put_contents($garbage, 'not an image at all');
$subCode = str_replace(
    ['APPROOT', 'TINY', 'SIZ'],
    [dirname(__DIR__), $garbage, 19],
    $sub
);
$out = shell_exec('php -d display_errors=0 -d disable_classes=finfo -r ' . escapeshellarg($subCode) . ' 2>&1');
$parsed = json_decode((string)$out, true);
test('imgbb without fileinfo rejects non-image', is_array($parsed) && ($parsed['error']['message'] ?? '') === 'not an image', (string)$out);
unlink($garbage);
unlink($tinyPng);

echo "== imgbb multi-key rotation ==\n";
putenv('APP_VAR_DIR=' . $dataDir . '/var');
$keys = App\Config::imgbbKeys();
test('imgbb keys from config array', count($keys) === 6 && $keys[0] === '9fc1b0414d6169d761763120e0b33038', implode(',', $keys));
test('imgbb rotation initial order matches config', App\Config::imgbbRotationKeys() === $keys);
putenv('IMGBB_API_KEY=k1,k2,k3');
@unlink($dataDir . '/var/imgbb_key_index');
$seq = [];
for ($i = 0; $i < 4; $i++) {
    $seq[] = implode(',', App\Config::imgbbRotationKeys());
}
test('imgbb keys rotate round-robin', $seq[0] === 'k1,k2,k3' && $seq[1] === 'k2,k3,k1' && $seq[2] === 'k3,k1,k2' && $seq[3] === 'k1,k2,k3', implode(' ; ', $seq));
test('imgbb rotation persists on disk', file_exists($dataDir . '/var/imgbb_key_index'));
putenv('IMGBB_API_KEY');
putenv('APP_VAR_DIR');

echo "== keywordops: form ==\n";
$html = runRequest([], [], 'GET', [], '/keywordops.php');
test('keyword GET renders card form', strpos($html, 'id="formtable"') !== false && strpos($html, 'class="card card-sops"') !== false);
test('keyword GET has csrf hidden', strpos($html, 'name="csrf_token"') !== false);
test('keyword GET lists site gitnames', strpos($html, '>testpoker</option>') !== false && strpos($html, '>shpoker</option>') !== false);
test('keyword GET unified header/footer', strpos($html, 'sops-sidebar') !== false && strpos($html, 'footer-sops') !== false);

echo "== keywordops: POST single ==\n";
$html = runRequest([], [
    'post_keyword' => 'texas holdem',
    'post_gitname' => 'testpoker',
    'post_lang' => 'en',
    'post_geo' => 'US',
    'post_pubdir' => 'article',
    'post_status' => 'enable',
    'setupNum' => 'ckeditorFormated',
], 'POST', [], '/keywordops.php');
$krows = db("SELECT * FROM keywordmonitorlist WHERE git_name = 'testpoker'");
test('keyword POST inserts row', count($krows) === 1);
if (count($krows) === 1) {
    test('keyword row fields', $krows[0]['keyword'] === 'texas holdem' && $krows[0]['lang'] === 'en' && $krows[0]['geo'] === 'US' && $krows[0]['status'] === 'enable' && $krows[0]['pubdir'] === 'article');
    test('keyword row json valid', json_decode($krows[0]['json'], true) !== null);
    test('keyword row ctx_id set', strlen($krows[0]['ctx_id']) > 5);
}
test('keyword confirm rendered', strpos($html, '关键词提交确认') !== false);
test('keyword backup file written', file_exists($dataDir . '/keywordmonitor/' . $krows[0]['ctx_id'] . '.json'));
$kExport = (string)file_get_contents($dataDir . '/keyword_monitor_list.txt');
test('keyword export file written', strpos($kExport, 'texas holdem') !== false);
$kParts = explode('|', explode("\n", trim($kExport))[0]);
test('keyword export 7 columns', count($kParts) === 7, (string)count($kParts));

echo "== keywordops: POST bulk ==\n";
$html = runRequest([], [
    'post_keyword' => 'poker tips,  poker rules,',
    'post_bulkkeyword' => 'enable',
    'post_gitname' => 'shpoker',
    'post_lang' => 'ja',
    'post_geo' => 'JP',
    'post_pubdir' => 'news',
    'post_status' => 'draft',
    'setupNum' => 'ckeditorFormated',
], 'POST', [], '/keywordops.php');
$krows = db("SELECT * FROM keywordmonitorlist WHERE git_name = 'shpoker'");
test('keyword bulk inserts 2 rows', count($krows) === 2, (string)count($krows));
test('keyword bulk trims keywords', count(array_filter(array_column($krows, 'keyword'), function ($k) { return $k !== ''; })) === 2);

echo "== keywordops: upsert by keyword ==\n";
runRequest([], [
    'post_keyword' => 'texas holdem',
    'post_gitname' => 'testpoker',
    'post_lang' => 'en',
    'post_geo' => 'US',
    'post_pubdir' => 'home',
    'post_status' => 'disable',
    'setupNum' => 'ckeditorFormated',
], 'POST', [], '/keywordops.php');
$krows = db("SELECT * FROM keywordmonitorlist WHERE keyword = 'texas holdem'");
test('keyword upsert no duplicate', count($krows) === 1);
if (count($krows) === 1) {
    test('keyword upsert updates fields', $krows[0]['pubdir'] === 'home' && $krows[0]['status'] === 'disable');
    test('keyword upsert keeps ctx_id', strlen($krows[0]['ctx_id']) > 5);
    $kCtxId = $krows[0]['ctx_id'];
} else {
    $kCtxId = '';
}

echo "== keywordops: edit via eid ==\n";
$html = runRequest(['eid' => $kCtxId], [], 'GET', [], '/keywordops.php');
test('keyword GET eid prefills form', $kCtxId !== '' && strpos($html, 'name="post_keyword"') !== false && strpos($html, 'value="texas holdem"') !== false);
test('keyword GET eid shows edit title', strpos($html, '关键词编辑') !== false);
test('keyword GET eid keeps ctx id hidden', strpos($html, 'name="post_ctxid" value="' . $kCtxId . '"') !== false);
runRequest([], [
    'post_ctxid' => $kCtxId,
    'post_keyword' => 'texas holdem guide',
    'post_gitname' => 'testpoker',
    'post_lang' => 'en',
    'post_geo' => 'US',
    'post_pubdir' => 'home',
    'post_status' => 'enable',
    'setupNum' => 'ckeditorFormated',
], 'POST', [], '/keywordops.php');
$krows = db("SELECT * FROM keywordmonitorlist WHERE keyword = 'texas holdem guide'");
test('keyword edit renames row', count($krows) === 1, (string)count($krows));
if (count($krows) === 1) {
    test('keyword edit keeps ctx_id', $krows[0]['ctx_id'] === $kCtxId, ($krows[0]['ctx_id'] ?? '') . ' vs ' . $kCtxId);
    test('keyword edit keeps no duplicate old keyword', count(db("SELECT * FROM keywordmonitorlist WHERE keyword = 'texas holdem'")) === 0);
}
test('keyword edit unknown eid falls back to new form', strpos(runRequest([], [], 'GET', ['eid' => 'nope-missing'], '/keywordops.php'), '关键词配置') !== false);

echo "== keywordops: CSRF ==\n";
$html = runRequest([], [
    'post_keyword' => 'csrfkw',
    'post_gitname' => 'testpoker',
    'post_lang' => 'en',
    'post_geo' => 'US',
    'post_pubdir' => 'article',
    'post_status' => 'enable',
    'csrf_token' => 'deadbeefdeadbeefdeadbeefdeadbeef',
    'setupNum' => 'ckeditorFormated',
], 'POST', ['siteops_uid' => 'client1'], '/keywordops.php');
test('keyword CSRF bad token rejected', strpos($html, 'CSRF token invalid') !== false);
test('keyword CSRF rejection writes nothing', count(db("SELECT * FROM keywordmonitorlist WHERE keyword = 'csrfkw'")) === 0);
$html = runRequest([], [
    'post_keyword' => 'csrfok',
    'post_gitname' => 'testpoker',
    'post_lang' => 'en',
    'post_geo' => 'US',
    'post_pubdir' => 'article',
    'post_status' => 'enable',
    'setupNum' => 'ckeditorFormated',
], 'POST', [], '/keywordops.php');
test('keyword cookie-less POST allowed (API contract)', count(db("SELECT * FROM keywordmonitorlist WHERE keyword = 'csrfok'")) === 1);

echo "== topicops: form ==\n";
$html = runRequest([], [], 'GET', [], '/topicops.php');
test('topic GET renders card form', strpos($html, 'id="formtable"') !== false && strpos($html, 'class="card card-sops"') !== false);
test('topic GET csrf hidden', strpos($html, 'name="csrf_token"') !== false);
test('topic GET lists site gitnames/domains', strpos($html, '>testpoker</option>') !== false && strpos($html, '>testpoker.com</option>') !== false);
test('topic GET unified header/footer', strpos($html, 'sops-sidebar') !== false && strpos($html, 'footer-sops') !== false);
test('topic GET default bulk checked', strpos($html, 'id="post_bulkkeyword" name="post_bulkkeyword" checked') !== false);
test('topic GET emits site menu script', strpos($html, 'js/sops-site-menus.js') !== false && strpos($html, 'sopsAttachSiteMenus({') !== false && strpos($html, "triggerId: 'post_domain'") !== false && strpos($html, "targetId: 'post_pubdir'") !== false, substr($html, -800));
test('topic GET pubdir/domain carry data-id', strpos($html, 'data-id="post_pubdir"') !== false && strpos($html, 'data-id="post_domain"') !== false, '');
$json = runRequest([], ['action' => 'site_list', 'csrf_token' => 'x'], 'POST', [], '/topicops.php');
$siteRows = json_decode($json, true);
test('topicops site_list returns topnav_menus', is_array($siteRows) && isset($siteRows['rows'][0]) && array_key_exists('topnav_menus', $siteRows['rows'][0]), substr((string)$json, 0, 200));
$nodeCheck = trim((string)shell_exec('command -v node >/dev/null 2>&1 && node --check ' . escapeshellarg(dirname(__DIR__) . '/js/sops-site-menus.js') . ' 2>&1'));
test('sops-site-menus.js syntax valid', $nodeCheck === '' || strpos($nodeCheck, 'Error') === false, $nodeCheck);

echo "== topicops: POST single ==\n";
$html = runRequest([], [
    'post_uuid' => 'TOPICCTX001',
    'post_keyword' => 'poker strategy',
    'post_gitname' => 'testpoker',
    'post_domain' => 'testpoker.com',
    'post_lang' => 'en',
    'post_geo' => 'US',
    'post_pubdir' => 'article',
    'post_status' => 'enable',
    'setupNum' => 'ckeditorFormated',
], 'POST', [], '/topicops.php');
$trows = db("SELECT * FROM sitetopic WHERE git_name = 'testpoker'");
test('topic POST inserts row', count($trows) === 1, (string)count($trows));
if (count($trows) === 1) {
    test('topic row fields', $trows[0]['keyword'] === 'poker strategy' && $trows[0]['domain'] === 'testpoker.com' && $trows[0]['lang'] === 'en' && $trows[0]['geo'] === 'US' && $trows[0]['status'] === 'enable' && $trows[0]['pubdir'] === 'article');
    test('topic row json valid', json_decode($trows[0]['json'], true) !== null);
}
test('topic confirm rendered', strpos($html, '话题提交确认') !== false);
test('topic backup file written', file_exists($dataDir . '/topicmonitor/TOPICCTX001.json'));
$tExport = (string)file_get_contents($dataDir . '/topic_monitor_list.txt');
test('topic export file written', strpos($tExport, 'poker strategy') !== false);
$tParts = explode('|', explode("\n", trim($tExport))[0]);
test('topic export 8 columns', count($tParts) === 8, (string)count($tParts));

echo "== topicops: POST bulk ==\n";
$html = runRequest([], [
    'post_uuid' => 'BULKCTX001',
    'post_keyword' => 'texas holdem,  omaha hi,',
    'post_bulkkeyword' => 'enable',
    'post_gitname' => 'shpoker',
    'post_domain' => 'shpoker.com',
    'post_lang' => 'ja',
    'post_geo' => 'JP',
    'post_pubdir' => 'news',
    'post_status' => 'draft',
    'setupNum' => 'ckeditorFormated',
], 'POST', [], '/topicops.php');
$trows = db("SELECT * FROM sitetopic WHERE git_name = 'shpoker'");
test('topic bulk inserts 2 rows', count($trows) === 2, (string)count($trows));
test('topic bulk trims keywords', count(array_filter(array_column($trows, 'keyword'), function ($k) { return $k !== ''; })) === 2);

echo "== topicops: upsert by keyword+git_name ==\n";
runRequest([], [
    'post_uuid' => 'BULKCTX001',
    'post_keyword' => 'texas holdem',
    'post_bulkkeyword' => 'enable',
    'post_gitname' => 'shpoker',
    'post_domain' => 'shpoker.com',
    'post_lang' => 'ja',
    'post_geo' => 'JP',
    'post_pubdir' => 'faqs',
    'post_status' => 'disable',
    'setupNum' => 'ckeditorFormated',
], 'POST', [], '/topicops.php');
$trows = db("SELECT * FROM sitetopic WHERE git_name = 'shpoker'");
test('topic upsert no duplicate', count($trows) === 2, (string)count($trows));
$tHoldem = array_values(array_filter($trows, function ($r) { return $r['keyword'] === 'texas holdem'; }));
test('topic upsert updates fields', isset($tHoldem[0]) && $tHoldem[0]['pubdir'] === 'faqs' && $tHoldem[0]['status'] === 'disable');

echo "== topicops: edit via eid ==\n";
$tRows = db("SELECT * FROM sitetopic WHERE keyword = 'poker strategy'");
$tCtxId = count($tRows) === 1 ? $tRows[0]['ctx_id'] : '';
$html = runRequest(['eid' => $tCtxId], [], 'GET', [], '/topicops.php');
test('topic GET eid prefills form', $tCtxId !== '' && strpos($html, 'name="post_keyword"') !== false && strpos($html, 'value="poker strategy"') !== false);
test('topic GET eid shows edit title', strpos($html, '话题编辑') !== false);
runRequest([], [
    'post_uuid' => $tCtxId,
    'post_keyword' => 'poker strategy guide',
    'post_gitname' => 'testpoker',
    'post_domain' => 'testpoker.com',
    'post_lang' => 'en',
    'post_geo' => 'US',
    'post_pubdir' => 'article',
    'post_status' => 'enable',
    'setupNum' => 'ckeditorFormated',
], 'POST', [], '/topicops.php');
$trows = db("SELECT * FROM sitetopic WHERE keyword = 'poker strategy guide'");
test('topic edit renames row', count($trows) === 1, (string)count($trows));
if (count($trows) === 1) {
    test('topic edit keeps ctx_id', $trows[0]['ctx_id'] === $tCtxId, ($trows[0]['ctx_id'] ?? '') . ' vs ' . $tCtxId);
}
test('topic edit unknown eid falls back to new form', strpos(runRequest([], [], 'GET', ['eid' => 'nope-missing'], '/topicops.php'), '话题录入') !== false);

db("UPDATE sitetopic SET pubdir = 'custom-dir-not-in-config' WHERE ctx_id = '" . $tCtxId . "'");
$html = runRequest(['eid' => $tCtxId], [], 'GET', [], '/topicops.php');
test('topic GET keeps pubdir config value as option', strpos($html, '<option data-subtext="配置值" value="custom-dir-not-in-config" selected>') !== false, 'pubdir fallback missing');
test('topic GET pubdir still lists config options', strpos($html, '<option value="article">article</option>') !== false, substr($html, 0, 200));

echo "== topicops: CSRF ==\n";
$html = runRequest([], [
    'post_uuid' => 'TOPICCSRF1',
    'post_keyword' => 'csrfkw',
    'post_gitname' => 'testpoker',
    'post_domain' => 'testpoker.com',
    'post_lang' => 'en',
    'post_geo' => 'US',
    'post_pubdir' => 'article',
    'post_status' => 'enable',
    'csrf_token' => 'deadbeefdeadbeefdeadbeefdeadbeef',
    'setupNum' => 'ckeditorFormated',
], 'POST', ['siteops_uid' => 'client1'], '/topicops.php');
test('topic CSRF bad token rejected', strpos($html, 'CSRF token invalid') !== false);
test('topic CSRF rejection writes nothing', count(db("SELECT * FROM sitetopic WHERE keyword = 'csrfkw'")) === 0);

echo "== articleops: form ==\n";
$html = runRequest([], [], 'GET', [], '/article_new.php');
test('article GET renders card form', strpos($html, 'id="wechatpost"') !== false && strpos($html, 'class="card card-sops"') !== false);
test('article GET csrf hidden', strpos($html, 'name="csrf_token"') !== false);
test('article GET setupNum marker hidden', strpos($html, 'id="setupNum"') !== false && strpos($html, 'value="ckeditorFormated"') !== false);
test('article GET uuid generated', preg_match('/id="post_uuid"[^>]*value="([^"]+)"/', $html, $am) === 1 && strlen($am[1]) > 10, $am[1] ?? 'no uuid');
test('article GET ckeditor assets', strpos($html, 'js/ckeditor5/ckeditor5.js') !== false && strpos($html, 'ckeditor5.css') !== false && strpos($html, 'js/slug.js') !== false);
test('article GET word count and tools', strpos($html, 'id="word-count"') !== false && strpos($html, 'id="list2number"') !== false && strpos($html, 'id="removeBlankLines"') !== false);
test('article GET pubdomain global + site options', strpos($html, '>global</option>') !== false && strpos($html, '>testpoker.com</option>') !== false);
test('article GET language/translateto options removed', strpos($html, '>en</option>') !== false && strpos($html, 'name="post_translate_to_langs[]"') === false && strpos($html, 'id="autotranslate"') === false, substr($html, 0, 200));
test('article GET unified header/footer', strpos($html, 'sops-sidebar') !== false && strpos($html, 'footer-sops') !== false);
test('article GET image upload flow', strpos($html, 'id="imagesource"') !== false && strpos($html, 'imgbb_proxy.php') !== false && strpos($html, 'X-CSRF-Token') !== false);
test('article GET preview button', strpos($html, 'id="previewImageTrigger"') !== false && strpos($html, 'btn-outline-primary') !== false && strpos($html, 'bi bi-eye') !== false && strpos($html, 'imagePreviewModal') !== false);
test('article POST site_list queries siteops db', (function () {
    \App\Repositories\SiteRepository::upsertByDomain([
        'git_name' => 'testlist.com',
        'domain' => 'testlist.com',
        'site_title' => 'Test List Site',
        'languages' => 'zh,en',
        'status' => 'draft',
        'theme_type' => 'poker',
    ]);
    $resp = runRequest([], ['action' => 'site_list', 'csrf_token' => \App\Support\Security::csrfToken()], 'POST', [], '/article_new.php');
    $data = json_decode($resp, true);
    $found = false;
    if (is_array($data) && isset($data['total']) && is_array($data['rows'])) {
        foreach ($data['rows'] as $row) {
            if (isset($row['domain']) && $row['domain'] === 'testlist.com' && strpos((string)$row['languages'], 'zh') !== false) {
                $found = true;
                break;
            }
        }
    }
    return is_array($data) && isset($data['total']) && $found;
})());

echo "== wechatops: form ==\n";
$html = runRequest([], [], 'GET', [], '/wechat_import.php');
test('wechat GET renders import form', strpos($html, 'id="wechatpost"') !== false && strpos($html, 'id="wx_url_input"') !== false && strpos($html, 'id="wx_extract_btn"') !== false);
test('wechat GET cover sync + batch badges', strpos($html, 'id="imgbbSyncThumb"') !== false && strpos($html, 'id="uploadImgbbButton"') !== false && strpos($html, 'imgbb_sync') !== false && strpos($html, 'imgbb_batch_sync') !== false);
test('wechat GET prefill fields', strpos($html, 'id="post_static_thumbnail"') !== false && strpos($html, 'id="post_title"') !== false && strpos($html, 'name="post_ckeditor_contents"') !== false);
$htmlBad = runRequest(['wx_url' => 'https://example.com/not-weixin'], [], 'GET', [], '/wechat_import.php');
test('wechat GET invalid wx_url notice', strpos($htmlBad, '仅支持 mp.weixin.qq.com') !== false);
$htmlMenu = runRequest([], [], 'GET', [], '/siteops.php');
test('wechat menu child added', strpos($htmlMenu, 'wechat_import.php') !== false && strpos($htmlMenu, 'bi-wechat') !== false);
test('wechat slugify ascii', App\Services\WeChatService::slugify('Hello World Test') === 'hello-world-test', App\Services\WeChatService::slugify('Hello World Test'));
test('wechat extract rejects empty url', (function () {
    $r = App\Services\WeChatService::extract('');
    return isset($r['error']) && $r['error'] !== '';
})());
test('wechat isWeixinArticleUrl', App\Services\WeChatService::isWeixinArticleUrl('https://mp.weixin.qq.com/s/abc') === true && App\Services\WeChatService::isWeixinArticleUrl('https://mp.weixin.qq.com/s') === true && App\Services\WeChatService::isWeixinArticleUrl('https://example.com/x') === false);
$r = runRequest([], ['action' => 'imgbb_sync', 'url' => 'https://example.com/x.jpg', 'csrf_token' => 'deadbeefdeadbeefdeadbeefdeadbeef'], 'POST', ['siteops_uid' => 'wxclient1'], '/wechat_import.php');
$d = json_decode($r, true);
test('wechat imgbb_sync bad csrf rejected', is_array($d) && isset($d['ok']) && $d['ok'] === false && isset($d['error']) && strpos($d['error'], 'CSRF') !== false, $r);
$r = runRequest([], ['action' => 'imgbb_batch_sync', 'urls' => json_encode(['https://example.com/a.jpg']), 'csrf_token' => 'deadbeefdeadbeefdeadbeefdeadbeef'], 'POST', ['siteops_uid' => 'wxclient1'], '/wechat_import.php');
$d = json_decode($r, true);
test('wechat imgbb_batch_sync bad csrf rejected', is_array($d) && isset($d['ok']) && $d['ok'] === false && isset($d['error']) && strpos($d['error'], 'CSRF') !== false, $r);
$r = runRequest([], ['action' => 'site_list', 'csrf_token' => \App\Support\Security::csrfToken()], 'POST', [], '/wechat_import.php');
$d = json_decode($r, true);
test('wechat POST site_list queries siteops db', (function () use ($d) {
    if (!is_array($d) || !isset($d['total']) || !is_array($d['rows'])) {
        return false;
    }
    foreach ($d['rows'] as $row) {
        if (isset($row['domain']) && $row['domain'] === 'testlist.com') {
            return true;
        }
    }
    return false;
})());

echo "== wechatops: cookie config file ==\n";
$cookieTpl = $dataDir . '/wechat.cookie.php';
file_put_contents($cookieTpl, "<?php return ['cookie' => 'wxlogin=abc123', 'http_headers' => []];");
putenv('APP_WECHAT_COOKIE_FILE=' . $cookieTpl);
\App\Config::reset();
$wc = \App\Config::wechatConfig();
test('wechat config defaults merged', count($wc['http_headers']) >= 10 && $wc['timeout'] === 30 && $wc['max_retries'] === 2);
test('wechat cookie file cookie wins', $wc['cookie'] === 'wxlogin=abc123', $wc['cookie']);
test('wechat empty headers keep defaults', count($wc['http_headers']) >= 10, '');
file_put_contents($cookieTpl, "<?php return ['cookie' => 'wxlogin=xyz789', 'http_headers' => ['User-Agent: CustomAgent']];");
\App\Config::reset();
$wc = \App\Config::wechatConfig();
test('wechat cookie file headers replace', $wc['http_headers'] === ['User-Agent: CustomAgent'] && $wc['cookie'] === 'wxlogin=xyz789');
unlink($cookieTpl);
putenv('APP_WECHAT_COOKIE_FILE=' . $cookieTpl);
\App\Config::reset();
$wc = \App\Config::wechatConfig();
test('wechat missing cookie file falls back', $wc['cookie'] === '' && count($wc['http_headers']) >= 10);
putenv('APP_WECHAT_COOKIE_FILE');
\App\Config::reset();
test('wechat_cookie_php exists in app root', is_file(\App\Config::wechatConfigFile()));

echo "== wechatops: cookie config manage page ==\n";
$wcManageFile = $dataDir . '/wechat_cookie_manage.php';
@unlink($wcManageFile);
putenv('APP_WECHAT_COOKIE_FILE=' . $wcManageFile);
\App\Config::reset();
$html = runRequest([], [], 'GET', [], '/wechat_cookie.php');
test('cookie manage GET renders form', strpos($html, '微信cookie配置') !== false && strpos($html, 'name="cookie"') !== false && strpos($html, 'name="http_headers"') !== false && strpos($html, 'name="max_retries"') !== false, substr($html, 0, 200));
test('cookie manage GET no empty alert', preg_match('/<div class="alert alert-success py-2 small">\s*<\/div>/', $html) === 0, substr($html, 0, 200));
test('cookie manage GET shows effective defaults', strpos($html, 'name="timeout"') !== false && strpos($html, 'value="30"') !== false, substr($html, 0, 200));
test('cookie manage menu child added', strpos($html, 'wechat_cookie.php') !== false && strpos($html, 'bi-cookie') !== false);
$html = runRequest([], ['csrf_token' => 'x', 'cookie' => 'wxlogin=newcookie', 'http_headers' => '["User-Agent: CookiePageAgent"]', 'wechat_forwarded_for' => '1.2.3.4', 'proxy' => 'http://127.0.0.1:8080', 'connect_timeout' => '5', 'timeout' => '12', 'max_retries' => '3'], 'POST', [], '/wechat_cookie.php');
test('cookie manage POST saves with message', strpos($html, '已保存') !== false, substr($html, 0, 200));
\App\Config::reset();
$wcSaved = \App\Config::wechatConfig();
test('cookie manage POST persists config', $wcSaved['cookie'] === 'wxlogin=newcookie' && $wcSaved['http_headers'] === ['User-Agent: CookiePageAgent'] && $wcSaved['wechat_forwarded_for'] === '1.2.3.4' && $wcSaved['proxy'] === 'http://127.0.0.1:8080' && $wcSaved['connect_timeout'] === 5 && $wcSaved['timeout'] === 12 && $wcSaved['max_retries'] === 3, json_encode($wcSaved, JSON_UNESCAPED_UNICODE));
test('cookie manage writes php file', is_file($wcManageFile) && strpos((string)file_get_contents($wcManageFile), "wxlogin=newcookie") !== false);
$html = runRequest([], ['csrf_token' => 'x', 'cookie' => 'k', 'http_headers' => 'not-json', 'connect_timeout' => '5', 'timeout' => '12', 'max_retries' => '1'], 'POST', [], '/wechat_cookie.php');
test('cookie manage rejects bad headers json', strpos($html, '保存失败') !== false && strpos($html, 'http_headers') !== false, substr($html, 0, 200));
\App\Config::reset();
test('cookie manage bad headers not saved', \App\Config::wechatConfig()['cookie'] === 'wxlogin=newcookie');
$html = runRequest([], ['csrf_token' => 'x', 'cookie' => 'k', 'http_headers' => '[]', 'connect_timeout' => 'abc', 'timeout' => '12', 'max_retries' => '1'], 'POST', [], '/wechat_cookie.php');
test('cookie manage rejects bad timeout', strpos($html, '保存失败') !== false, substr($html, 0, 200));
$html = runRequest([], ['csrf_token' => 'bad', 'cookie' => 'k', 'http_headers' => '[]', 'connect_timeout' => '5', 'timeout' => '12', 'max_retries' => '1'], 'POST', ['siteops_uid' => 'csrfuser'], '/wechat_cookie.php');
test('cookie manage bad csrf rejected', strpos($html, 'CSRF') !== false, substr($html, 0, 200));
@unlink($wcManageFile);
putenv('APP_WECHAT_COOKIE_FILE');
\App\Config::reset();

echo "== cache manage page ==\n";
$cacheCfgFile = APP_PATH . '/cache.config.php';
@unlink($cacheCfgFile);
\App\Support\Cache::reset();
$html = runRequest([], [], 'GET', [], '/cache_manage.php');
test('cache manage GET renders page', strpos($html, '缓存管理') !== false && strpos($html, 'name="redis_host"') !== false && strpos($html, 'name="redis_port"') !== false, substr($html, 0, 200));
test('cache manage GET no empty alert', preg_match('/<div class="alert alert-success py-2 small">\s*<\/div>/', $html) === 0, substr($html, 0, 200));
test('cache manage GET shows file backend', strpos($html, '文件') !== false && strpos($html, '缓存后端') !== false, substr($html, 0, 200));
test('cache manage menu child added', strpos($html, 'cache_manage.php') !== false && strpos($html, 'bi-lightning-charge') !== false);

$html = runRequest([], ['action' => 'save_config', 'csrf_token' => 'x', 'redis_host' => '127.0.0.1', 'redis_port' => '6390', 'redis_auth' => 'secret123', 'redis_db' => '2', 'redis_timeout' => '1.0'], 'POST', [], '/cache_manage.php');
test('cache manage POST saves config', strpos($html, '已保存') !== false, substr($html, 0, 200));
test('cache manage writes config file', is_file($cacheCfgFile) && strpos((string)file_get_contents($cacheCfgFile), "'host' => '127.0.0.1'") !== false && strpos((string)file_get_contents($cacheCfgFile), "'port' => 6390") !== false && strpos((string)file_get_contents($cacheCfgFile), "'auth' => 'secret123'") !== false && strpos((string)file_get_contents($cacheCfgFile), "'db' => 2") !== false);
\App\Support\Cache::reset();
$cfgLoaded = is_file($cacheCfgFile) ? (include $cacheCfgFile) : [];
test('cache manage config file returns array', is_array($cfgLoaded) && $cfgLoaded['host'] === '127.0.0.1' && $cfgLoaded['port'] === 6390 && $cfgLoaded['auth'] === 'secret123' && $cfgLoaded['db'] === 2 && (float)$cfgLoaded['timeout'] === 1.0, json_encode($cfgLoaded, JSON_UNESCAPED_UNICODE));

$html = runRequest([], ['action' => 'save_config', 'csrf_token' => 'x', 'redis_host' => '127.0.0.1', 'redis_port' => 'abc', 'redis_auth' => '', 'redis_db' => '0', 'redis_timeout' => '0.5'], 'POST', [], '/cache_manage.php');
test('cache manage rejects bad port', strpos($html, '端口') !== false, substr($html, 0, 200));

$html = runRequest([], ['action' => 'save_config', 'csrf_token' => 'x', 'redis_host' => '127.0.0.1', 'redis_port' => '6379', 'redis_auth' => '', 'redis_db' => '99', 'redis_timeout' => '0.5'], 'POST', [], '/cache_manage.php');
test('cache manage rejects bad db', strpos($html, 'DB') !== false, substr($html, 0, 200));

$html = runRequest([], ['action' => 'save_config', 'csrf_token' => 'bad', 'redis_host' => '', 'redis_port' => '6379', 'redis_auth' => '', 'redis_db' => '0', 'redis_timeout' => '0.5'], 'POST', ['siteops_uid' => 'csrfuser'], '/cache_manage.php');
test('cache manage bad csrf rejected', strpos($html, 'CSRF') !== false, substr($html, 0, 200));

$html = runRequest([], ['action' => 'save_config', 'csrf_token' => 'x', 'redis_host' => '', 'redis_port' => '6379', 'redis_auth' => '', 'redis_db' => '0', 'redis_timeout' => '0.5'], 'POST', [], '/cache_manage.php');
test('cache manage empty host clears config', strpos($html, '回退到文件缓存') !== false, substr($html, 0, 200));

\App\Support\Cache::set('test:flush', 'value1', 60);
test('cache flushAll pre-check', \App\Support\Cache::get('test:flush') === 'value1');
$html = runRequest([], ['action' => 'flush', 'csrf_token' => 'x'], 'POST', [], '/cache_manage.php');
test('cache flush POST shows message', strpos($html, '缓存已清空') !== false, substr($html, 0, 200));
test('cache flushAll cleared entry', \App\Support\Cache::get('test:flush') === null);

$html = runRequest([], ['action' => 'flush', 'csrf_token' => 'bad'], 'POST', ['siteops_uid' => 'csrfuser'], '/cache_manage.php');
test('cache flush bad csrf rejected', strpos($html, 'CSRF') !== false, substr($html, 0, 200));

@unlink($cacheCfgFile);
\App\Support\Cache::reset();

echo "== cache layer unit ==\n";
\App\Support\Cache::forget('unit:test');
test('cache get miss returns null', \App\Support\Cache::get('unit:test') === null);
\App\Support\Cache::set('unit:test', ['data' => 42], 60);
test('cache set+get roundtrip', \App\Support\Cache::get('unit:test')['data'] === 42);
$calls = 0;
$v1 = \App\Support\Cache::remember('unit:remember', 60, function () use (&$calls) { $calls++; return 'computed'; });
$v2 = \App\Support\Cache::remember('unit:remember', 60, function () use (&$calls) { $calls++; return 'computed'; });
test('cache remember computes once', $v1 === 'computed' && $v2 === 'computed' && $calls === 1, "calls=$calls");
\App\Support\Cache::forget('unit:remember');
\App\Support\Cache::forget('unit:test');
$wxCtx = $ts10 . '99wximport0';
$html = runRequest([], [
    'post_uuid' => $wxCtx,
    'post_url' => 'https://mp.weixin.qq.com/s/wxorigin',
    'post_title' => 'WeChat Imported Article',
    'post_static_thumbnail' => 'https://img.example.com/wxcover.jpg',
    'post_tag' => 'wechat, import',
    'post_keyword' => 'wechat import',
    'post_description' => 'Imported from WeChat.',
    'post_ckeditor_contents' => '<p>WeChat content here.</p>',
    'post_lang' => 'zh',
    'post_series' => 'news',
    'post_pubdir' => 'article',
    'post_savename' => 'wechat-imported-article',
    'post_pubdomain' => ['testpoker.com'],
    'post_globalpublish' => 'no',
    'csrf_token' => 'x',
    'setupNum' => 'ckeditorFormated',
], 'POST', [], '/wechat_import.php');
$wrows = db("SELECT * FROM article WHERE ctx_id = '$wxCtx'");
test('wechat POST inserts row', count($wrows) === 1, (string)count($wrows));
if (count($wrows) === 1) {
    test('wechat row fields', $wrows[0]['title'] === 'WeChat Imported Article' && $wrows[0]['url'] === 'https://mp.weixin.qq.com/s/wxorigin' && $wrows[0]['lang'] === 'zh' && $wrows[0]['savename'] === 'wechat-imported-article', $wrows[0]['title']);
    test('wechat row pubdomain', $wrows[0]['pubdomain'] === 'testpoker.com', $wrows[0]['pubdomain']);
    test('wechat row json content', strpos((string)$wrows[0]['json'], '<p>WeChat content here.</p>') !== false);
}
$ts10 = substr((string)time(), 0, 10);
$ctxId = $ts10 . '12345abcde';
$html = runRequest([], [
    'post_uuid' => $ctxId,
    'post_url' => 'https://example.com/original.html',
    'post_title' => 'Poker Strategy Guide',
    'post_static_thumbnail' => 'https://img.example.com/cover.jpg',
    'post_iframesrc' => 'https://img.example.com/gif1.gif',
    'post_tag' => 'poker, strategy, guide',
    'post_keyword' => 'poker strategy, texas holdem',
    'post_description' => 'A complete guide to poker strategy.',
    'post_ckeditor_contents' => '<h2>Intro</h2><p>Poker is fun.</p>',
    'post_lang' => 'en',
    'post_series' => 'news',
    'post_pubdir' => 'article',
    'post_savename' => 'poker-strategy-guide',
    'post_pubdomain' => ['testpoker.com', 'shpoker.com'],
    'post_globalpublish' => 'no',
    'post_translate_to_langs' => ['ja', 'es'],
    'csrf_token' => 'x',
    'setupNum' => 'ckeditorFormated',
], 'POST', [], '/article_new.php');
$arows = db("SELECT * FROM article WHERE ctx_id = '$ctxId'");
test('article POST inserts row', count($arows) === 1, (string)count($arows));
if (count($arows) === 1) {
    $arow = $arows[0];
    test('article row fields', $arow['title'] === 'Poker Strategy Guide' && $arow['keyword'] === 'poker strategy,texas holdem' && $arow['tags'] === 'poker,strategy,guide' && $arow['lang'] === 'en' && $arow['series'] === 'news' && $arow['pubdir'] === 'article' && $arow['globalpublish'] === 'no' && $arow['savename'] === 'poker-strategy-guide', $arow['title']);
    test('article row pubdomain joined', $arow['pubdomain'] === 'testpoker.com,shpoker.com', $arow['pubdomain']);
    test('article row translate langs joined', $arow['translate_to_langs'] === 'ja,es', $arow['translate_to_langs']);
    test('article row update_date Z format', preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string)$arow['update_date']) === 1, $arow['update_date']);
    $ajson = json_decode($arow['json'], true);
    test('article row json valid', is_array($ajson));
    if (is_array($ajson)) {
        test('article json createAt from uuid ts', isset($ajson['createAt']['text'][0]) && (int)$ajson['createAt']['text'][0] === (int)$ts10, var_export($ajson['createAt'] ?? null, true));
        test('article json title/tags/keywords split', $ajson['title']['text'][0] === 'Poker Strategy Guide' && $ajson['tags']['text'] === ['poker', 'strategy', 'guide'] && $ajson['keywords']['text'] === ['poker strategy', 'texas holdem'], '');
        test('article json content html', isset($ajson['content']['html'][0]) && strpos($ajson['content']['html'][0], '<p>Poker is fun.</p>') !== false, '');
        test('article json pubdomain + translate arrays', $ajson['pubdomain'] === ['testpoker.com', 'shpoker.com'] && $ajson['translate_to_langs'] === ['ja', 'es'], '');
        test('article json slug/savename/url', $ajson['slug']['text'][0] === 'poker-strategy-guide' && $ajson['savename']['text'][0] === 'poker-strategy-guide' && $ajson['url'] === 'https://example.com/original.html', '');
        test('article json upload_site default', ($ajson['upload_site'] ?? '') === 'https://wptg.wptdata.com', (string)($ajson['upload_site'] ?? ''));
        test('article json globalpublish no', ($ajson['globalpublish'] ?? '') === 'no', '');
    }
}
test('article json file written', file_exists($dataDir . '/json/' . $ctxId . '.json'));
if (file_exists($dataDir . '/json/' . $ctxId . '.json')) {
    $ajf = json_decode((string)file_get_contents($dataDir . '/json/' . $ctxId . '.json'), true);
    test('article json file matches row json', is_array($ajf) && $ajf['post_uuid'] === $ctxId && ($ajf['title']['text'][0] ?? '') === 'Poker Strategy Guide', '');
}
test('article confirm rendered', strpos($html, '文章已保存') !== false && strpos($html, 'json/') !== false && strpos($html, '再写一篇') !== false);
test('article confirm shows content html', strpos($html, '<p>Poker is fun.</p>') !== false);
test('article confirm shows pubdomain', strpos($html, 'testpoker.com,shpoker.com') !== false);
test('article no publish message without globalpublish', strpos($html, '发布任务已触发') === false);

echo "== articleops: upsert by ctx_id ==\n";
$html = runRequest([], [
    'post_uuid' => $ctxId,
    'post_url' => 'https://example.com/original.html',
    'post_title' => 'Poker Strategy Guide v2',
    'post_static_thumbnail' => 'https://img.example.com/cover2.jpg',
    'post_tag' => 'poker',
    'post_keyword' => 'poker strategy',
    'post_description' => 'Updated description.',
    'post_ckeditor_contents' => '<p>Updated content.</p>',
    'post_lang' => 'en',
    'post_series' => 'news',
    'post_pubdir' => 'article',
    'post_savename' => 'poker-strategy-guide',
    'post_pubdomain' => ['testpoker.com'],
    'post_globalpublish' => 'no',
    'csrf_token' => 'x',
    'setupNum' => 'ckeditorFormated',
], 'POST', [], '/article_new.php');
$arows = db("SELECT * FROM article WHERE ctx_id = '$ctxId'");
test('article upsert keeps single row', count($arows) === 1, (string)count($arows));
test('article upsert updates content', count($arows) === 1 && strpos($arows[0]['content'], 'Updated content.') !== false && $arows[0]['title'] === 'Poker Strategy Guide v2', $arows[0]['title'] ?? '');
test('article upsert replaces pubdomain', count($arows) === 1 && $arows[0]['pubdomain'] === 'testpoker.com', $arows[0]['pubdomain'] ?? '');

echo "== articleops: edit via eid ==\n";
$html = runRequest(['eid' => $ctxId], [], 'GET', [], '/article_new.php');
test('article eid backfills title', strpos($html, 'value="Poker Strategy Guide v2"') !== false, '');
test('article eid backfills content', strpos($html, 'Updated content.') !== false, '');
test('article eid keeps same uuid', strpos($html, 'value="' . $ctxId . '"') !== false, '');
test('article eid backfills pubdomain selection', preg_match('/<option data-subtext="testpoker\.com\([^"]*\)" selected>testpoker\.com<\/option>/', $html) === 1, '');
test('article unknown eid falls back to new form', strpos(runRequest(['eid' => 'nope-missing'], [], 'GET', [], '/article_new.php'), '新建文章') !== false);

echo "== articleops: publish with globalpublish=yes ==\n";
$pubCtx = $ts10 . '99887pub01';
$html = runRequest([], [
    'post_uuid' => $pubCtx,
    'post_url' => '',
    'post_title' => 'Publishable Article',
    'post_static_thumbnail' => 'https://img.example.com/pub.jpg',
    'post_tag' => 'poker',
    'post_keyword' => 'publish',
    'post_description' => 'publish me',
    'post_ckeditor_contents' => '<p>publish content</p>',
    'post_lang' => 'en',
    'post_series' => 'news',
    'post_pubdir' => 'article',
    'post_savename' => 'publishable-article',
    'post_pubdomain' => ['testpoker.com'],
    'post_globalpublish' => 'yes',
    'post_translate_to_langs' => ['zh'],
    'csrf_token' => 'x',
    'setupNum' => 'ckeditorFormated',
], 'POST', [], '/article_new.php');
test('article publish confirm shows message', strpos($html, '发布任务已触发') !== false, substr($html, 0, 300));
$astatus = $dataDir . '/seodata/aigc_status.json';
test('article publish writes seodata/aigc_status.json', file_exists($astatus), '');
if (file_exists($astatus)) {
    $astatusJson = json_decode((string)file_get_contents($astatus), true);
    test('article aigc_status entry format', is_array($astatusJson) && isset($astatusJson[0]['ctx_id']) && $astatusJson[0]['ctx_id'] === $pubCtx && isset($astatusJson[0]['keyword']) && $astatusJson[0]['keyword'] === 'Publishable Article' && isset($astatusJson[0]['lang']) && $astatusJson[0]['lang'] === 'en' && isset($astatusJson[0]['pubdomain']) && $astatusJson[0]['pubdomain'] === 'testpoker.com' && isset($astatusJson[0]['createAt']) && isset($astatusJson[0]['publishAt']), substr((string)file_get_contents($astatus), 0, 200));
    unlink($astatus);
}
$seoJsonFile = $dataDir . '/seodata/json/' . $pubCtx . '.json';
test('article publish writes seodata/json/{uuid}.json', file_exists($seoJsonFile), $seoJsonFile);
if (file_exists($seoJsonFile)) {
    $seoJson = json_decode((string)file_get_contents($seoJsonFile), true);
    test('article seodata json is list of one record', is_array($seoJson) && count($seoJson) === 1 && isset($seoJson[0]['post_uuid']) && $seoJson[0]['post_uuid'] === $pubCtx && isset($seoJson[0]['title']['text'][0]) && $seoJson[0]['title']['text'][0] === 'Publishable Article', substr((string)file_get_contents($seoJsonFile), 0, 200));
    unlink($seoJsonFile);
}

echo "== articleops: CSRF ==\n";
$html = runRequest([], [
    'post_uuid' => $ts10 . '66666csrf01',
    'post_title' => 'CSRF Attack Article',
    'post_static_thumbnail' => 'https://img.example.com/x.jpg',
    'post_tag' => 'x',
    'post_keyword' => 'x',
    'post_description' => 'x',
    'post_ckeditor_contents' => '<p>x</p>',
    'post_lang' => 'en',
    'post_series' => 'news',
    'post_pubdir' => 'article',
    'post_savename' => 'x',
    'csrf_token' => 'deadbeefdeadbeefdeadbeefdeadbeef',
    'setupNum' => 'ckeditorFormated',
], 'POST', ['siteops_uid' => 'client1'], '/article_new.php');
test('article CSRF bad token rejected', strpos($html, 'CSRF token invalid') !== false, substr($html, 0, 120));
test('article CSRF rejection writes nothing', count(db("SELECT * FROM article WHERE ctx_id = '" . $ts10 . "66666csrf01'")) === 0);

echo "== articleops: list ==\n";
$html = runRequest([], [], 'GET', [], '/article_list.php');
test('article list renders table', strpos($html, '文章列表') !== false && strpos($html, 'articleTable') !== false);
test('article list edit link', strpos($html, 'article_new.php?eid=') !== false && strpos($html, 'articleDeleteConfirm') !== false && strpos($html, 'articleDeleteModal') !== false);
$importScriptPos = strpos($html, "var executeBtn = document.getElementById('articleImportExecuteBtn')");
$importModalPos = strpos($html, 'id="articleImportModal"');
test('article import script placed after modal markup', $importModalPos !== false && $importScriptPos !== false && $importModalPos < $importScriptPos);
test('article list unique ctx_id field (sort fix)', substr_count($html, "field: 'ctx_id'") === 1 && strpos($html, "field: '_op'") !== false);
$json = runRequest(['format' => 'json', 'offset' => 0, 'limit' => 20], [], 'GET', [], '/article_list.php');
$payload = json_decode($json, true);
test('article list json endpoint', is_array($payload) && isset($payload['total']) && (int)$payload['total'] >= 2 && is_array($payload['rows']) && isset($payload['rows'][0]['ctx_id']), substr($json, 0, 120));
$json = runRequest(['format' => 'json', 'search' => 'Poker Strategy Guide v2'], [], 'GET', [], '/article_list.php');
$payload = json_decode($json, true);
test('article list json search', is_array($payload) && (int)$payload['total'] === 1 && $payload['rows'][0]['ctx_id'] === $ctxId, substr($json, 0, 120));
$json = runRequest(['format' => 'json', 'sort' => 'id', 'order' => 'asc'], [], 'GET', [], '/article_list.php');
$payload = json_decode($json, true);
test('article list json sort asc', is_array($payload) && isset($payload['rows'][0]['ctx_id']), substr($json, 0, 120));

echo "== article list delete ==\n";
$json = runRequest([], ['action' => 'delete-article', 'ctx_id' => $ctxId, 'csrf_token' => 'x'], 'POST', [], '/article_list.php');
$payload = json_decode($json, true);
test('article list delete removes row', is_array($payload) && isset($payload['rows'][0]['ok']) && $payload['rows'][0]['ok'] === true && App\Repositories\ArticleRepository::byCtxId($ctxId) === null, substr($json, 0, 120));
$json = runRequest([], ['action' => 'delete-article', 'ctx_id' => 'NOPE-MISSING', 'csrf_token' => 'x'], 'POST', [], '/article_list.php');
$payload = json_decode($json, true);
test('article list delete unknown ctx rejected', is_array($payload) && isset($payload['rows'][0]['ok']) && $payload['rows'][0]['ok'] === false, substr($json, 0, 120));
$json = runRequest([], ['action' => 'delete-article', 'ctx_id' => $pubCtx, 'csrf_token' => 'x'], 'POST', [], '/article_list.php');
$payload = json_decode($json, true);
test('article delete removes published row', is_array($payload) && $payload['rows'][0]['ok'] === true, substr($json, 0, 120));

echo "== article list import log ==\n";
$json = runRequest([], ['action' => 'import-log', 'csrf_token' => 'x'], 'POST', [], '/article_list.php');
$payload = json_decode($json, true);
$firstRow = is_array($payload) && isset($payload['rows'][0]) ? $payload['rows'][0] : null;
test('article import-log returns summary', is_array($payload) && isset($firstRow['ok']) && $firstRow['ok'] === true && strpos((string)$firstRow['message'], '导入完成') !== false, substr($json, 0, 200));
$sumMsg = is_array($firstRow) ? (string)$firstRow['message'] : '';
$sumImported = preg_match('/新增 (\d+) 条/', $sumMsg, $m) ? (int)$m[1] : -1;
$sumSkipped = preg_match('/跳过 (\d+) 条/', $sumMsg, $m) ? (int)$m[1] : -1;
$sumMissing = preg_match('/缺失跳过 (\d+) 条/', $sumMsg, $m) ? (int)$m[1] : -1;
$sumFailed = preg_match('/失败 (\d+) 条/', $sumMsg, $m) ? (int)$m[1] : -1;
test('article import-log counts sum to 39 lines', $sumImported + $sumSkipped + $sumMissing + $sumFailed === 39 && $sumImported >= 30, $sumMsg);
$importedFirst = is_array($payload) && isset($payload['rows'][1]) ? $payload['rows'][1] : null;
$importedCtx = is_array($importedFirst) && !empty($importedFirst['ctx_id']) ? $importedFirst['ctx_id'] : '172620874766e3daeb9ae0f483394068';
$importedRow = App\Repositories\ArticleRepository::byCtxId($importedCtx);
test('article import-log row inserted with title', is_array($importedRow) && !empty($importedRow['title']), json_encode($importedRow, JSON_UNESCAPED_UNICODE));
$wxRow = App\Repositories\ArticleRepository::byCtxId('17868883506a81c09ebdc58113462089');
test('article import-log okx wechat row url from second column', is_array($wxRow) && (string)$wxRow['url'] === 'https://mp.weixin.qq.com/s/JvS8-gBEc-epBamg3knzSg', json_encode($wxRow, JSON_UNESCAPED_UNICODE));
test('article import-log writes seodata/json for imported row', file_exists($dataDir . '/seodata/json/' . $importedCtx . '.json'));
$seoJson = file_exists($dataDir . '/seodata/json/' . $importedCtx . '.json') ? json_decode((string)file_get_contents($dataDir . '/seodata/json/' . $importedCtx . '.json'), true) : null;
test('article import-log seodata/json is list of one record', is_array($seoJson) && count($seoJson) === 1 && isset($seoJson[0]['post_uuid']) && $seoJson[0]['post_uuid'] === $importedCtx, json_encode($seoJson, JSON_UNESCAPED_UNICODE));
test('article import-log updates aigc_status.json', file_exists($dataDir . '/seodata/aigc_status.json'));
$aigc = file_exists($dataDir . '/seodata/aigc_status.json') ? json_decode((string)file_get_contents($dataDir . '/seodata/aigc_status.json'), true) : null;
$aigcCtxs = is_array($aigc) ? array_column($aigc, 'ctx_id') : [];
test('article import-log aigc_status contains wechat row', is_array($aigcCtxs) && in_array('17868883506a81c09ebdc58113462089', $aigcCtxs, true), json_encode($aigc, JSON_UNESCAPED_UNICODE));
test('article import-log json file written', file_exists($dataDir . '/json/' . $importedCtx . '.json'));
$json = runRequest([], ['action' => 'import-log', 'csrf_token' => 'x'], 'POST', [], '/article_list.php');
$payload = json_decode($json, true);
$secondMsg = is_array($payload) && isset($payload['rows'][0]) ? (string)$payload['rows'][0]['message'] : '';
test('article import-log second run all skipped', preg_match('/新增 (\d+) 条/', $secondMsg, $m) === 1 && (int)$m[1] === 0, $secondMsg);

$tmpLog = $tmpDir . '/import_test.txt';
file_put_contents($tmpLog, $importedCtx . '|' . $importedCtx . '|{"title":{"text":["SKIPME"]},"post_uuid":"' . $importedCtx . '"}' . "\n" . "BADLINE|NOPE|{not-json" . "\n" . "NEWIMPORT001|NEWIMPORT001|{\"post_uuid\":\"NEWIMPORT001\",\"title\":{\"text\":[\"Imported New\"]},\"content\":{\"html\":[\"<p>hello</p>\"]},\"lang\":\"zh\"}" . "\n");
$svcResult = App\Services\ArticleService::importFromLogFile($tmpLog);
test('article import-log service counts', is_array($svcResult) && $svcResult['imported'] === 1 && $svcResult['skipped'] === 1 && $svcResult['failed'] === 1, json_encode($svcResult, JSON_UNESCAPED_UNICODE));
$svcRow = App\Repositories\ArticleRepository::byCtxId('NEWIMPORT001');
test('article import-log service row inserted', is_array($svcRow) && $svcRow['title'] === 'Imported New', json_encode($svcRow, JSON_UNESCAPED_UNICODE));
test('article import-log service json file written', file_exists($dataDir . '/json/NEWIMPORT001.json'));
$dupLog = $tmpDir . '/import_dup_test.txt';
file_put_contents($dupLog, "DUPCTX001|https://example.com/a|{\"post_uuid\":\"DUPCTX001\",\"title\":{\"text\":[\"First\"]}}\nDUPCTX001|https://example.com/b|{\"post_uuid\":\"DUPCTX001\",\"title\":{\"text\":[\"Second\"]}}\nNOJSONCTX01|https://example.com/c|/www/wwwroot/server/pokerjson/NOJSONCTX01.json\n");
$dupResult = App\Services\ArticleService::importFromLogFile($dupLog);
test('article import-log duplicate ctx skipped not failed', is_array($dupResult) && $dupResult['imported'] === 1 && $dupResult['skipped'] === 1 && $dupResult['missing'] === 1 && $dupResult['failed'] === 0, json_encode($dupResult, JSON_UNESCAPED_UNICODE));
$dupMissingRow = null;
foreach ((is_array($dupResult) ? $dupResult['rows'] : []) as $dupRow) {
    if (!empty($dupRow['missing'])) {
        $dupMissingRow = $dupRow;
        break;
    }
}
test('article import-log missing json reports raw path', is_array($dupMissingRow) && strpos((string)$dupMissingRow['message'], '/www/wwwroot/server/pokerjson/NOJSONCTX01.json') !== false, json_encode($dupMissingRow));
test('article import-log resolves absolute log path', App\Services\ArticleService::resolveLogPath('/abs/dir/log.txt') === '/abs/dir/log.txt' && App\Services\ArticleService::resolveLogPath('C:\\logs\\a.txt') === 'C:\\logs\\a.txt' && App\Services\ArticleService::resolveLogPath('editor_poker_allpost_list.txt') === APP_PATH . '/editor_poker_allpost_list.txt', App\Services\ArticleService::resolveLogPath('editor_poker_allpost_list.txt'));
$json = runRequest([], ['action' => 'import-log', 'csrf_token' => 'x'], 'POST', [], '/article_list.php');
$payload = json_decode($json, true);
$msgAfterSvc = is_array($payload) && isset($payload['rows'][0]) ? (string)$payload['rows'][0]['message'] : '';
test('article import-log service row skipped by http run', preg_match('/新增 (\d+) 条/', $msgAfterSvc, $m) === 1 && (int)$m[1] === 0, $msgAfterSvc);

$okxJson = '{"post_uuid":"OKXNEW01","title":{"text":["Okx Imported"]},"content":{"html":["<p>hi</p>"]},"lang":"zh","url":"https://mp.weixin.qq.com/s/OKXNEW01"}';
file_put_contents($tmpDir . '/okx_ref.json', $okxJson);
file_put_contents($tmpLog, "OKXNEW01|https://mp.weixin.qq.com/s/OKXNEW01|" . $tmpDir . "/okx_ref.json" . "\n" . "OKXMISS01|https://mp.weixin.qq.com/s/OKXMISS01|/www/wwwroot/wpk.cpanice.com/wpk/pokerjson/OKXMISS01.json" . "\n");
$svcResult = App\Services\ArticleService::importFromLogFile($tmpLog);
test('article import-log path format imports', is_array($svcResult) && $svcResult['imported'] === 1 && $svcResult['missing'] === 1 && $svcResult['failed'] === 0, json_encode($svcResult, JSON_UNESCAPED_UNICODE));
$okxRow = App\Repositories\ArticleRepository::byCtxId('OKXNEW01');
test('article import-log path format row inserted', is_array($okxRow) && $okxRow['title'] === 'Okx Imported' && $okxRow['url'] === 'https://mp.weixin.qq.com/s/OKXNEW01', json_encode($okxRow, JSON_UNESCAPED_UNICODE));
test('article import-log path format missing json skipped', App\Repositories\ArticleRepository::byCtxId('OKXMISS01') === null);
$okxLocal = $root . '/pokerjson/17868925356a81d0f739601057407153.json';
file_put_contents($tmpLog, "OKXREL01|OKXREL01|/www/wwwroot/wpk.cpanice.com/wpk/pokerjson/17868925356a81d0f739601057407153.json\n");
$svcResult = App\Services\ArticleService::importFromLogFile($tmpLog);
$okxRelRow = App\Repositories\ArticleRepository::byCtxId('OKXREL01');
test('article import-log path format basename fallback', is_array($svcResult) && $svcResult['imported'] === 1 && is_array($okxRelRow) && !empty($okxRelRow['title']) && (string)$okxRelRow['ctx_id'] === 'OKXREL01', json_encode($svcResult, JSON_UNESCAPED_UNICODE));

echo "== seo_report ==\n";
$html = runRequest([], [], 'GET', [], '/seo_report.php');
test('report default wordlist renders', strpos($html, '全部关键词') !== false && strpos($html, 'bootstrapTable') !== false);
test('report wordlist edit column present', strpos($html, 'keywordEditer') !== false);
$html = runRequest(['reporttype' => 'wordlist'], [], 'GET', [], '/seo_report.php');
test('report wordlist edit link to keywordops', strpos($html, 'keywordops.php?eid=') !== false);
$json = runRequest(['format' => 'json', 'reporttype' => 'wordlist'], [], 'GET', [], '/seo_report.php');
$payload = json_decode($json, true);
test('report wordlist json returns total+rows', is_array($payload) && isset($payload['total']) && $payload['total'] >= 1 && isset($payload['rows'][0]['keyword']), substr($json, 0, 120));
test('report wordlist json contains exported keyword', strpos($json, 'texas holdem') !== false);
$json = runRequest(['format' => 'json', 'reporttype' => 'wordlist', 'search' => 'texas'], [], 'GET', [], '/seo_report.php');
$payload = json_decode($json, true);
test('report wordlist json search filters', is_array($payload) && $payload['total'] >= 1 && strpos($json, 'texas holdem') !== false, substr($json, 0, 120));
$json = runRequest(['format' => 'json', 'reporttype' => 'wordlist', 'offset' => 0, 'limit' => 1], [], 'GET', [], '/seo_report.php');
$payload = json_decode($json, true);
$firstKw = is_array($payload) && isset($payload['rows'][0]) ? $payload['rows'][0]['id'] : null;
$json = runRequest(['format' => 'json', 'reporttype' => 'wordlist', 'offset' => 1, 'limit' => 1], [], 'GET', [], '/seo_report.php');
$payload = json_decode($json, true);
$secondKw = is_array($payload) && isset($payload['rows'][0]) ? $payload['rows'][0]['id'] : null;
test('report wordlist json offset paging differs', $secondKw !== null && $secondKw !== $firstKw, 'first=' . var_export($firstKw, true) . ' second=' . var_export($secondKw, true));
$html = runRequest(['reporttype' => 'topiclist'], [], 'GET', [], '/seo_report.php');
test('report topiclist renders rows', strpos($html, '话题列表') !== false && strpos($html, 'topicEditer') !== false);
test('report topiclist edit link to topicops', strpos($html, 'topicops.php?eid=') !== false);
$json = runRequest(['format' => 'json', 'reporttype' => 'topiclist'], [], 'GET', [], '/seo_report.php');
test('report topiclist json contains exported topic', strpos($json, 'poker strategy guide') !== false, substr($json, 0, 120));
$html = runRequest(['reporttype' => 'sitelist'], [], 'GET', [], '/seo_report.php');
test('report sitelist renders rows', strpos($html, '站点数据') !== false);
test('report sitelist edit link to siteops', strpos($html, 'siteops.php?eid=') !== false);
test('report sitelist action column name', strpos($html, '"title":"操作"') !== false, strpos($html, '"title":"操作"') !== false ? '' : 'column title not 操作');
test('report sitelist delete button rendered', strpos($html, 'siteDeleteConfirm') !== false && strpos($html, 'siteDeleteModal') !== false);
$json = runRequest(['format' => 'json', 'reporttype' => 'sitelist'], [], 'GET', [], '/seo_report.php');
test('report sitelist json contains test site', strpos($json, 'testpoker.com') !== false, substr($json, 0, 120));
$json = runRequest(['format' => 'json', 'reporttype' => 'sitelist', 'search' => 'testpoker.com'], [], 'GET', [], '/seo_report.php');
$payload = json_decode($json, true);
test('report sitelist json search filters', is_array($payload) && $payload['total'] >= 1 && isset($payload['rows'][0]['domain']) && $payload['rows'][0]['domain'] === 'testpoker.com', substr($json, 0, 120));

// --- siteops_setting.txt 导入：兼容 12 列现行布局与 11 列旧版布局 ---
echo "== siteops_setting import ==\n";
$impToken = substr(hash_hmac('sha256', 'csrf|impuid1', 'testcsrfsecret'), 0, 32);
$settingFile = $GLOBALS['dataDir'] . '/siteops_setting.txt';
$legacyJson = json_encode([
    'git_name' => 'legacysite.com', 'post_uuid' => null, 'git_account' => 'legacyacct',
    'domain' => 'legacysite.com', 'site_title' => 'Legacy Site', 'site_logo' => 'https://img.example.com/l.png',
    'languages' => 'vi', 'sns_id' => '@Legacy', 'topnav_menus' => 'A,B', 'keyword' => 'kw1,kw2',
    'theme_name' => 'legacysite.com', 'theme_type' => 'sport', 'sitedir' => '/root/hugo/legacysite.com',
    'deploy' => 'cloudflare', 'hostip' => '10.0.0.9', 'local_deploy' => 'cloudflare', 'local_hostip' => '10.0.0.9',
    'site_subtitle' => 'Legacy subtitle', 'status' => 'done',
], JSON_UNESCAPED_UNICODE);
// 旧版 = EXPORT_COLUMNS 去掉 git_account，共 11 列
$legacyLine = implode('|', [
    'LEGACYCTX001', 'legacysite.com', 'done', 'sport', 'vi',
    'legacysite.com', '@Legacy', 'A,B', 'Legacy Site', 'Legacy subtitle', $legacyJson,
]);
file_put_contents($settingFile, $legacyLine . "\n");
$parsedLegacy = App\Services\SiteService::parseSettingLine($legacyLine);
test('parseSettingLine accepts legacy 11 columns', is_array($parsedLegacy) && $parsedLegacy['legacy'] === true, var_export($parsedLegacy === null, true));
test('parseSettingLine recovers git_account from json', is_array($parsedLegacy) && $parsedLegacy['git_account'] === 'legacyacct', json_encode($parsedLegacy['git_account'] ?? null));
test('parseSettingLine maps legacy status not theme_type', is_array($parsedLegacy) && $parsedLegacy['status'] === 'done' && $parsedLegacy['theme_type'] === 'sport' && $parsedLegacy['languages'] === 'vi', json_encode([$parsedLegacy['status'] ?? null, $parsedLegacy['theme_type'] ?? null, $parsedLegacy['languages'] ?? null]));
test('parseSettingLine maps legacy sns_id from index 6', is_array($parsedLegacy) && $parsedLegacy['sns_id'] === '@Legacy' && $parsedLegacy['domain'] === 'legacysite.com', json_encode([$parsedLegacy['sns_id'] ?? null, $parsedLegacy['domain'] ?? null]));
$currentLine = implode('|', [
    'CURRENTCTX01', 'cursite.com', 'curacct', 'draft', 'poker', 'en',
    'cursite.com', 'cursns', 'C,D', 'Cur Site', 'Cur subtitle', $legacyJson,
]);
$parsedCurrent = App\Services\SiteService::parseSettingLine($currentLine);
test('parseSettingLine accepts current 12 columns', is_array($parsedCurrent) && $parsedCurrent['legacy'] === false && $parsedCurrent['git_account'] === 'curacct', json_encode($parsedCurrent['git_account'] ?? null));
test('parseSettingLine rejects wrong column count', App\Services\SiteService::parseSettingLine('a|b|c') === null, 'should be null');
test('parseSettingLine rejects invalid json tail', App\Services\SiteService::parseSettingLine(implode('|', array_fill(0, 11, 'x'))) === null, 'should be null');

$html = runRequest([], ['action' => 'import-site', 'csrf_token' => $impToken], 'POST', ['siteops_uid' => 'impuid1'], '/seo_report.php');
$payload = json_decode($html, true);
test('import-site imports legacy 11-column row', is_array($payload) && $payload['ok'] === true && strpos($payload['message'], '新增 1 条') !== false, substr($html, 0, 200));
test('import-site reports legacy row count', is_array($payload) && strpos($payload['message'], '旧版 11 列格式') !== false, substr($html, 0, 200));
$legacyRow = db("SELECT * FROM siteops WHERE ctx_id = 'LEGACYCTX001'");
test('import-site persisted legacy row', count($legacyRow) === 1 && $legacyRow[0]['domain'] === 'legacysite.com', json_encode($legacyRow));
test('import-site recovered git_account into column', count($legacyRow) === 1 && $legacyRow[0]['git_account'] === 'legacyacct', json_encode($legacyRow[0]['git_account'] ?? null));
test('import-site kept status from index 2 not index 3', count($legacyRow) === 1 && $legacyRow[0]['status'] === 'done' && $legacyRow[0]['theme_type'] === 'sport', json_encode($legacyRow));
$exportedLegacy = exportLines();
$legacyWidth = count($exportedLegacy) ? count(explode('|', $exportedLegacy[0])) : 0;
test('export after legacy import writes 12 columns', $legacyWidth === 12, 'width=' . $legacyWidth);

file_put_contents($settingFile, $currentLine . "\n");
$html = runRequest([], ['action' => 'import-site', 'csrf_token' => $impToken], 'POST', ['siteops_uid' => 'impuid1'], '/seo_report.php');
$payload = json_decode($html, true);
test('import-site imports current 12-column row', is_array($payload) && $payload['ok'] === true && strpos($payload['message'], '新增 1 条') !== false, substr($html, 0, 200));
$currentRow = db("SELECT * FROM siteops WHERE ctx_id = 'CURRENTCTX01'");
test('import-site persisted current row', count($currentRow) === 1 && $currentRow[0]['git_account'] === 'curacct' && $currentRow[0]['status'] === 'draft', json_encode($currentRow));

// 全量失败时必须跳过导出，否则会用库状态覆盖用户源文件
$doomed = implode('|', ['TOOBADCTX01', 'x', 'y']);
file_put_contents($settingFile, $doomed . "\n" . implode('|', ['TOOBADCTX02', 'p', 'q']) . "\n");
$html = runRequest([], ['action' => 'import-site', 'csrf_token' => $impToken], 'POST', ['siteops_uid' => 'impuid1'], '/seo_report.php');
$payload = json_decode($html, true);
test('import-site rejects all-bad file', is_array($payload) && $payload['ok'] === false && strpos($payload['message'], '全部无法解析') !== false, substr($html, 0, 200));
$after = trim((string)@file_get_contents($settingFile));
test('import-site does not overwrite source file on total failure', $after === $doomed . "\n" . implode('|', ['TOOBADCTX02', 'p', 'q']), 'source=' . $after);

// ReportService::sitelist 同样需接受旧版 11 列
file_put_contents($settingFile, $legacyLine . "\n");
$sitelistRows = App\Services\ReportService::sitelist();
test('ReportService sitelist reads legacy 11 columns', count($sitelistRows) === 1 && $sitelistRows[0]['git_account'] === 'legacyacct', json_encode($sitelistRows));
test('ReportService sitelist legacy row has all 12 keys', count($sitelistRows) === 1 && count(array_diff(App\Services\ReportService::SITELIST_COLUMNS, array_keys($sitelistRows[0]))) === 0, json_encode(array_keys($sitelistRows[0] ?? [])));

// --- topic_monitor_list.txt 导入：兼容 8 列现行布局与 9 列旧版布局 ---
echo "== topic_monitor_list import ==\n";
$impTokenT = substr(hash_hmac('sha256', 'csrf|imptuid1', 'testcsrfsecret'), 0, 32);
$topicFile = $dataDir . '/topic_monitor_list.txt';
$topicJson = json_encode([
    'git_name' => 'legacy-topic.com', 'domain' => 'legacy-topic.com', 'keyword' => 'legacy kw',
    'pubdir' => 'article', 'status' => 'enable', 'lang' => 'vi', 'geo' => 'JP', 'lasttask' => '20260101',
], JSON_UNESCAPED_UNICODE);
// 旧版 9 列 = topic_bulk_update_via_import.php 的列序，geo 为独立列
$topicLegacyLine = implode('|', [
    'LEGACYTOPIC1', 'legacy-topic.com', 'legacy-topic.com', 'legacy kw', 'article', 'enable', 'vi', 'JP', $topicJson,
]);
$parsedTopicLegacy = App\Services\TopicService::parseMonitorLine($topicLegacyLine);
test('parseMonitorLine accepts legacy 9 columns', is_array($parsedTopicLegacy) && $parsedTopicLegacy['legacy'] === true, var_export($parsedTopicLegacy === null, true));
test('parseMonitorLine legacy geo from column not json', is_array($parsedTopicLegacy) && $parsedTopicLegacy['geo'] === 'JP', json_encode($parsedTopicLegacy['geo'] ?? null));
test('parseMonitorLine legacy maps git_name/domain/keyword', is_array($parsedTopicLegacy) && $parsedTopicLegacy['git_name'] === 'legacy-topic.com' && $parsedTopicLegacy['domain'] === 'legacy-topic.com' && $parsedTopicLegacy['keyword'] === 'legacy kw', json_encode([$parsedTopicLegacy['git_name'] ?? null, $parsedTopicLegacy['keyword'] ?? null]));
test('parseMonitorLine legacy reads lasttask from json', is_array($parsedTopicLegacy) && $parsedTopicLegacy['lasttask'] === '20260101', json_encode($parsedTopicLegacy['lasttask'] ?? null));
$topicCurrentLine = implode('|', [
    'CURRENTTOPIC1', 'current kw', 'enable', 'cur-topic.com', 'cur-topic.com', 'article', 'en', $topicJson,
]);
$parsedTopicCurrent = App\Services\TopicService::parseMonitorLine($topicCurrentLine);
test('parseMonitorLine accepts current 8 columns', is_array($parsedTopicCurrent) && $parsedTopicCurrent['legacy'] === false, json_encode($parsedTopicCurrent['legacy'] ?? null));
test('parseMonitorLine current geo from json fallback', is_array($parsedTopicCurrent) && $parsedTopicCurrent['geo'] === 'JP', json_encode($parsedTopicCurrent['geo'] ?? null));
test('parseMonitorLine rejects wrong column count', App\Services\TopicService::parseMonitorLine('a|b|c') === null, 'should be null');
test('parseMonitorLine rejects invalid json tail', App\Services\TopicService::parseMonitorLine(implode('|', array_fill(0, 8, 'x'))) === null, 'should be null');

file_put_contents($topicFile, $topicLegacyLine . "\n");
$html = runRequest([], ['action' => 'import-topic', 'csrf_token' => $impTokenT], 'POST', ['siteops_uid' => 'imptuid1'], '/topiclist.php');
$payload = json_decode($html, true);
$impMsg = $payload['rows'][0]['message'] ?? '';
test('import-topic imports legacy 9-column row', is_array($payload) && ($payload['rows'][0]['ok'] ?? false) === true && strpos($impMsg, '新增 1 条') !== false, substr($html, 0, 200));
test('import-topic reports legacy row count', strpos($impMsg, '旧版 9 列格式') !== false, substr($html, 0, 200));
$legacyTopicRow = db("SELECT * FROM sitetopic WHERE ctx_id = 'LEGACYTOPIC1'");
test('import-topic persisted legacy row', count($legacyTopicRow) === 1 && $legacyTopicRow[0]['keyword'] === 'legacy kw' && $legacyTopicRow[0]['domain'] === 'legacy-topic.com', json_encode($legacyTopicRow));
test('import-topic persisted legacy geo', count($legacyTopicRow) === 1 && $legacyTopicRow[0]['geo'] === 'JP', json_encode($legacyTopicRow[0]['geo'] ?? null));
test('import-topic persisted legacy status not pubdir', count($legacyTopicRow) === 1 && $legacyTopicRow[0]['status'] === 'enable' && $legacyTopicRow[0]['pubdir'] === 'article', json_encode($legacyTopicRow));
$topicExportLines = array_values(array_filter(file($topicFile, FILE_IGNORE_NEW_LINES), function ($l) { return trim($l) !== ''; }));
$topicWidth = count($topicExportLines) ? count(explode('|', $topicExportLines[0])) : 0;
test('export after legacy topic import writes 8 columns', $topicWidth === 8, 'width=' . $topicWidth);

file_put_contents($topicFile, $topicCurrentLine . "\n");
$html = runRequest([], ['action' => 'import-topic', 'csrf_token' => $impTokenT], 'POST', ['siteops_uid' => 'imptuid1'], '/topiclist.php');
$payload = json_decode($html, true);
test('import-topic imports current 8-column row', ($payload['rows'][0]['ok'] ?? false) === true && strpos($payload['rows'][0]['message'] ?? '', '新增 1 条') !== false, substr($html, 0, 200));
$currentTopicRow = db("SELECT * FROM sitetopic WHERE ctx_id = 'CURRENTTOPIC1'");
test('import-topic persisted current row', count($currentTopicRow) === 1 && $currentTopicRow[0]['keyword'] === 'current kw' && $currentTopicRow[0]['geo'] === 'JP', json_encode($currentTopicRow));

// 全量失败时必须跳过导出，否则会用库状态覆盖用户源文件
$badTopic1 = implode('|', ['BADTOPIC001', 'x', 'y']);
$badTopic2 = implode('|', ['BADTOPIC002', 'p', 'q']);
file_put_contents($topicFile, $badTopic1 . "\n" . $badTopic2 . "\n");
$html = runRequest([], ['action' => 'import-topic', 'csrf_token' => $impTokenT], 'POST', ['siteops_uid' => 'imptuid1'], '/topiclist.php');
$payload = json_decode($html, true);
test('import-topic rejects all-bad file', ($payload['rows'][0]['ok'] ?? true) === false && strpos($payload['rows'][0]['message'] ?? '', '全部无法解析') !== false, substr($html, 0, 200));
$topicAfter = trim((string)@file_get_contents($topicFile));
test('import-topic does not overwrite source file on total failure', $topicAfter === $badTopic1 . "\n" . $badTopic2, 'source=' . $topicAfter);

// ReportService::topiclist 同样需接受旧版 9 列
file_put_contents($topicFile, $topicLegacyLine . "\n");
$topicRows = App\Services\ReportService::parseColumns(
    [$topicLegacyLine],
    App\Services\ReportService::TOPICLIST_COLUMNS,
    '',
    App\Services\TopicService::LEGACY_EXPORT_COLUMNS
);
test('ReportService topiclist reads legacy 9 columns', count($topicRows) === 1 && $topicRows[0]['keyword'] === 'legacy kw', json_encode($topicRows));
test('ReportService topiclist legacy row has all 8 keys', count($topicRows) === 1 && count(array_diff(App\Services\ReportService::TOPICLIST_COLUMNS, array_keys($topicRows[0]))) === 0, json_encode(array_keys($topicRows[0] ?? [])));

// --- keyword_monitor_list.txt 导入：与 site/topic 收敛为同一套严格解析 ---
echo "== keyword_monitor_list import ==\n";
$impTokenK = substr(hash_hmac('sha256', 'csrf|impkuid1', 'testcsrfsecret'), 0, 32);
$kwFile = $dataDir . '/keyword_monitor_list.txt';
$kwJson = json_encode([
    'git_name' => 'legacy-kw.com', 'domain' => 'legacy-kw.com', 'keyword' => 'legacy kw',
    'pubdir' => 'article', 'status' => 'enable', 'lang' => 'vi', 'geo' => 'JP', 'lasttask' => '20260202',
], JSON_UNESCAPED_UNICODE);
$kwLine = implode('|', ['LEGACYKW00001', 'legacy kw', 'enable', 'legacy-kw.com', 'article', 'vi', $kwJson]);
$parsedKw = App\Services\KeywordService::parseMonitorLine($kwLine);
test('parseMonitorLine keyword accepts 7 columns', is_array($parsedKw) && $parsedKw['legacy'] === false, var_export($parsedKw === null, true));
test('parseMonitorLine keyword reads geo from json', is_array($parsedKw) && $parsedKw['geo'] === 'JP', json_encode($parsedKw['geo'] ?? null));
test('parseMonitorLine keyword reads lasttask from json', is_array($parsedKw) && $parsedKw['lasttask'] === '20260202', json_encode($parsedKw['lasttask'] ?? null));
test('parseMonitorLine keyword maps all 6 columns', is_array($parsedKw) && $parsedKw['keyword'] === 'legacy kw' && $parsedKw['status'] === 'enable' && $parsedKw['git_name'] === 'legacy-kw.com' && $parsedKw['pubdir'] === 'article' && $parsedKw['lang'] === 'vi', json_encode($parsedKw));
test('parseMonitorLine keyword rejects 8 columns (wrong width)', App\Services\KeywordService::parseMonitorLine($kwLine . '|extra') === null, 'should be null');
test('parseMonitorLine keyword rejects 6 columns (wrong width)', App\Services\KeywordService::parseMonitorLine(implode('|', array_slice(explode('|', $kwLine), 0, 6))) === null, 'should be null');
test('parseMonitorLine keyword rejects invalid json tail', App\Services\KeywordService::parseMonitorLine(implode('|', array_fill(0, 7, 'x'))) === null, 'should be null');
test('parseMonitorLine keyword rejects empty ctx_id', App\Services\KeywordService::parseMonitorLine(implode('|', ['', 'kw', 'enable', 'g.com', 'article', 'en', $kwJson])) === null, 'should be null');

file_put_contents($kwFile, $kwLine . "\n");
$html = runRequest([], ['action' => 'import-keyword', 'csrf_token' => $impTokenK], 'POST', ['siteops_uid' => 'impkuid1'], '/seo_report.php');
$payload = json_decode($html, true);
test('import-keyword imports 7-column row', is_array($payload) && $payload['ok'] === true && strpos($payload['message'], '新增 1 条') !== false, substr($html, 0, 200));
$legacyKwRow = db("SELECT * FROM keywordmonitorlist WHERE ctx_id = 'LEGACYKW00001'");
test('import-keyword persisted row', count($legacyKwRow) === 1 && $legacyKwRow[0]['keyword'] === 'legacy kw' && $legacyKwRow[0]['git_name'] === 'legacy-kw.com', json_encode($legacyKwRow));
test('import-keyword persisted geo from json', count($legacyKwRow) === 1 && $legacyKwRow[0]['geo'] === 'JP', json_encode($legacyKwRow[0]['geo'] ?? null));
test('import-keyword persisted lasttask from json', count($legacyKwRow) === 1 && $legacyKwRow[0]['lasttask'] === '20260202', json_encode($legacyKwRow[0]['lasttask'] ?? null));
$kwExportLines = array_values(array_filter(file($kwFile, FILE_IGNORE_NEW_LINES), function ($l) { return trim($l) !== ''; }));
$kwWidth = count($kwExportLines) ? count(explode('|', $kwExportLines[0])) : 0;
test('export after keyword import writes 7 columns', $kwWidth === 7, 'width=' . $kwWidth);

// 全量失败时必须跳过导出，否则会用库状态覆盖用户源文件
$badKw1 = implode('|', ['BADKW000001', 'x', 'y']);
$badKw2 = implode('|', ['BADKW000002', 'p', 'q']);
file_put_contents($kwFile, $badKw1 . "\n" . $badKw2 . "\n");
$html = runRequest([], ['action' => 'import-keyword', 'csrf_token' => $impTokenK], 'POST', ['siteops_uid' => 'impkuid1'], '/seo_report.php');
$payload = json_decode($html, true);
test('import-keyword rejects all-bad file', is_array($payload) && $payload['ok'] === false && strpos($payload['message'], '全部无法解析') !== false, substr($html, 0, 200));
$kwAfter = trim((string)@file_get_contents($kwFile));
test('import-keyword does not overwrite source file on total failure', $kwAfter === $badKw1 . "\n" . $badKw2, 'source=' . $kwAfter);

// 混入坏行时：好行仍应导入，且坏行计数正确
file_put_contents($kwFile, $kwLine . "\n" . $badKw1 . "\n" . $badKw2 . "\n");
$html = runRequest([], ['action' => 'import-keyword', 'csrf_token' => $impTokenK], 'POST', ['siteops_uid' => 'impkuid1'], '/seo_report.php');
$payload = json_decode($html, true);
test('import-keyword partial file imports valid rows only', is_array($payload) && $payload['ok'] === true && strpos($payload['message'], '失败 2 条') !== false, substr($html, 0, 200));

// --- 报表 DB 回退：文件缺失或不可解析时不应空白 ---
echo "== report DB fallback ==\n";
@unlink($settingFile);
$rows = App\Services\ReportService::sitelist();
test('ReportService sitelist falls back to DB when file missing', count($rows) >= 1, 'rows=' . count($rows));
$sitelisted = [];
foreach ($rows as $r) { if (($r['domain'] ?? '') === 'testpoker.com') { $sitelisted = $r; break; } }
test('ReportService sitelist fallback carries git_account', $sitelisted !== [] && array_key_exists('git_account', $sitelisted) && ($sitelisted['git_account'] ?? '') === 'felangel79', json_encode($sitelisted['git_account'] ?? null));
test('ReportService sitelist fallback has all 12 columns', $sitelisted !== [] && count(array_diff(App\Services\ReportService::SITELIST_COLUMNS, array_keys($sitelisted))) === 0, json_encode(array_keys($sitelisted)));
// 不可解析（列数错乱）时同样回退
file_put_contents($settingFile, "bad|row|here\n");
$rows = App\Services\ReportService::sitelist();
test('ReportService sitelist falls back on unparseable file', count($rows) >= 1, 'rows=' . count($rows));

@unlink($kwFile);
$rows = App\Services\ReportService::wordlist();
test('ReportService wordlist falls back to DB when file missing', count($rows) >= 1, 'rows=' . count($rows));
// 用只存在于测试库的 canary 证明数据确实来自 DB，而非恰好读到别的库/文件
$kwCanary = [];
foreach ($rows as $r) { if (($r['ctx_id'] ?? '') === 'LEGACYKW00001') { $kwCanary = $r; break; } }
test('ReportService wordlist fallback serves test-DB canary row', $kwCanary !== [] && $kwCanary['keyword'] === 'legacy kw', json_encode($kwCanary));
// 不依赖具体 keyword（前面的编辑用例会改写它），直接校验任一行的键完整性
$kwAny = $kwCanary !== [] ? $kwCanary : (count($rows) ? $rows[0] : []);
test('ReportService wordlist fallback carries geo and lasttask', $kwAny !== [] && array_key_exists('geo', $kwAny) && array_key_exists('lasttask', $kwAny), json_encode(array_keys($kwAny)));
test('ReportService wordlist fallback has all 7 columns', $kwAny !== [] && count(array_diff(App\Services\ReportService::WORDLIST_COLUMNS, array_keys($kwAny))) === 0, json_encode(array_keys($kwAny)));
file_put_contents($kwFile, "bad|row|here\n");
$rows = App\Services\ReportService::wordlist();
test('ReportService wordlist falls back on unparseable file', count($rows) >= 1, 'rows=' . count($rows));

// 三个视图在文件缺失时都不应返回空
$json = runRequest(['format' => 'json', 'reporttype' => 'wordlist'], [], 'GET', [], '/seo_report.php');
$payload = json_decode($json, true);
test('report wordlist json not blank when file missing', is_array($payload) && $payload['total'] >= 1, substr($json, 0, 120));
$json = runRequest(['format' => 'json', 'reporttype' => 'sitelist'], [], 'GET', [], '/seo_report.php');
$payload = json_decode($json, true);
test('report sitelist json not blank when file missing', is_array($payload) && $payload['total'] >= 1, substr($json, 0, 120));
// 恢复导出文件，避免影响后续用例
\App\Services\ExportService::export();
\App\Services\KeywordService::export();
\App\Services\TopicService::export();
$json = runRequest(['format' => 'json', 'reporttype' => 'relateword'], [], 'GET', [], '/seo_report.php');
$payload = json_decode($json, true);
test('report relateword missing file empty state', is_array($payload) && $payload['total'] === 0, substr($json, 0, 120));
file_put_contents($dataDir . '/table_relatedword.txt', "createtime|subword|status|domain|pubdir|lang|mainword\n20260801|holdem tips|enable|testpoker.com|article|en|texas holdem\n");
$json = runRequest(['format' => 'json', 'reporttype' => 'relateword'], [], 'GET', [], '/seo_report.php');
$payload = json_decode($json, true);
test('report relateword parses fixture', is_array($payload) && $payload['total'] === 1 && strpos($json, 'holdem tips') !== false, substr($json, 0, 120));
unlink($dataDir . '/table_relatedword.txt');
$html = runRequest([], [], 'GET', [], '/seo_report.php');
test('report unified header/footer', strpos($html, 'sops-sidebar') !== false && strpos($html, 'footer-sops') !== false);
$html = runRequest(['reporttype' => 'sitelist'], [], 'GET', [], '/seo_report.php');
preg_match('/var csrf = \'([^\']+)\'/', $html, $mc);
test('report sitelist page carries csrf token', isset($mc[1]) && strlen($mc[1]) === 32, isset($mc[1]) ? $mc[1] : 'no token');
$html = runRequest(['reporttype' => 'topiclist'], [], 'GET', [], '/seo_report.php');
preg_match('/var csrf = \'([^\']+)\'/', $html, $mc2);
test('report topiclist page carries csrf token', isset($mc2[1]) && strlen($mc2[1]) === 32, isset($mc2[1]) ? $mc2[1] : 'no token');
$runId = 'DELREP' . substr(md5(uniqid('', true)), 0, 8);
$html = runRequest([], validPost([
    'post_uuid' => $runId,
    'post_gitname' => 'delrep-site',
    'post_domain' => 'delrep.com',
    'post_keyword' => 'delete regression',
    'post_sitetitle' => 'DelRep',
    'post_json' => '{"content":{}}',
]), 'POST');
$delToken = substr(hash_hmac('sha256', 'csrf|delrepuid1', 'testcsrfsecret'), 0, 32);
$html = runRequest([], ['action' => 'delete-site', 'ctx_id' => $runId, 'csrf_token' => 'wrong'], 'POST', ['siteops_uid' => 'delrepuid1'], '/seo_report.php');
test('report site delete requires csrf', strpos($html, 'CSRF token invalid') !== false && count(db("SELECT * FROM siteops WHERE ctx_id = '$runId'")) === 1, substr($html, 0, 120));
$html = runRequest([], ['action' => 'delete-site', 'ctx_id' => $runId, 'csrf_token' => $delToken], 'POST', ['siteops_uid' => 'delrepuid1'], '/seo_report.php');
test('report site delete works with csrf', strpos($html, '"ok":true') !== false && count(db("SELECT * FROM siteops WHERE ctx_id = '$runId'")) === 0, substr($html, 0, 120));

echo "== topic pages ==\n";
db("INSERT INTO sitetopic (ctx_id, git_name, domain, keyword, pubdir, status, lang, geo, lasttask, json, time) VALUES ('TOPICDATEREPORT1', 'testpoker', 'testpoker.com', 'date report topic', 'article', 'enable', 'en', 'US', '20260801093000', '{}', '2026-08-01 09:30:00')");
$html = runRequest([], [], 'GET', [], '/topiclist.php');
test('topic list page renders header/footer', strpos($html, 'sops-sidebar') !== false && strpos($html, 'footer-sops') !== false);
test('topic list page uses server-side table', strpos($html, "url: 'topiclist.php'") !== false && strpos($html, 'sidePagination: \'server\'') !== false);
test('topic list unique ctx_id field (sort fix)', substr_count($html, "field: 'ctx_id'") === 1 && strpos($html, "field: '_op'") !== false);
$json = runRequest(['format' => 'json'], [], 'GET', [], '/topiclist.php');
$payload = json_decode($json, true);
test('topic list json returns total+rows', is_array($payload) && isset($payload['total']) && $payload['total'] >= 1 && isset($payload['rows'][0]['ctx_id']), substr($json, 0, 120));
test('topic list json contains exported topic', strpos($json, 'poker strategy guide') !== false);
test('topic list json rows carry ctx_id for edit', strpos($json, 'topicops.php?eid=') !== false || (is_array($payload) && isset($payload['rows'][0]['ctx_id']) && $payload['rows'][0]['ctx_id'] !== ''));
$json = runRequest(['format' => 'json', 'search' => 'poker strategy guide'], [], 'GET', [], '/topiclist.php');
$payload = json_decode($json, true);
test('topic list json search filters rows', is_array($payload) && $payload['total'] >= 1 && strpos($json, 'poker strategy guide') !== false, substr($json, 0, 120));
$json = runRequest(['format' => 'json', 'offset' => 0, 'limit' => 1], [], 'GET', [], '/topiclist.php');
$payload = json_decode($json, true);
$firstId = is_array($payload) && isset($payload['rows'][0]) ? $payload['rows'][0]['id'] : null;
test('topic list json offset/limit page 1', $firstId !== null, substr($json, 0, 120));
$json = runRequest(['format' => 'json', 'offset' => 1, 'limit' => 1], [], 'GET', [], '/topiclist.php');
$payload = json_decode($json, true);
$secondId = is_array($payload) && isset($payload['rows'][0]) ? $payload['rows'][0]['id'] : null;
test('topic list json offset/limit page 2 differs', $secondId !== null && $secondId !== $firstId, 'first=' . var_export($firstId, true) . ' second=' . var_export($secondId, true));
$json = runRequest(['format' => 'json', 'sort' => 'id', 'order' => 'asc'], [], 'GET', [], '/topiclist.php');
$payload = json_decode($json, true);
test('topic list json sort asc honored', is_array($payload) && isset($payload['rows'][0]['id']) && $payload['rows'][0]['id'] === (int)min(array_column($payload['rows'], 'id')), var_export($payload['rows'][0]['id'] ?? null, true));
$json = runRequest(['format' => 'json', 'sort' => 'id;DROP TABLE sitetopic', 'order' => 'desc'], [], 'GET', [], '/topiclist.php');
test('topic list json sort whitelist rejects injection', is_array(json_decode($json, true)) && count(db("SELECT * FROM sitetopic")) > 0);
$topicListTxt = $dataDir . '/topic_monitor_list.txt';
if (file_exists($topicListTxt)) {
    unlink($topicListTxt);
}
$html = runRequest([], [], 'GET', [], '/topictable.php');
test('topic report page renders summary cards', strpos($html, '话题总数') !== false && strpos($html, '按发布日期') !== false);
test('topic report page uses tabs', strpos($html, 'nav-tabs') !== false && strpos($html, 'href="#tab-date"') !== false && strpos($html, 'href="#tab-domain"') !== false && strpos($html, 'href="#tab-detail"') !== false);
test('topic report page uses server-side table', strpos($html, "url: 'topictable.php'") !== false);
$json = runRequest(['format' => 'json'], [], 'GET', [], '/topictable.php');
$payload = json_decode($json, true);
test('topic report json returns total+rows', is_array($payload) && isset($payload['total']) && $payload['total'] >= 1 && isset($payload['rows'][0]['id']), substr($json, 0, 120));
test('topic report json contains exported topic row', strpos($json, 'poker strategy guide') !== false);
test('topic report json rows carry ctx_id', is_array($payload) && isset($payload['rows'][0]['ctx_id']) && $payload['rows'][0]['ctx_id'] !== '');
$json = runRequest(['format' => 'json', 'view' => 'date'], [], 'GET', [], '/topictable.php');
$payload = json_decode($json, true);
test('topic report date view json', is_array($payload) && isset($payload['total']) && $payload['total'] >= 1 && isset($payload['rows'][0]['date']) && isset($payload['rows'][0]['total']), substr($json, 0, 120));
test('topic report date view has rate', is_array($payload) && isset($payload['rows'][0]['rate']) && $payload['rows'][0]['rate'] >= 0 && $payload['rows'][0]['rate'] <= 100, substr($json, 0, 120));
$json = runRequest(['format' => 'json', 'view' => 'date', 'sort' => 'rate', 'order' => 'desc'], [], 'GET', [], '/topictable.php');
$payload = json_decode($json, true);
test('topic report date view sort by rate', is_array($payload) && isset($payload['rows'][0]['rate']) && $payload['rows'][0]['rate'] === (int)max(array_column($payload['rows'], 'rate')), var_export($payload['rows'][0]['rate'] ?? null, true));
$json = runRequest(['format' => 'json', 'view' => 'date', 'search' => '20260801'], [], 'GET', [], '/topictable.php');
$payload = json_decode($json, true);
test('topic report date view search filters', is_array($payload) && isset($payload['rows'][0]['date']) && $payload['rows'][0]['date'] === '20260801', substr($json, 0, 120));
$json = runRequest(['format' => 'json', 'view' => 'date', 'sort' => 'total', 'order' => 'asc'], [], 'GET', [], '/topictable.php');
$payload = json_decode($json, true);
test('topic report date view sort honored', is_array($payload) && isset($payload['rows'][0]['total']) && $payload['rows'][0]['total'] === (int)min(array_column($payload['rows'], 'total')), var_export($payload['rows'][0]['total'] ?? null, true));
$json = runRequest(['format' => 'json', 'view' => 'domain'], [], 'GET', [], '/topictable.php');
$payload = json_decode($json, true);
test('topic report domain view json', is_array($payload) && isset($payload['total']) && $payload['total'] >= 1 && isset($payload['rows'][0]['domain']), substr($json, 0, 120));
test('topic report domain view has rate', is_array($payload) && isset($payload['rows'][0]['rate']) && $payload['rows'][0]['rate'] >= 0 && $payload['rows'][0]['rate'] <= 100, substr($json, 0, 120));
$html = runRequest([], [], 'GET', [], '/topictable.php');
test('topic report page has rate progress formatter', strpos($html, 'topicRateFormatter') !== false && strpos($html, "field: 'rate'") !== false);
$json = runRequest(['format' => 'json', 'view' => 'domain', 'search' => 'testpoker.com'], [], 'GET', [], '/topictable.php');
$payload = json_decode($json, true);
test('topic report domain view search filters', is_array($payload) && isset($payload['rows'][0]['domain']) && $payload['rows'][0]['domain'] === 'testpoker.com', substr($json, 0, 120));
$json = runRequest(['format' => 'json', 'view' => 'domain', 'sort' => 'total', 'order' => 'asc'], [], 'GET', [], '/topictable.php');
$payload = json_decode($json, true);
test('topic report domain view sort honored', is_array($payload) && isset($payload['rows'][0]['total']) && $payload['rows'][0]['total'] === (int)min(array_column($payload['rows'], 'total')), var_export($payload['rows'][0]['total'] ?? null, true));

echo "== audit log ==\n";test('audit log written', file_exists($dataDir . '/siteops_submit.log') && strpos((string)file_get_contents($dataDir . '/siteops_submit.log'), 'authok.com') !== false);

echo "== error page ==\n";
$html = runRequest([], validPost([
    'post_uuid' => 'ERRORUUID1',
    'post_gitname' => 'errdup1',
    'post_domain' => 'errdup1.com',
]), 'POST');
$html = runRequest([], validPost([
    'post_uuid' => 'ERRORUUID1',
    'post_gitname' => 'errdup2',
    'post_domain' => 'errdup2.com',
]), 'POST');
test('db failure renders error page', strpos($html, 'Internal Server Error') !== false);

echo "== RBAC ==\n";
putenv('APP_AUTH_SECRET=rbacsecret');
// Database::migrate seeds rbac tables on first connection
App\Database::reset();
$rbacTables = db("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('users','roles','permissions','user_roles','role_permissions') ORDER BY name");
test('rbac tables exist', count($rbacTables) === 5, implode(',', array_column($rbacTables, 'name')));
$seedRoles = db("SELECT name FROM roles ORDER BY id");
test('rbac seed roles', array_column($seedRoles, 'name') === ['admin', 'editor', 'viewer'], implode(',', array_column($seedRoles, 'name')));
$seedPerms = db("SELECT code FROM permissions ORDER BY id");
test('rbac seed permissions', count($seedPerms) === 11 && in_array('config.manage', array_column($seedPerms, 'code'), true), implode(',', array_column($seedPerms, 'code')));
$adminRole = db("SELECT id FROM roles WHERE name='admin'");
$adminRoleId = (int)$adminRole[0]['id'];
$adminPermCount = db("SELECT COUNT(*) AS c FROM role_permissions WHERE role_id=$adminRoleId");
test('rbac admin role has all permissions', (int)$adminPermCount[0]['c'] === 11, 'c=' . $adminPermCount[0]['c']);

// user CRUD via repository
$uid = App\Repositories\UserRepository::create([
    'username' => 'rbacted',
    'password_hash' => password_hash('pass1234', PASSWORD_DEFAULT),
    'display_name' => 'RBAC Editor',
    'status' => 'active',
]);
$editorRole = db("SELECT id FROM roles WHERE name='editor'");
$editorRoleId = (int)$editorRole[0]['id'];
App\Repositories\UserRepository::setRoles($uid, [$editorRoleId]);
$stored = App\Repositories\UserRepository::findByUsername('rbacted');
test('rbac user created with roles', $stored !== null && $stored['display_name'] === 'RBAC Editor' && App\Repositories\UserRepository::roleNames($uid) === ['editor'], var_export(App\Repositories\UserRepository::roleNames($uid), true));
$perms = App\Repositories\UserRepository::permissionsOf($uid);
test('rbac user permissions from role', in_array('site.manage', $perms, true) && !in_array('user.manage', $perms, true), implode(',', $perms));

// login as db user
$html = runRequest([], ['login_action' => 'login', 'auth_user' => 'rbacted', 'auth_password' => 'pass1234'], 'POST', ['siteops_uid' => 'rbac1']);
test('rbac login success redirects', $html === '');
$html = runRequest([], ['login_action' => 'login', 'auth_user' => 'rbacted', 'auth_password' => 'wrong'], 'POST', ['siteops_uid' => 'rbac1']);
test('rbac wrong password rejected', strpos($html, 'invalid credentials') !== false);

$expiry = time() + 3600;
$mac = substr(hash_hmac('sha256', 'auth|rbacted|' . $expiry, 'rbacsecret'), 0, 32);
$rbacCookie = 'rbacted|' . $expiry . '|' . $mac;
$html = runRequest([], [], 'GET', ['siteops_uid' => 'rbac1', 'siteops_auth' => $rbacCookie], '/users.php');
test('rbac editor blocked from user manage', strpos($html, 'forbidden: insufficient permission') !== false);
$html = runRequest([], [], 'GET', ['siteops_uid' => 'rbac1', 'siteops_auth' => $rbacCookie], '/topiclist.php');
test('rbac editor allowed topic view', strpos($html, '话题列表') !== false);
$rbacParams = runRequest(['format' => 'json'], [], 'GET', ['siteops_uid' => 'rbac1', 'siteops_auth' => $rbacCookie], '/topiclist.php');
$rbacPayload = json_decode($rbacParams, true);
test('rbac editor json endpoint allowed', is_array($rbacPayload) && isset($rbacPayload['total']), substr($rbacParams, 0, 80));
$html = runRequest([], [], 'GET', ['siteops_uid' => 'rbac1', 'siteops_auth' => $rbacCookie], '/article_new.php');
test('rbac editor allowed article manage', strpos($html, 'id="wechatpost"') !== false && strpos($html, '文章') !== false, substr($html, 0, 120));
$html = runRequest([], [], 'GET', ['siteops_uid' => 'rbac1', 'siteops_auth' => $rbacCookie], '/article_list.php');
test('rbac editor allowed article list', strpos($html, '文章列表') !== false, substr($html, 0, 120));

// menu filtering
$viewer = App\Repositories\UserRepository::findByUsername('rbacted');
$viewerPerms = App\Repositories\UserRepository::permissionsOf($uid);
$filtered = App\Config::headerModulesFor($viewerPerms);
$flat = [];
foreach ($filtered as $mod) {
    $flat[] = $mod['title'];
    foreach (isset($mod['children']) ? $mod['children'] : [] as $child) {
        $flat[] = $child['title'];
    }
}
test('rbac menu hides admin domain for editor', !in_array('系统管理', $flat, true) && in_array('站点管理', $flat, true), implode(',', $flat));

// viewer role: view-only
$vuid = App\Repositories\UserRepository::create([
    'username' => 'rbacviewer',
    'password_hash' => password_hash('pass1234', PASSWORD_DEFAULT),
    'display_name' => 'Viewer',
    'status' => 'active',
]);
$viewerRole = db("SELECT id FROM roles WHERE name='viewer'");
App\Repositories\UserRepository::setRoles($vuid, [(int)$viewerRole[0]['id']]);
$vw = App\Repositories\UserRepository::permissionsOf($vuid);
test('rbac viewer has view-only perms', in_array('site.view', $vw, true) && !in_array('site.manage', $vw, true), implode(',', $vw));
$vExpiry = time() + 3600;
$vMac = substr(hash_hmac('sha256', 'auth|rbacviewer|' . $vExpiry, 'rbacsecret'), 0, 32);
$vCookie = 'rbacviewer|' . $vExpiry . '|' . $vMac;
$html = runRequest([], [], 'GET', ['siteops_uid' => 'rbacv1', 'siteops_auth' => $vCookie], '/article_new.php');
test('rbac viewer blocked from article manage', strpos($html, 'forbidden: insufficient permission') !== false, substr($html, 0, 120));
$html = runRequest([], [], 'GET', ['siteops_uid' => 'rbacv1', 'siteops_auth' => $vCookie], '/article_list.php');
test('rbac viewer allowed article list', strpos($html, '文章列表') !== false, substr($html, 0, 120));

// user_edit page renders form
$legacyAdminExpiry = time() + 3600;
$legacyMac = substr(hash_hmac('sha256', 'auth|admin|' . $legacyAdminExpiry, 'rbacsecret'), 0, 32);
$legacyAdminCookie = 'admin|' . $legacyAdminExpiry . '|' . $legacyMac;
$html = runRequest([], [], 'GET', ['siteops_uid' => 'opencode-admin', 'siteops_auth' => $legacyAdminCookie], '/user_edit.php');
test('rbac user form renders for legacy admin', strpos($html, '新建用户') !== false && strpos($html, 'name="username"') !== false);
$html = runRequest([], [], 'GET', ['siteops_uid' => 'rbac1', 'siteops_auth' => $rbacCookie], '/user_edit.php');
test('rbac editor blocked from user form', strpos($html, 'forbidden: insufficient permission') !== false);
putenv('APP_AUTH_SECRET');

echo "== RBAC initial admin seeding ==\n";
foreach (App\Repositories\UserRepository::all('', 1, 100)['rows'] as $userRow) {
    App\Repositories\UserRepository::delete((int)$userRow['id']);
}
putenv('APP_AUTH_USER=seedadmin');
putenv('APP_AUTH_PASSWORD=seedpw');
putenv('APP_AUTH_SECRET=rbacsecret');
App\Database::reset();
$confirmCount = App\Repositories\UserRepository::count();
$initialAdmin = App\Repositories\UserRepository::findByUsername('seedadmin');
test('rbac env auth seeds initial admin user', $initialAdmin !== null, var_export($initialAdmin, true));
test('rbac seeded admin has admin role', $initialAdmin !== null && App\Repositories\UserRepository::roleNames((int)$initialAdmin['id']) === ['admin'], var_export(App\Repositories\UserRepository::roleNames((int)$initialAdmin['id']), true));
App\Database::reset();
$countAgain = App\Repositories\UserRepository::count();
test('rbac initial admin seed idempotent', $countAgain === 1, 'count=' . $countAgain);

$exp = time() + 3600;
$seedMac = substr(hash_hmac('sha256', 'auth|seedadmin|' . $exp, 'rbacsecret'), 0, 32);
$seedCookie = 'seedadmin|' . $exp . '|' . $seedMac;
$deleteCsrf = substr(hash_hmac('sha256', 'csrf|rbac2', 'testcsrfsecret'), 0, 32);
$html = runRequest([], ['action' => 'delete', 'id' => (int)$initialAdmin['id'], 'csrf_token' => $deleteCsrf], 'POST', ['siteops_uid' => 'rbac2', 'siteops_auth' => $seedCookie], '/user_edit.php');
test('rbac cannot delete current logged-in user', strpos($html, 'cannot delete the current logged-in user') !== false);

$admin2Id = App\Repositories\UserRepository::create([
    'username' => 'admin2',
    'password_hash' => password_hash('pw123', PASSWORD_DEFAULT),
    'display_name' => 'Admin Two',
    'status' => 'active',
]);
$adminRole = db("SELECT id FROM roles WHERE name='admin'");
App\Repositories\UserRepository::setRoles($admin2Id, [(int)$adminRole[0]['id']]);
$html = runRequest([], ['action' => 'delete', 'id' => $admin2Id, 'csrf_token' => $deleteCsrf], 'POST', ['siteops_uid' => 'rbac2', 'siteops_auth' => $seedCookie], '/user_edit.php');
test('rbac second admin deletable', App\Repositories\UserRepository::findById($admin2Id) === null, substr($html, 0, 80));
$legacyDelExpiry = time() + 3600;
$legacyDelMac = substr(hash_hmac('sha256', 'auth|admin|' . $legacyDelExpiry, 'rbacsecret'), 0, 32);
$html = runRequest([], ['action' => 'delete', 'id' => (int)$initialAdmin['id'], 'csrf_token' => $deleteCsrf], 'POST', ['siteops_uid' => 'rbac2', 'siteops_auth' => 'admin|' . $legacyDelExpiry . '|' . $legacyDelMac], '/user_edit.php');
test('rbac cannot delete last admin user', strpos($html, 'cannot delete the last admin user') !== false);

$delId = App\Repositories\UserRepository::create([
    'username' => 'delme',
    'password_hash' => password_hash('pw123', PASSWORD_DEFAULT),
    'display_name' => 'Doomed',
    'status' => 'active',
]);
$html = runRequest([], ['action' => 'delete', 'id' => $delId, 'csrf_token' => $deleteCsrf], 'POST', ['siteops_uid' => 'rbac2', 'siteops_auth' => $seedCookie], '/user_edit.php');
test('rbac non-admin user deletable', App\Repositories\UserRepository::findById($delId) === null, substr($html, 0, 80));
$html = runRequest([], ['action' => 'delete', 'id' => 999999, 'csrf_token' => $deleteCsrf], 'POST', ['siteops_uid' => 'rbac2', 'siteops_auth' => $seedCookie], '/user_edit.php');
test('rbac delete unknown user msg', strpos($html, 'user not found') !== false);
putenv('APP_AUTH_USER');
putenv('APP_AUTH_PASSWORD');
putenv('APP_AUTH_SECRET');
App\Support\Security::reset();

echo "== RBAC role CRUD ==\n";
putenv('APP_AUTH_SECRET=rbacsecret');
App\Support\Security::reset();
$roleCsrf = $deleteCsrf;
$newRoleId = App\Repositories\RoleRepository::create(['name' => 'auditor', 'description' => 'Auditor role']);
test('rbac role created', $newRoleId > 0 && App\Repositories\RoleRepository::findById($newRoleId) !== null, 'id=' . $newRoleId);
App\Repositories\RoleRepository::update($newRoleId, ['name' => 'auditor2', 'description' => 'Renamed']);
test('rbac role updated', App\Repositories\RoleRepository::findById($newRoleId)['name'] === 'auditor2', var_export(App\Repositories\RoleRepository::findById($newRoleId), true));
$html = runRequest([], ['action' => 'delete', 'id' => $newRoleId, 'csrf_token' => $roleCsrf], 'POST', ['siteops_uid' => 'rbac2', 'siteops_auth' => $seedCookie], '/role_edit.php');
test('rbac role deletable via ui', App\Repositories\RoleRepository::findById($newRoleId) === null, substr($html, 0, 80));
$html = runRequest([], ['action' => 'delete', 'id' => $adminRole[0]['id'], 'csrf_token' => $roleCsrf], 'POST', ['siteops_uid' => 'rbac2', 'siteops_auth' => $seedCookie], '/role_edit.php');
test('rbac built-in role undeletable', strpos($html, 'cannot delete built-in role') !== false);
$tempRoleId = App\Repositories\RoleRepository::create(['name' => 'usedrole', 'description' => '']);
$tempUserId = App\Repositories\UserRepository::create([
    'username' => 'roleholder',
    'password_hash' => password_hash('pw123', PASSWORD_DEFAULT),
    'display_name' => 'Holder',
    'status' => 'active',
]);
App\Repositories\UserRepository::setRoles($tempUserId, [$tempRoleId]);
$html = runRequest([], ['action' => 'delete', 'id' => $tempRoleId, 'csrf_token' => $roleCsrf], 'POST', ['siteops_uid' => 'rbac2', 'siteops_auth' => $seedCookie], '/role_edit.php');
test('rbac assigned role undeletable', strpos($html, 'cannot delete role assigned to users') !== false && App\Repositories\RoleRepository::findById($tempRoleId) !== null);
App\Repositories\UserRepository::delete($tempUserId);
$html = runRequest([], ['action' => 'delete', 'id' => $tempRoleId, 'csrf_token' => $roleCsrf], 'POST', ['siteops_uid' => 'rbac2', 'siteops_auth' => $seedCookie], '/role_edit.php');
test('rbac role deletable after unassign', App\Repositories\RoleRepository::findById($tempRoleId) === null, substr($html, 0, 80));

echo "== RBAC user list shows all ==\n";
App\Repositories\UserRepository::create([
    'username' => 'userone',
    'password_hash' => password_hash('pw123', PASSWORD_DEFAULT),
    'display_name' => 'User One',
    'status' => 'active',
]);
App\Repositories\UserRepository::create([
    'username' => 'usertwo',
    'password_hash' => password_hash('pw123', PASSWORD_DEFAULT),
    'display_name' => 'User Two',
    'status' => 'active',
]);
$html = runRequest([], [], 'GET', ['siteops_uid' => 'rbac2', 'siteops_auth' => $seedCookie], '/users.php');
test('rbac user list shows all users server-rendered', strpos($html, 'userone') !== false && strpos($html, 'usertwo') !== false && strpos($html, 'seedadmin') !== false, 'userone=' . (strpos($html, 'userone') !== false ? 'y' : 'n') . ' usertwo=' . (strpos($html, 'usertwo') !== false ? 'y' : 'n'));
$html = runRequest(['format' => 'json'], [], 'GET', ['siteops_uid' => 'rbac2', 'siteops_auth' => $seedCookie], '/users.php');
$usersPayload = json_decode($html, true);
test('rbac user json lists all users', is_array($usersPayload) && isset($usersPayload['total']) && $usersPayload['total'] >= 3 && count($usersPayload['rows']) >= 3, substr($html, 0, 120));
$html = runRequest([], [], 'GET', ['siteops_uid' => 'rbac2', 'siteops_auth' => $seedCookie], '/roles.php');
test('rbac role list renders delete guard', strpos($html, 'cannot') !== false || strpos($html, 'roleDelete') !== false, substr($html, 0, 120));
putenv('APP_AUTH_SECRET');
App\Support\Security::reset();

echo "== report site delete ==\n";
db("INSERT INTO siteops (ctx_id, git_name, domain, status) VALUES ('DELETECONTEXT1', 'delsite', 'delsite.com', 'enable')");
$json = runRequest([], ['action' => 'delete-site', 'ctx_id' => 'DELETECONTEXT1', 'csrf_token' => 'x'], 'POST', [], '/seo_report.php');
$payload = json_decode($json, true);
test('report site delete removes row', is_array($payload) && $payload['ok'] === true && App\Repositories\SiteRepository::findByCtxId('DELETECONTEXT1') === null, substr($json, 0, 120));
$json = runRequest([], ['action' => 'delete-site', 'ctx_id' => 'NOPE-MISSING', 'csrf_token' => 'x'], 'POST', [], '/seo_report.php');
$payload = json_decode($json, true);
test('report site delete unknown ctx rejected', is_array($payload) && $payload['ok'] === false, substr($json, 0, 120));

echo "== report word delete ==\n";
db("INSERT INTO keywordmonitorlist (ctx_id, keyword, status, git_name, pubdir, lang) VALUES ('DELETEWORD1', 'delete word test', 'enable', 'delword', 'article', 'en')");
db("INSERT INTO sitetopic (ctx_id, git_name, domain, keyword, pubdir, status, lang, geo, lasttask, json, time) VALUES ('DELETETOPIC1', 'deltopic', 'deltopic.com', 'delete topic test', 'article', 'enable', 'en', 'US', '20260801093000', '{}', '2026-08-01 09:30:00')");
$html = runRequest(['reporttype' => 'wordlist'], [], 'GET', [], '/seo_report.php');
test('report wordlist action column name', strpos($html, '"title":"操作"') !== false, strpos($html, '"title":"操作"') !== false ? '' : 'column title not 操作');
test('report wordlist delete button rendered', strpos($html, 'keywordDeleteConfirm') !== false && strpos($html, 'siteDeleteModal') !== false, substr($html, 0, 200));
$json = runRequest([], ['action' => 'delete-keyword', 'ctx_id' => 'DELETEWORD1', 'csrf_token' => 'x'], 'POST', [], '/seo_report.php');
$payload = json_decode($json, true);
test('report word delete removes row', is_array($payload) && $payload['ok'] === true && App\Repositories\KeywordRepository::byCtxId('DELETEWORD1') === null, substr($json, 0, 120));
$json = runRequest([], ['action' => 'delete-keyword', 'ctx_id' => 'NOPE-MISSING', 'csrf_token' => 'x'], 'POST', [], '/seo_report.php');
$payload = json_decode($json, true);
test('report word delete unknown ctx rejected', is_array($payload) && $payload['ok'] === false, substr($json, 0, 120));

echo "== topic list delete ==\n";
$html = runRequest([], [], 'GET', [], '/topiclist.php');
test('topic list delete button rendered', strpos($html, 'topicDeleteConfirm') !== false && strpos($html, 'topicDeleteModal') !== false, substr($html, 0, 200));
$json = runRequest([], ['action' => 'delete-topic', 'ctx_id' => 'DELETETOPIC1', 'csrf_token' => 'x'], 'POST', [], '/topiclist.php');
$payload = json_decode($json, true);
test('topic list delete removes row', is_array($payload) && isset($payload['rows'][0]['ok']) && $payload['rows'][0]['ok'] === true && App\Repositories\TopicRepository::byCtxId('DELETETOPIC1') === null, substr($json, 0, 120));
$json = runRequest([], ['action' => 'delete-topic', 'ctx_id' => 'NOPE-MISSING', 'csrf_token' => 'x'], 'POST', [], '/topiclist.php');
$payload = json_decode($json, true);
test('topic list delete unknown ctx rejected', is_array($payload) && isset($payload['rows'][0]['ok']) && $payload['rows'][0]['ok'] === false, substr($json, 0, 120));

echo "== topic list batch select / batch delete / batch edit ==\n";
$html = runRequest([], [], 'GET', [], '/topiclist.php');
test('topic list batch buttons and modals rendered', strpos($html, 'topicBatchDelBtn') !== false && strpos($html, 'topicBatchEditBtn') !== false && strpos($html, 'topicBatchDeleteModal') !== false && strpos($html, 'topicBatchEditModal') !== false, substr($html, 0, 200));
test('topic list checkbox select column rendered', strpos($html, 'checkbox: true') !== false, '');
test('topic list batch edit form offers fields', strpos($html, 'topicBatchEditForm') !== false && strpos($html, 'name="status"') !== false && strpos($html, 'name="keyword"') !== false && strpos($html, 'name="geo"') !== false && strpos($html, '— 不修改 —') !== false, '');
test('topic list batch selection wiring present', strpos($html, 'topicBatchUpdateBar') !== false && strpos($html, 'onCheckAll: topicBatchUpdateBar') !== false && strpos($html, 'action=delete-topics') !== false && strpos($html, 'action=update-topics') !== false, '');

db("INSERT INTO sitetopic (ctx_id, git_name, domain, keyword, pubdir, status, lang, geo, lasttask, json, time) VALUES ('BATCHTOPIC1', 'batchrepo', 'batch1.com', 'batch topic one', 'article', 'enable', 'en', 'US', '20260801093000', '{}', '2026-08-01 09:30:00')");
db("INSERT INTO sitetopic (ctx_id, git_name, domain, keyword, pubdir, status, lang, geo, lasttask, json, time) VALUES ('BATCHTOPIC2', 'batchrepo', 'batch2.com', 'batch topic two', 'article', 'enable', 'ja', 'JP', '20260802093000', '{}', '2026-08-02 09:30:00')");
db("INSERT INTO sitetopic (ctx_id, git_name, domain, keyword, pubdir, status, lang, geo, lasttask, json, time) VALUES ('BATCHTOPIC3', 'batchrepo', 'batch3.com', 'batch topic three', 'article', 'enable', 'en', 'US', '20260803093000', '{}', '2026-08-03 09:30:00')");

$json = runRequest([], ['action' => 'update-topics', 'ctx_ids' => 'BATCHTOPIC1,BATCHTOPIC2', 'status' => 'aidone', 'lang' => 'zh', 'csrf_token' => 'x'], 'POST', [], '/topiclist.php');
$payload = json_decode($json, true);
test('topic batch update ok with updated count', is_array($payload) && $payload['rows'][0]['ok'] === true && $payload['rows'][0]['updated'] === 2, $json);
$batchRow1 = App\Repositories\TopicRepository::byCtxId('BATCHTOPIC1');
$batchRow2 = App\Repositories\TopicRepository::byCtxId('BATCHTOPIC2');
test('topic batch update persisted to all selected', $batchRow1 !== null && $batchRow1['status'] === 'aidone' && $batchRow1['lang'] === 'zh' && $batchRow2 !== null && $batchRow2['status'] === 'aidone' && $batchRow2['lang'] === 'zh', json_encode([$batchRow1['status'] ?? null, $batchRow2['status'] ?? null]));
$batchJson = json_decode((string)$batchRow1['json'], true);
test('topic batch update recomputes json column', is_array($batchJson) && ($batchJson['status'] ?? '') === 'aidone' && ($batchJson['lang'] ?? '') === 'zh', substr((string)$batchRow1['json'], 0, 160));

$json = runRequest([], ['action' => 'update-topics', 'ctx_ids' => 'BATCHTOPIC3', 'keyword' => 'bad|pipe', 'csrf_token' => 'x'], 'POST', [], '/topiclist.php');
$batchRow3 = App\Repositories\TopicRepository::byCtxId('BATCHTOPIC3');
test('topic batch update strips pipe from values', $batchRow3 !== null && $batchRow3['keyword'] === 'badpipe', json_encode($batchRow3['keyword'] ?? null));

$json = runRequest([], ['action' => 'update-topics', 'ctx_ids' => 'BATCHTOPIC1', 'csrf_token' => 'x'], 'POST', [], '/topiclist.php');
$payload = json_decode($json, true);
test('topic batch update rejects all-empty fields', is_array($payload) && $payload['rows'][0]['ok'] === false && strpos($payload['rows'][0]['message'], '没有可修改的字段') !== false, $json);

$json = runRequest([], ['action' => 'update-topics', 'ctx_ids' => '', 'status' => 'enable', 'csrf_token' => 'x'], 'POST', [], '/topiclist.php');
$payload = json_decode($json, true);
test('topic batch update rejects empty selection', is_array($payload) && $payload['rows'][0]['ok'] === false && $payload['rows'][0]['message'] === '未选择话题', $json);

$json = runRequest([], ['action' => 'update-topics', 'ctx_ids' => 'GHOST-TOPIC-1', 'status' => 'enable', 'csrf_token' => 'x'], 'POST', [], '/topiclist.php');
$payload = json_decode($json, true);
test('topic batch update counts missing ctx', is_array($payload) && $payload['rows'][0]['missing'] === 1 && $payload['rows'][0]['updated'] === 0 && strpos($payload['rows'][0]['message'], '不存在') !== false, $json);

$json = runRequest([], ['action' => 'delete-topics', 'ctx_ids' => 'BATCHTOPIC1,BATCHTOPIC2,GHOST-TOPIC-2', 'csrf_token' => 'x'], 'POST', [], '/topiclist.php');
$payload = json_decode($json, true);
test('topic batch delete ok with deleted and missing counts', is_array($payload) && $payload['rows'][0]['ok'] === true && $payload['rows'][0]['deleted'] === 2 && $payload['rows'][0]['missing'] === 1, $json);
test('topic batch delete removed selected rows', App\Repositories\TopicRepository::byCtxId('BATCHTOPIC1') === null && App\Repositories\TopicRepository::byCtxId('BATCHTOPIC2') === null && App\Repositories\TopicRepository::byCtxId('BATCHTOPIC3') !== null, '');

$json = runRequest([], ['action' => 'delete-topics', 'ctx_ids' => '', 'csrf_token' => 'x'], 'POST', [], '/topiclist.php');
$payload = json_decode($json, true);
test('topic batch delete rejects empty selection', is_array($payload) && $payload['rows'][0]['ok'] === false && $payload['rows'][0]['message'] === '未选择话题', $json);

$json = runRequest([], ['action' => 'delete-topics', 'ctx_ids' => 'GHOST-TOPIC-3,GHOST-TOPIC-4', 'csrf_token' => 'x'], 'POST', [], '/topiclist.php');
$payload = json_decode($json, true);
test('topic batch delete all-missing still ok with zero deleted', is_array($payload) && $payload['rows'][0]['ok'] === true && $payload['rows'][0]['deleted'] === 0 && strpos($payload['rows'][0]['message'], '已删除 0 条') !== false, $json);

$json = runRequest([], ['action' => 'update-topics', 'ctx_ids' => array('BATCHTOPIC3'), 'status' => 'enable', 'csrf_token' => 'x'], 'POST', [], '/topiclist.php');
$payload = json_decode($json, true);
test('topic batch update accepts ctx_ids array form', is_array($payload) && $payload['rows'][0]['ok'] === true && $payload['rows'][0]['updated'] === 1, $json);

db("DELETE FROM sitetopic WHERE ctx_id = 'BATCHTOPIC3'");

echo "== config management ==\n";
$cfgRows = db("SELECT config_key FROM app_configs ORDER BY config_key");
$cfgKeys = array_column($cfgRows, 'config_key');
test('app_configs seeded from file', count($cfgRows) === 8 && in_array('languages', $cfgKeys, true) && in_array('sitetype', $cfgKeys, true), implode(',', $cfgKeys));
$fileCfg = include APP_PATH . '/global_config.php';
test('config file dictionary untouched', is_array($fileCfg['languages']) && count($fileCfg['languages']) === 22, 'count=' . count($fileCfg['languages']));
App\Config::reset();
$merged = App\Config::all();
test('config dict merged from db equals file initially', is_array($merged['languages']) && $merged['languages'] === $fileCfg['languages'], '');
$html = runRequest([], [], 'GET', [], '/config_list.php');
test('config list page renders', strpos($html, '配置管理') !== false && strpos($html, 'config_edit.php?key=languages') !== false, substr($html, 0, 200));
$html = runRequest(['key' => 'languages'], [], 'GET', [], '/config_edit.php');
test('config edit page renders json', strpos($html, 'en-US') !== false && strpos($html, 'config_value') !== false, substr($html, 0, 200));
$html = runRequest(['key' => 'nope'], [], 'GET', [], '/config_edit.php');
test('config edit unknown key rejected', strpos($html, 'unknown config key') !== false, substr($html, 0, 200));
$newLanguages = $fileCfg['languages'];
$newLanguages['en-GB'] = 'en';
$html = runRequest([], ['key' => 'languages', 'config_value' => json_encode($newLanguages, JSON_UNESCAPED_UNICODE), 'csrf_token' => 'x'], 'POST', [], '/config_edit.php');
test('config save persists', strpos($html, 'saved') !== false && strpos($html, 'config_edit.php?key=languages') !== false, substr($html, 0, 200));
$saved = db("SELECT config_value FROM app_configs WHERE config_key='languages'");
$savedJson = json_decode($saved[0]['config_value'], true);
test('config save stores new entry', is_array($savedJson) && isset($savedJson['en-GB']) && count($savedJson) === 23, substr($saved[0]['config_value'], 0, 80));
App\Config::reset();
$merged = App\Config::all();
test('config db overrides file', is_array($merged['languages']) && isset($merged['languages']['en-GB']) && count($merged['languages']) === 23, 'count=' . (is_array($merged['languages']) ? count($merged['languages']) : 'n/a'));
$html = runRequest([], ['key' => 'languages', 'config_value' => 'not json', 'csrf_token' => 'x'], 'POST', [], '/config_edit.php');
test('config save invalid json rejected', strpos($html, 'invalid JSON') !== false, substr($html, 0, 200));
$html = runRequest([], ['key' => 'gitaccount', 'config_value' => '{}', 'csrf_token' => 'x'], 'POST', [], '/config_edit.php');
test('config save non-dictionary key rejected', strpos($html, 'unknown config key') !== false, substr($html, 0, 200));
$html = runRequest(['key' => 'languages'], [], 'GET', [], '/config_edit.php');
test('config edit shows updated_by', strpos($html, 'by ') !== false, '');

echo "== display timezone (system settings dropdown) ==\n";
$html = runRequest([], [], 'GET', [], '/config_list.php');
test('config list renders timezone dropdown default beijing', strpos($html, 'id="displayTimezone"') !== false && strpos($html, 'save-display-tz') !== false && preg_match('/value="Asia\/Shanghai"\s+selected/', $html) === 1, substr($html, 0, 300));
test('default display timezone is Asia/Shanghai', App\Config::displayTimezone() === 'Asia/Shanghai', App\Config::displayTimezone());
test('layout_head injects sopsTimeZone default', strpos($html, 'window.sopsTimeZone = "Asia/Shanghai"') !== false, substr($html, strpos($html, 'sopsTimeZone') ?: 0, 80));
$cfgTzHeader = (string)file_get_contents($root . '/views/header.php');
test('sidebar clock renders configured timezone', strpos($cfgTzHeader, 'Config::displayTimezone()') !== false && strpos($cfgTzHeader, 'DateTimeZone') !== false, '');
$cfgTzJs = (string)file_get_contents($root . '/js/sops-time.js');
test('sops-time js honors configured timezone with fallback', strpos($cfgTzJs, 'timeZone: tz') !== false && strpos($cfgTzJs, 'window.sopsSetTimeZone') !== false && strpos($cfgTzJs, 'sopsIsValidTimeZone') !== false, '');

$html = runRequest([], ['action' => 'save-display-tz', 'display_timezone' => 'America/New_York', 'csrf_token' => 'x'], 'POST', [], '/config_list.php');
test('timezone save applies immediately', App\Config::displayTimezone() === 'America/New_York' && preg_match('/value="America\/New_York"\s+selected/', $html) === 1, App\Config::displayTimezone());
test('timezone save reflected in page injection', strpos($html, 'window.sopsTimeZone = "America/New_York"') !== false && strpos($html, '显示时区已保存') !== false, '');

$html = runRequest([], ['action' => 'save-display-tz', 'display_timezone' => 'Mars/Olympus', 'csrf_token' => 'x'], 'POST', [], '/config_list.php');
test('invalid timezone rejected keeps current', App\Config::displayTimezone() === 'America/New_York' && strpos($html, '无效的时区') !== false, App\Config::displayTimezone());

runRequest([], ['action' => 'save-display-tz', 'display_timezone' => 'Asia/Shanghai', 'csrf_token' => 'x'], 'POST', [], '/config_list.php');
test('timezone restored to default for later tests', App\Config::displayTimezone() === 'Asia/Shanghai', App\Config::displayTimezone());
test('scalar display_timezone merged into config dict', App\Config::get('display_timezone') === 'Asia/Shanghai', '');
test('timezone card shows current time preview', preg_match('/当前\s*\d{4}-\d{2}-\d{2} \d{2}:\d{2}/u', (string)runRequest([], [], 'GET', [], '/config_list.php')) === 1, '');

echo "== config import from file ==\n";
$cfgAuthExpiry = time() + 3600;
$cfgAuthMac = substr(hash_hmac('sha256', 'auth|admin|' . $cfgAuthExpiry, App\Config::authSecret()), 0, 32);
$cfgAuthCookie = 'admin|' . $cfgAuthExpiry . '|' . $cfgAuthMac;
$html = runRequest([], [], 'GET', ['siteops_uid' => 'cfgimport', 'siteops_auth' => $cfgAuthCookie], '/config_list.php');
test('config list renders import button', strpos($html, 'config_import_form') !== false && strpos($html, 'global_config.php') !== false, substr($html, 0, 200));
$html = runRequest([], ['csrf_token' => ''], 'POST', ['siteops_uid' => 'cfgimport', 'siteops_auth' => $cfgAuthCookie], '/config_list.php');
test('config import without csrf rejected', strpos($html, 'CSRF token invalid') !== false, substr($html, 0, 200));
$_COOKIE['siteops_uid'] = 'cfgimport';
$importCsrf = App\Support\Security::csrfToken();
$html = runRequest([], ['csrf_token' => $importCsrf], 'POST', ['siteops_uid' => 'cfgimport', 'siteops_auth' => $cfgAuthCookie], '/config_list.php');
test('config import from file succeeds', strpos($html, 'imported 8 items') !== false, substr($html, 0, 200));
$imported = db("SELECT config_key, config_value FROM app_configs");
$importedMap = [];
foreach ($imported as $row) {
    $importedMap[$row['config_key']] = json_decode($row['config_value'], true);
}
$fileCfg = include APP_PATH . '/global_config.php';
test('config import restores file languages', isset($importedMap['languages']) && $importedMap['languages'] === $fileCfg['languages'] && !isset($importedMap['languages']['en-GB']), json_encode($importedMap['languages'] ?? null));
test('config import syncs all dictionary keys', count($importedMap) === 9 && isset($importedMap['sitetype']) && $importedMap['sitetype'] === $fileCfg['sitetype'] && $importedMap['display_timezone'] === 'Asia/Shanghai', implode(',', array_keys($importedMap)));
App\Config::reset();
$merged = App\Config::all();
test('config import takes effect in runtime config', is_array($merged['languages']) && $merged['languages'] === $fileCfg['languages'], '');

echo "== git server ip whitelist ==\n";
$_SERVER['REMOTE_ADDR'] = '8.222.246.123';
$html = runRequest([], [], 'GET', ['siteops_uid' => 'whitelisttest'], '/siteops.php');
test('whitelist ip bypasses login on site page', strpos($html, '站点录入') !== false && strpos($html, 'forbidden') === false, substr($html, 0, 150));
$html = runRequest([], [], 'GET', ['siteops_uid' => 'whitelisttest'], '/seo_report.php');
test('whitelist ip bypasses login on report page', strpos($html, 'SEO 报告') !== false && strpos($html, 'forbidden') === false, substr($html, 0, 150));
$html = runRequest([], [], 'GET', ['siteops_uid' => 'whitelisttest'], '/topiclist.php');
test('whitelist ip bypasses login on topic list page', strpos($html, '话题列表') !== false && strpos($html, 'forbidden') === false, substr($html, 0, 150));
$html = runRequest([], [], 'GET', ['siteops_uid' => 'whitelisttest'], '/keywordops.php');
test('whitelist ip bypasses login on keyword page', strpos($html, '关键词') !== false && strpos($html, 'forbidden') === false, substr($html, 0, 150));
$html = runRequest([], [], 'GET', ['siteops_uid' => 'whitelisttest'], '/users.php');
test('whitelist ip cannot access user management', strpos($html, 'forbidden') !== false, substr($html, 0, 150));
$html = runRequest([], [], 'GET', ['siteops_uid' => 'whitelisttest'], '/roles.php');
test('whitelist ip cannot access roles management', strpos($html, 'forbidden') !== false, substr($html, 0, 150));
$html = runRequest([], [], 'GET', ['siteops_uid' => 'whitelisttest'], '/config_list.php');
test('whitelist ip cannot access config management', strpos($html, 'forbidden') !== false, substr($html, 0, 150));
$whitelistCsrf = substr(hash_hmac('sha256', 'csrf|whitelisttest', App\Config::csrfSecret()), 0, 32);
$html = runRequest([], ['action' => 'delete-site', 'ctx_id' => 'NOPE-MISSING', 'csrf_token' => $whitelistCsrf], 'POST', ['siteops_uid' => 'whitelisttest'], '/seo_report.php');
$payload = json_decode($html, true);
test('whitelist ip can post business actions', is_array($payload) && isset($payload['ok']), substr($html, 0, 120));
unset($_SERVER['REMOTE_ADDR']);
$html = runRequest([], [], 'GET', ['siteops_uid' => 'whitelisttest'], '/siteops.php');
test('non-whitelist ip requires auth on site page', strpos($html, '登录') !== false, substr($html, 0, 150));

echo "== topic query/task from database ==\n";
$json = runRequest(['t' => 'all'], [], 'GET', [], '/api/topic_query.php');
$payload = json_decode($json, true);
test('topicquery all returns rows with id+json', is_array($payload) && count($payload) >= 1 && isset($payload[0]['id']) && isset($payload[0]['keyword']), substr($json, 0, 150));
$found = false;
foreach ($payload as $item) {
    if (isset($item['keyword']) && $item['keyword'] === 'poker strategy guide') {
        $found = true;
    }
}
test('topicquery all contains fixture keyword', $found, substr($json, 0, 150));
$json = runRequest(['t' => 'poker strategy guide'], [], 'GET', [], '/api/topic_query.php');
$payload = json_decode($json, true);
test('topicquery keyword filters rows', is_array($payload) && count($payload) === 1 && $payload[0]['keyword'] === 'poker strategy guide', substr($json, 0, 150));
$out = runRequest(['t' => 'no-such-keyword-xyz'], [], 'GET', [], '/api/topic_query.php');
test('topicquery unknown keyword returns empty array', trim($out) === '[]', var_export($out, true));
db("INSERT INTO sitetopic (ctx_id, git_name, domain, keyword, pubdir, status, lang, geo, lasttask, json, time) VALUES ('TASKDONE1', 'done-site', 'done.com', 'done topic', 'article', 'enable', 'en', 'US', '" . date('Ymd') . "', '{\"git_name\":\"done-site\",\"keyword\":\"done topic\",\"status\":\"enable\",\"lasttask\":\"" . date('Ymd') . "\"}', '2026-08-01 09:30:00')");
db("INSERT INTO sitetopic (ctx_id, git_name, domain, keyword, pubdir, status, lang, geo, lasttask, json, time) VALUES ('WRITEBACK1', 'wb-site', 'wb.com', 'writeback only', 'article', 'enable', 'en', 'US', '', '{\"git_name\":\"wb-site\",\"keyword\":\"writeback only\",\"status\":\"enable\",\"lang\":\"en\",\"geo\":\"US\",\"pubdir\":\"article\",\"domain\":\"wb.com\"}', '2026-08-01 09:30:00')");
$out = runRequest(['t' => 'writeback only'], [], 'GET', [], '/api/topic_task.php');
$line = trim($out);
$wparts = explode('|', $line);
test('topictask writeback outputs row', count($wparts) === 8 && $wparts[1] === 'writeback only', $line);
$wbrow = db("SELECT * FROM sitetopic WHERE ctx_id='WRITEBACK1'");
$wbjson = is_array($wbrow) && isset($wbrow[0]['json']) ? json_decode($wbrow[0]['json'], true) : null;
test('topictask writeback sets json lasttask today', is_array($wbjson) && isset($wbjson['lasttask']) && $wbjson['lasttask'] === date('Ymd'), json_encode($wbjson));
test('topictask writeback sets json status enable', is_array($wbjson) && $wbjson['status'] === 'enable', json_encode($wbjson));
test('topictask writeback updates status column', is_array($wbrow) && isset($wbrow[0]['status']) && $wbrow[0]['status'] === 'enable', json_encode($wbrow));
test('topictask writeback updates lasttask column', is_array($wbrow) && isset($wbrow[0]['lasttask']) && $wbrow[0]['lasttask'] === date('Ymd'), json_encode($wbrow));
$out = runRequest(['t' => 'done topic'], [], 'GET', [], '/api/topic_task.php');
test('topictask skips lasttask today rows', trim($out) === '', var_export($out, true));
$out = runRequest(['t' => 'all'], [], 'GET', [], '/api/topic_task.php');
$line = trim($out);
$parts = explode('|', $line);
$jsondata = json_decode(end($parts), true);
test('topictask all outputs single file-format line', is_array($jsondata) && count($parts) === 8 && !empty($parts[0]), $line);
test('topictask selected row is enable', count($parts) === 8 && $parts[2] === 'enable', $line);
$dbrow = db("SELECT * FROM sitetopic WHERE ctx_id='" . $parts[0] . "'");
test('topictask row exists in database', count($dbrow) === 1, 'ctx=' . $parts[0]);
db("UPDATE sitetopic SET json = '{\"git_name\":\"testpoker\",\"domain\":\"testpoker.com\",\"keyword\":\"poker strategy guide\",\"pubdir\":\"article\",\"status\":\"enable\",\"lang\":\"en\",\"geo\":\"US\"}' WHERE ctx_id = 'TOPICCTX001'");
$out = runRequest(['t' => 'poker strategy guide'], [], 'GET', [], '/api/topic_task.php');
$line = trim($out);
$parts = explode('|', $line);
test('topictask keyword returns matching row', count($parts) === 8 && $parts[1] === 'poker strategy guide', $line);
db("INSERT INTO sitetopic (ctx_id, git_name, domain, keyword, pubdir, status, lang, geo, json, time) VALUES ('BUGST1', 'bg', 'bg.com', 'bugstatus1', 'article', 'enable', 'en', 'US', '{\"keyword\":\"bugstatus1\",\"git_name\":\"bg\",\"lang\":\"en\",\"geo\":\"US\"}', '2026-08-01 09:30:00')");
db("INSERT INTO sitetopic (ctx_id, git_name, domain, keyword, pubdir, status, lang, geo, json, time) VALUES ('BUGPU1', 'bp', 'bp.com', 'bugpostuuid', 'article', 'enable', 'en', 'US', '{\"keyword\":\"bugpostuuid\",\"git_name\":\"bp\",\"lang\":\"en\",\"geo\":\"US\",\"post_uuid\":\"OTHER1\"}', '2026-08-01 09:30:00')");
$out = runRequest(['t' => 'bugstatus1'], [], 'GET', [], '/api/topic_task.php');
$brow = db("SELECT * FROM sitetopic WHERE ctx_id='BUGST1'");
test('topictask writeback keeps status column when json lacks it', is_array($brow) && count($brow) === 1 && $brow[0]['status'] === 'enable', json_encode($brow));
$out = runRequest(['t' => 'bugpostuuid'], [], 'GET', [], '/api/topic_task.php');
$brow = db("SELECT * FROM sitetopic WHERE ctx_id='BUGPU1'");
test('topictask writeback updates row by ctx_id not json post_uuid', is_array($brow) && count($brow) === 1 && $brow[0]['lasttask'] === date('Ymd'), json_encode($brow));

echo "== keyword query/task from database ==\n";
db("INSERT INTO keywordmonitorlist (ctx_id, git_name, keyword, pubdir, status, lang, geo, lasttask, json, time) VALUES ('KWQUERY1', 'kwq-site', 'kw query fixture', 'article', 'enable', 'en', 'US', '', '{\"git_name\":\"kwq-site\",\"keyword\":\"kw query fixture\",\"status\":\"enable\",\"lang\":\"en\",\"geo\":\"US\",\"pubdir\":\"article\"}', '2026-08-01 09:30:00')");
db("INSERT INTO keywordmonitorlist (ctx_id, git_name, keyword, pubdir, status, lang, geo, lasttask, json, time) VALUES ('KWWRITEBACK1', 'kwwb-site', 'kw writeback only', 'article', 'enable', 'en', 'US', '', '{\"git_name\":\"kwwb-site\",\"keyword\":\"kw writeback only\",\"status\":\"enable\",\"lang\":\"en\",\"geo\":\"US\",\"pubdir\":\"article\"}', '2026-08-01 09:30:00')");
$json = runRequest(['t' => 'all'], [], 'GET', [], '/keywordquery.php');
$payload = json_decode($json, true);
test('keywordquery all returns rows with id+json', is_array($payload) && count($payload) >= 1 && isset($payload[0]['id']) && isset($payload[0]['keyword']), substr($json, 0, 150));
$kfound = false;
foreach ($payload as $item) {
    if (isset($item['keyword']) && $item['keyword'] === 'kw query fixture') {
        $kfound = true;
    }
}
test('keywordquery all contains fixture keyword', $kfound, substr($json, 0, 150));
$json = runRequest(['t' => 'kw query fixture'], [], 'GET', [], '/keywordquery.php');
$payload = json_decode($json, true);
test('keywordquery keyword filters rows', is_array($payload) && count($payload) === 1 && $payload[0]['keyword'] === 'kw query fixture', substr($json, 0, 150));
$out = runRequest(['t' => 'no-such-keyword-xyz'], [], 'GET', [], '/keywordquery.php');
test('keywordquery unknown keyword returns empty array', trim($out) === '[]', var_export($out, true));
db("UPDATE keywordmonitorlist SET json = json_set(CASE WHEN json_valid(json) THEN json ELSE '{}' END, '$.lasttask', '" . date('Ymd') . "') WHERE ctx_id != 'KWWRITEBACK1'");
$out = runRequest(['t' => 'all'], [], 'GET', [], '/keywordtask.php');
$line = trim($out);
$kparts = explode('|', $line);
test('keywordtask all outputs single file-format line', count($kparts) === 7 && $kparts[0] === 'KWWRITEBACK1', $line);
$kwrow = db("SELECT * FROM keywordmonitorlist WHERE ctx_id='KWWRITEBACK1'");
$kwjson = is_array($kwrow) && isset($kwrow[0]['json']) ? json_decode($kwrow[0]['json'], true) : null;
test('keywordtask writeback marks json lasttask today', is_array($kwjson) && isset($kwjson['lasttask']) && $kwjson['lasttask'] === date('Ymd'), json_encode($kwjson));
test('keywordtask writeback keeps json status enable', is_array($kwjson) && $kwjson['status'] === 'enable', json_encode($kwjson));
test('keywordtask writeback updates lasttask column', is_array($kwrow) && isset($kwrow[0]['lasttask']) && $kwrow[0]['lasttask'] === date('Ymd'), json_encode($kwrow));
$out = runRequest(['t' => 'all'], [], 'GET', [], '/keywordtask.php');
test('keywordtask skips lasttask today rows', trim($out) === '', var_export($out, true));
db("INSERT INTO keywordmonitorlist (ctx_id, git_name, keyword, pubdir, status, lang, geo, json, time) VALUES ('KWBUGST1', 'kbg', 'kwbugstatus1', 'article', 'disable', 'en', 'US', '{\"keyword\":\"kwbugstatus1\",\"git_name\":\"kbg\",\"lang\":\"en\",\"geo\":\"US\"}', '2026-08-01 09:30:00')");
$out = runRequest(['t' => 'all'], [], 'GET', [], '/keywordtask.php');
$kparts = explode('|', trim($out));
test('keywordtask picks dirty row deterministically', $kparts[0] === 'KWBUGST1', trim($out));
$kbrow = db("SELECT * FROM keywordmonitorlist WHERE ctx_id='KWBUGST1'");
test('keywordtask writeback keeps status column when json lacks it', is_array($kbrow) && count($kbrow) === 1 && $kbrow[0]['status'] === 'disable', json_encode($kbrow));
// 回归：keywordtask 此前只有 "all" 分支，t=<keyword> 时 $rows 未定义导致恒空输出
db("INSERT INTO keywordmonitorlist (ctx_id, git_name, keyword, pubdir, status, lang, geo, lasttask, json, time) VALUES ('KWBRANCH1', 'kwb', 'kwbranch fixture', 'article', 'enable', 'en', 'US', '', '{\"git_name\":\"kwb\",\"keyword\":\"kwbranch fixture\",\"status\":\"enable\",\"lang\":\"en\",\"geo\":\"US\",\"pubdir\":\"article\"}', '2026-08-01 09:30:00')");
$out = runRequest(['t' => 'kwbranch fixture'], [], 'GET', [], '/keywordtask.php');
$kparts = explode('|', trim($out));
test('keywordtask keyword branch returns matching row', count($kparts) === 7 && $kparts[0] === 'KWBRANCH1', trim($out));
$out = runRequest(['t' => 'no-such-keyword-branch'], [], 'GET', [], '/keywordtask.php');
test('keywordtask unknown keyword empty output', trim($out) === '', var_export($out, true));
$out = runRequest([], [], 'GET', [], '/keywordtask.php');
test('keywordtask missing t returns 400', strpos($out, 'FAIL') !== false, var_export($out, true));

echo "== API endpoints for UiVision ==\n";
putenv('APP_API_CSRF_TOKENS=uivision-key-1');
App\Config::reset();
App\Database::reset();

function runApiEndpoint(string $file): string
{
    ob_start();
    include $file;
    $out = ob_get_clean();
    return $out;
}

// --- api/topic_update.php ---
resetRequest();
$_POST = ['post_keyword' => '', 'post_gitname' => '', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ORIGIN'] = 'https://uivision.example.com';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topic_update.php');
test('topic_update missing keyword returns 400', strpos($out, 'FAIL') !== false && strpos($out, 'forbidden') === false, $out);

resetRequest();
$_POST = [
    'post_keyword' => 'api topic kw',
    'post_gitname' => 'apitopic',
    'post_domain' => 'apitopic.com',
    'post_lang' => 'en',
    'post_geo' => 'US',
    'post_pubdir' => 'article',
    'post_status' => 'aidone',
    'post_lasttask' => date('Ymd'),
    'post_bulkkeyword' => 'disable',
    'csrf_token' => 'uivision-key-1',
];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ORIGIN'] = 'https://uivision.example.com';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topic_update.php');
test('topic_update inserts via API', strpos($out, 'OK:') !== false, $out);
$rows = db("SELECT * FROM sitetopic WHERE keyword = 'api topic kw' AND git_name = 'apitopic'");
test('topic_update wrote row to sitetopic', count($rows) === 1, json_encode($rows));

resetRequest();
$_POST = [
    'post_keyword' => 'api topic kw',
    'post_gitname' => 'apitopic',
    'post_domain' => 'apitopic.com',
    'post_lang' => 'ja',
    'post_geo' => 'JP',
    'post_pubdir' => 'article',
    'post_status' => 'done',
    'post_lasttask' => date('Ymd'),
    'post_bulkkeyword' => 'disable',
    'csrf_token' => 'uivision-key-1',
];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ORIGIN'] = 'https://uivision.example.com';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topic_update.php');
test('topic_update updates existing via API', strpos($out, 'OK:') !== false, $out);
$rows = db("SELECT * FROM sitetopic WHERE keyword = 'api topic kw' AND git_name = 'apitopic'");
test('topic_update updated lang to ja', count($rows) === 1 && $rows[0]['lang'] === 'ja', json_encode($rows));

resetRequest();
$_GET = ['csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topic_update.php');
test('topic_update rejects GET', strpos($out, 'FAIL') !== false, $out);

resetRequest();
$_POST = [
    'post_keyword' => 'api topic kw2',
    'post_gitname' => 'apitopic',
    'post_domain' => 'apitopic.com',
    'post_bulkkeyword' => 'disable',
    'csrf_token' => 'wrong-token',
];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ORIGIN'] = 'https://uivision.example.com';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topic_update.php');
test('topic_update rejects bad API token', strpos($out, 'forbidden') !== false, $out);

// --- api/keyword_update.php ---
resetRequest();
$_POST = ['post_keyword' => '', 'post_gitname' => '', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ORIGIN'] = 'https://uivision.example.com';
$out = runApiEndpoint(dirname(__DIR__) . '/api/keyword_update.php');
test('keyword_update missing keyword returns 400', strpos($out, 'FAIL') !== false && strpos($out, 'forbidden') === false, $out);

resetRequest();
$_POST = [
    'post_keyword' => 'api kw test',
    'post_gitname' => 'apikw',
    'post_lang' => 'en',
    'post_geo' => 'US',
    'post_pubdir' => 'article',
    'post_status' => 'enable',
    'post_bulkkeyword' => 'disable',
    'csrf_token' => 'uivision-key-1',
];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ORIGIN'] = 'https://uivision.example.com';
$out = runApiEndpoint(dirname(__DIR__) . '/api/keyword_update.php');
test('keyword_update inserts via API', strpos($out, 'OK:') !== false, $out);
$rows = db("SELECT * FROM keywordmonitorlist WHERE keyword = 'api kw test' AND git_name = 'apikw'");
test('keyword_update wrote row to keywordmonitorlist', count($rows) === 1, json_encode($rows));

resetRequest();
$_POST = [
    'post_keyword' => 'api kw test',
    'post_gitname' => 'apikw',
    'post_lang' => 'ja',
    'post_geo' => 'JP',
    'post_pubdir' => 'article',
    'post_status' => 'disable',
    'post_bulkkeyword' => 'disable',
    'csrf_token' => 'uivision-key-1',
];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ORIGIN'] = 'https://uivision.example.com';
$out = runApiEndpoint(dirname(__DIR__) . '/api/keyword_update.php');
test('keyword_update updates existing via API', strpos($out, 'OK:') !== false, $out);
$rows = db("SELECT * FROM keywordmonitorlist WHERE keyword = 'api kw test' AND git_name = 'apikw'");
test('keyword_update updated status to disable', count($rows) === 1 && $rows[0]['status'] === 'disable', json_encode($rows));

resetRequest();
$_GET = ['csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$out = runApiEndpoint(dirname(__DIR__) . '/api/keyword_update.php');
test('keyword_update rejects GET', strpos($out, 'FAIL') !== false, $out);

resetRequest();
$_POST = [
    'post_keyword' => 'api kw bad',
    'post_gitname' => 'apikw',
    'csrf_token' => 'wrong-token',
];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ORIGIN'] = 'https://uivision.example.com';
$out = runApiEndpoint(dirname(__DIR__) . '/api/keyword_update.php');
test('keyword_update rejects bad API token', strpos($out, 'forbidden') !== false, $out);

// --- api/site_update.php ---
resetRequest();
$_POST = ['post_domain' => '', 'post_gitname' => '', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ORIGIN'] = 'https://uivision.example.com';
$out = runApiEndpoint(dirname(__DIR__) . '/api/site_update.php');
test('site_update missing domain returns 400', strpos($out, 'FAIL') !== false && strpos($out, 'forbidden') === false, $out);

resetRequest();
$_POST = [
    'post_uuid' => 'APISITEUUID1',
    'post_gitname' => 'apitest',
    'post_domain' => 'apitest.com',
    'post_sitetitle' => 'API Test Site',
    'post_description' => 'desc',
    'post_sitelogo' => '',
    'post_sitedeploy' => 'cloudflare',
    'post_sitehostip' => '',
    'post_sns_id' => 'apitest',
    'post_topnavmenus' => 'home,about',
    'post_keyword' => 'test',
    'post_lang' => 'en',
    'post_sitetype' => 'cta',
    'post_themetype' => 'poker',
    'post_status' => 'done',
    'csrf_token' => 'uivision-key-1',
];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ORIGIN'] = 'https://uivision.example.com';
$out = runApiEndpoint(dirname(__DIR__) . '/api/site_update.php');
test('site_update inserts via API', strpos($out, 'OK:') !== false, $out);
$rows = db("SELECT * FROM siteops WHERE domain = 'apitest.com'");
test('site_update wrote row to siteops', count($rows) === 1, json_encode($rows));

resetRequest();
$_POST = [
    'post_uuid' => 'APISITEUUID1',
    'post_gitname' => 'apitest',
    'post_domain' => 'apitest.com',
    'post_sitetitle' => 'Updated Title',
    'post_description' => 'updated desc',
    'post_sitelogo' => '',
    'post_sitedeploy' => 'cloudflare',
    'post_sitehostip' => '',
    'post_sns_id' => 'apitest',
    'post_topnavmenus' => 'home,about,contact',
    'post_keyword' => 'test,update',
    'post_lang' => 'ja',
    'post_sitetype' => 'cta',
    'post_themetype' => 'poker',
    'post_status' => 'redo',
    'csrf_token' => 'uivision-key-1',
];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ORIGIN'] = 'https://uivision.example.com';
$out = runApiEndpoint(dirname(__DIR__) . '/api/site_update.php');
test('site_update updates existing via API', strpos($out, 'OK:') !== false, $out);
$rows = db("SELECT * FROM siteops WHERE domain = 'apitest.com'");
test('site_update updated title', count($rows) === 1 && $rows[0]['site_title'] === 'Updated Title', json_encode($rows));

resetRequest();
$_GET = ['csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$out = runApiEndpoint(dirname(__DIR__) . '/api/site_update.php');
test('site_update rejects GET', strpos($out, 'FAIL') !== false, $out);

resetRequest();
$_POST = [
    'post_gitname' => 'apibadsite',
    'post_domain' => 'apibad.com',
    'csrf_token' => 'wrong-token',
];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ORIGIN'] = 'https://uivision.example.com';
$out = runApiEndpoint(dirname(__DIR__) . '/api/site_update.php');
test('site_update rejects bad API token', strpos($out, 'forbidden') !== false, $out);

// --- api/topic_update.php bulk keyword ---
resetRequest();
$_POST = [
    'post_keyword' => 'bulk1,bulk2',
    'post_gitname' => 'bulksite',
    'post_domain' => 'bulk.com',
    'post_lang' => 'en',
    'post_geo' => 'US',
    'post_pubdir' => 'article',
    'post_status' => 'enable',
    'post_bulkkeyword' => 'enable',
    'csrf_token' => 'uivision-key-1',
];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ORIGIN'] = 'https://uivision.example.com';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topic_update.php');
test('topic_update bulk inserts 2 records', strpos($out, 'OK: 2') !== false, $out);
$rows = db("SELECT * FROM sitetopic WHERE git_name = 'bulksite'");
test('topic_update bulk wrote 2 rows', count($rows) === 2, json_encode($rows));

echo "== API query/task endpoints for UiVision ==\n";
putenv('APP_API_CSRF_TOKENS=uivision-key-1');
App\Config::reset();
App\Database::reset();

// Ensure sitetopic table exists for query/task tests
db("CREATE TABLE IF NOT EXISTS \"sitetopic\" (
    \"id\" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    \"ctx_id\" VARCHAR UNIQUE NOT NULL,
    \"git_name\" VARCHAR, \"domain\" VARCHAR, \"keyword\" VARCHAR,
    \"pubdir\" VARCHAR, \"status\" VARCHAR, \"lang\" VARCHAR, \"geo\" VARCHAR,
    \"lasttask\" VARCHAR, \"json\" VARCHAR, \"time\" DATETIME)");
db("CREATE TABLE IF NOT EXISTS \"keywordmonitorlist\" (
    \"id\" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    \"ctx_id\" VARCHAR UNIQUE NOT NULL,
    \"git_name\" VARCHAR, \"keyword\" VARCHAR, \"pubdir\" VARCHAR,
    \"status\" VARCHAR, \"lang\" VARCHAR, \"geo\" VARCHAR,
    \"lasttask\" VARCHAR, \"json\" VARCHAR, \"time\" DATETIME)");

// --- api/topic_ops.php ---
resetRequest();
$_POST = ['post_keyword' => '', 'post_gitname' => '', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topic_ops.php');
$json = json_decode($out, true);
test('topic_ops missing keyword returns 400', is_array($json) && isset($json['ok']) && $json['ok'] === false && strpos($out, 'forbidden') === false, $out);

resetRequest();
$_POST = ['keyword' => '', 'git_name' => 'opsalias', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topic_ops.php');
$json = json_decode($out, true);
test('topic_ops missing keyword alias returns 400', is_array($json) && $json['ok'] === false, $out);

resetRequest();
$_POST = ['post_keyword' => 'ops api kw', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topic_ops.php');
$json = json_decode($out, true);
test('topic_ops missing git_name returns 400', is_array($json) && $json['ok'] === false && strpos($json['error'], 'post_keyword and post_gitname') !== false, $out);

resetRequest();
$_POST = [
    'post_keyword' => 'ops api kw',
    'post_gitname' => 'opstopic',
    'post_domain' => 'opstopic.com',
    'post_lang' => 'en',
    'post_geo' => 'US',
    'csrf_token' => 'uivision-key-1',
];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topic_ops.php');
$json = json_decode($out, true);
test('topic_ops inserts via API', is_array($json) && $json['ok'] === true && $json['created'] === 1 && $json['updated'] === 0, $out);
test('topic_ops reports row action created', is_array($json) && isset($json['rows'][0]['action']) && $json['rows'][0]['action'] === 'created', $out);
test('topic_ops applies default pubdir', is_array($json) && $json['rows'][0]['pubdir'] === 'article', $out);
test('topic_ops applies default status', is_array($json) && $json['rows'][0]['status'] === 'enable', $out);
test('topic_ops returns ctx_id', is_array($json) && $json['rows'][0]['ctx_id'] !== '', $out);
$rows = db("SELECT * FROM sitetopic WHERE keyword = 'ops api kw' AND git_name = 'opstopic'");
test('topic_ops wrote row to sitetopic', count($rows) === 1, json_encode($rows));

resetRequest();
$_POST = [
    'post_keyword' => 'ops api kw',
    'post_gitname' => 'opstopic',
    'post_domain' => 'opstopic.com',
    'post_lang' => 'ja',
    'csrf_token' => 'uivision-key-1',
];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topic_ops.php');
$json = json_decode($out, true);
test('topic_ops upsert reports updated', is_array($json) && $json['ok'] === true && $json['created'] === 0 && $json['updated'] === 1, $out);
test('topic_ops upsert row action updated', is_array($json) && $json['rows'][0]['action'] === 'updated', $out);
$rows = db("SELECT * FROM sitetopic WHERE keyword = 'ops api kw' AND git_name = 'opstopic'");
test('topic_ops did not duplicate row on upsert', count($rows) === 1, json_encode($rows));
test('topic_ops upsert applied lang ja', count($rows) === 1 && $rows[0]['lang'] === 'ja', json_encode($rows));

// 部分更新：仅传 lang，其余列必须保留原值
resetRequest();
$_POST = ['post_keyword' => 'ops api kw', 'post_gitname' => 'opstopic', 'post_lang' => 'ko', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topic_ops.php');
$json = json_decode($out, true);
$rows = db("SELECT * FROM sitetopic WHERE keyword = 'ops api kw' AND git_name = 'opstopic'");
test('topic_ops partial update applied lang', count($rows) === 1 && $rows[0]['lang'] === 'ko', json_encode($rows));
test('topic_ops partial update kept domain', count($rows) === 1 && $rows[0]['domain'] === 'opstopic.com', json_encode($rows));
test('topic_ops partial update kept geo', count($rows) === 1 && $rows[0]['geo'] === 'US', json_encode($rows));
test('topic_ops partial update kept status default', count($rows) === 1 && $rows[0]['status'] === 'enable', json_encode($rows));
test('topic_ops partial update kept pubdir', count($rows) === 1 && $rows[0]['pubdir'] === 'article', json_encode($rows));
$decodedJson = json_decode(isset($rows[0]['json']) ? (string)$rows[0]['json'] : '{}', true);
test('topic_ops partial update json mirrors merged lang', is_array($decodedJson) && $decodedJson['lang'] === 'ko', json_encode($decodedJson));
test('topic_ops partial update json mirrors preserved geo', is_array($decodedJson) && $decodedJson['geo'] === 'US', json_encode($decodedJson));

// 部分更新：显式传空串仍应清空该列（区分"未传"与"传空"）
resetRequest();
$_POST = ['post_keyword' => 'ops api kw', 'post_gitname' => 'opstopic', 'post_geo' => '', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topic_ops.php');
$rows = db("SELECT * FROM sitetopic WHERE keyword = 'ops api kw' AND git_name = 'opstopic'");
test('topic_ops explicit empty clears only that column', count($rows) === 1 && $rows[0]['geo'] === '' && $rows[0]['lang'] === 'ko', json_encode($rows));

// 部分更新不应依赖默认状态值覆盖原值：新建时用 aidone，再只传 lang 更新
resetRequest();
$_POST = ['post_keyword' => 'ops keep status', 'post_gitname' => 'opskeep', 'post_status' => 'aidone', 'post_lang' => 'en', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topic_ops.php');
resetRequest();
$_POST = ['post_keyword' => 'ops keep status', 'post_gitname' => 'opskeep', 'post_lang' => 'fr', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topic_ops.php');
$rows = db("SELECT * FROM sitetopic WHERE keyword = 'ops keep status' AND git_name = 'opskeep'");
test('topic_ops partial update did not clobber status with default', count($rows) === 1 && $rows[0]['status'] === 'aidone', json_encode($rows));
test('topic_ops partial update applied lang over existing', count($rows) === 1 && $rows[0]['lang'] === 'fr', json_encode($rows));

resetRequest();
$_POST = ['keyword' => 'ops bulk a,ops bulk b,ops bulk c', 'git_name' => 'opsbulk', 'bulk' => 'true', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topic_ops.php');
$json = json_decode($out, true);
test('topic_ops bulk creates 3 records', is_array($json) && $json['ok'] === true && $json['total'] === 3 && $json['created'] === 3, $out);
$rows = db("SELECT * FROM sitetopic WHERE git_name = 'opsbulk'");
test('topic_ops bulk wrote 3 rows', count($rows) === 3, json_encode($rows));

resetRequest();
$_POST = ['keyword' => ' , , ', 'git_name' => 'opsbulk', 'bulk' => 'true', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topic_ops.php');
$json = json_decode($out, true);
test('topic_ops rejects all-empty bulk', is_array($json) && $json['ok'] === false && strpos($json['error'], 'no valid records') !== false, $out);

resetRequest();
$_POST = ['post_keyword' => 'ops nested', 'post_gitname' => 'opsnested', 'extra' => 'x', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topic_ops.php');
$json = json_decode($out, true);
test('topic_ops rejects GET', is_array($json) && $json['ok'] === false && strpos($json['error'], 'method not allowed') !== false, $out);

resetRequest();
$_POST = ['post_keyword' => 'ops tok', 'post_gitname' => 'opstok', 'csrf_token' => 'bad-key'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topic_ops.php');
$json = json_decode($out, true);
test('topic_ops rejects bad API token', is_array($json) && $json['ok'] === false && strpos($json['error'], 'forbidden') !== false, $out);

resetRequest();
$_SERVER['REQUEST_METHOD'] = 'OPTIONS';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topic_ops.php');
test('topic_ops OPTIONS returns empty 204', $out === '', $out);

// --- api/topicops.php（topic_ops.php 别名）---
resetRequest();
$_POST = ['post_keyword' => 'ops alias kw', 'post_gitname' => 'opsalias', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topicops.php');
$json = json_decode($out, true);
test('topicops alias inserts via API', is_array($json) && $json['ok'] === true && $json['created'] === 1, $out);
$rows = db("SELECT * FROM sitetopic WHERE keyword = 'ops alias kw' AND git_name = 'opsalias'");
test('topicops alias wrote row to sitetopic', count($rows) === 1, json_encode($rows));

resetRequest();
$_POST = ['post_keyword' => 'ops alias kw', 'post_gitname' => 'opsalias', 'post_lang' => 'de', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topicops.php');
$json = json_decode($out, true);
test('topicops alias supports partial update', is_array($json) && $json['ok'] === true && $json['updated'] === 1, $out);
$rows = db("SELECT * FROM sitetopic WHERE keyword = 'ops alias kw' AND git_name = 'opsalias'");
test('topicops alias partial update kept pubdir', count($rows) === 1 && $rows[0]['pubdir'] === 'article', json_encode($rows));

resetRequest();
$_POST = ['csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topicops.php');
$json = json_decode($out, true);
test('topicops alias rejects GET', is_array($json) && $json['ok'] === false && strpos($json['error'], 'method not allowed') !== false, $out);

// --- api/keyword_ops.php ---
resetRequest();
$_POST = ['post_keyword' => '', 'post_gitname' => '', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/keyword_ops.php');
$json = json_decode($out, true);
test('keyword_ops missing keyword returns 400', is_array($json) && $json['ok'] === false && strpos($json['error'], 'post_keyword and post_gitname') !== false && strpos($out, 'forbidden') === false, $out);

resetRequest();
$_POST = ['keyword' => '', 'git_name' => 'opsalias', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/keyword_ops.php');
$json = json_decode($out, true);
test('keyword_ops missing keyword alias returns 400', is_array($json) && $json['ok'] === false, $out);

resetRequest();
$_POST = ['post_gitname' => 'opskw', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/keyword_ops.php');
$json = json_decode($out, true);
test('keyword_ops missing git_name returns 400', is_array($json) && $json['ok'] === false, $out);

resetRequest();
$_POST = [
    'keyword' => 'ops kw one',
    'git_name' => 'opskw',
    'pubdir' => 'opsdir',
    'lang' => 'en',
    'geo' => 'US',
    'status' => 'aidone',
    'csrf_token' => 'uivision-key-1',
];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/keyword_ops.php');
$json = json_decode($out, true);
test('keyword_ops inserts via API', is_array($json) && $json['ok'] === true && $json['created'] === 1 && $json['updated'] === 0, $out);
test('keyword_ops reports row action created', is_array($json) && $json['rows'][0]['action'] === 'created', $out);
test('keyword_ops alias mapped pubdir', is_array($json) && $json['rows'][0]['pubdir'] === 'opsdir', $out);
test('keyword_ops alias mapped geo', is_array($json) && $json['rows'][0]['geo'] === 'US', $out);
test('keyword_ops returns ctx_id', is_array($json) && $json['rows'][0]['ctx_id'] !== '', $out);
$rows = db("SELECT * FROM keywordmonitorlist WHERE keyword = 'ops kw one'");
test('keyword_ops wrote row to keywordmonitorlist', count($rows) === 1, json_encode($rows));

// 部分更新：仅传 lang，其余列必须保留原值
resetRequest();
$_POST = ['post_keyword' => 'ops kw one', 'post_gitname' => 'opskw', 'post_lang' => 'ja', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/keyword_ops.php');
$json = json_decode($out, true);
test('keyword_ops upsert reports updated', is_array($json) && $json['ok'] === true && $json['created'] === 0 && $json['updated'] === 1, $out);
$rows = db("SELECT * FROM keywordmonitorlist WHERE keyword = 'ops kw one'");
test('keyword_ops did not duplicate row on upsert', count($rows) === 1, json_encode($rows));
test('keyword_ops partial update applied lang', count($rows) === 1 && $rows[0]['lang'] === 'ja', json_encode($rows));
test('keyword_ops partial update kept pubdir', count($rows) === 1 && $rows[0]['pubdir'] === 'opsdir', json_encode($rows));
test('keyword_ops partial update kept geo', count($rows) === 1 && $rows[0]['geo'] === 'US', json_encode($rows));
test('keyword_ops partial update kept status', count($rows) === 1 && $rows[0]['status'] === 'aidone', json_encode($rows));
$decodedJson = json_decode(isset($rows[0]['json']) ? (string)$rows[0]['json'] : '{}', true);
test('keyword_ops partial update json mirrors merged lang', is_array($decodedJson) && $decodedJson['lang'] === 'ja', json_encode($decodedJson));
test('keyword_ops partial update json mirrors preserved geo', is_array($decodedJson) && $decodedJson['geo'] === 'US', json_encode($decodedJson));

// 显式传空串仍应清空该列
resetRequest();
$_POST = ['post_keyword' => 'ops kw one', 'post_gitname' => 'opskw', 'post_geo' => '', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/keyword_ops.php');
$rows = db("SELECT * FROM keywordmonitorlist WHERE keyword = 'ops kw one'");
test('keyword_ops explicit empty clears only that column', count($rows) === 1 && $rows[0]['geo'] === '' && $rows[0]['lang'] === 'ja', json_encode($rows));

resetRequest();
$_POST = ['keyword' => 'ops bulk a,ops bulk b,ops bulk c', 'git_name' => 'opskwbulk', 'bulk' => 'true', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/keyword_ops.php');
$json = json_decode($out, true);
test('keyword_ops bulk creates 3 records', is_array($json) && $json['ok'] === true && $json['total'] === 3 && $json['created'] === 3, $out);
$rows = db("SELECT * FROM keywordmonitorlist WHERE git_name = 'opskwbulk'");
test('keyword_ops bulk wrote 3 rows', count($rows) === 3, json_encode($rows));

resetRequest();
$_POST = ['keyword' => ' , , ', 'git_name' => 'opskwbulk', 'bulk' => 'true', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/keyword_ops.php');
$json = json_decode($out, true);
test('keyword_ops rejects all-empty bulk', is_array($json) && $json['ok'] === false && strpos($json['error'], 'no valid records') !== false, $out);

resetRequest();
$_POST = ['csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$out = runApiEndpoint(dirname(__DIR__) . '/api/keyword_ops.php');
$json = json_decode($out, true);
test('keyword_ops rejects GET', is_array($json) && $json['ok'] === false && strpos($json['error'], 'method not allowed') !== false, $out);

resetRequest();
$_POST = ['post_keyword' => 'ops tok', 'post_gitname' => 'opstok', 'csrf_token' => 'bad-key'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/keyword_ops.php');
$json = json_decode($out, true);
test('keyword_ops rejects bad API token', is_array($json) && $json['ok'] === false && strpos($json['error'], 'forbidden') !== false, $out);

resetRequest();
$_SERVER['REQUEST_METHOD'] = 'OPTIONS';
$out = runApiEndpoint(dirname(__DIR__) . '/api/keyword_ops.php');
test('keyword_ops OPTIONS returns empty 204', $out === '', $out);

// --- api/keywordops.php（keyword_ops.php 别名）---
resetRequest();
$_POST = ['keyword' => 'kwalias kw', 'git_name' => 'kwalias', 'geo' => 'CN', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/keywordops.php');
$json = json_decode($out, true);
test('keywordops alias inserts via API', is_array($json) && $json['ok'] === true && $json['created'] === 1, $out);
$rows = db("SELECT * FROM keywordmonitorlist WHERE keyword = 'kwalias kw'");
test('keywordops alias wrote row', count($rows) === 1, json_encode($rows));

resetRequest();
$_POST = ['keyword' => 'kwalias kw', 'git_name' => 'kwalias', 'lang' => 'ja', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/keywordops.php');
$json = json_decode($out, true);
test('keywordops alias supports partial update', is_array($json) && $json['ok'] === true && $json['updated'] === 1, $out);
$rows = db("SELECT * FROM keywordmonitorlist WHERE keyword = 'kwalias kw'");
test('keywordops alias partial update kept geo', count($rows) === 1 && $rows[0]['geo'] === 'CN', json_encode($rows));

resetRequest();
$_POST = ['csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$out = runApiEndpoint(dirname(__DIR__) . '/api/keywordops.php');
$json = json_decode($out, true);
test('keywordops alias rejects GET', is_array($json) && $json['ok'] === false && strpos($json['error'], 'method not allowed') !== false, $out);

// --- api/siteops.php（site_ops.php 别名）---
resetRequest();
$_POST = ['domain' => 'siteops-alias.com', 'git_name' => 'siteopsalias', 'languages' => 'zh', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/siteops.php');
$json = json_decode($out, true);
test('siteops alias inserts via API', is_array($json) && $json['ok'] === true && $json['created'] === 1, $out);
$rows = db("SELECT * FROM siteops WHERE domain = 'siteops-alias.com'");
test('siteops alias wrote row', count($rows) === 1, json_encode($rows));

resetRequest();
$_POST = ['domain' => 'siteops-alias.com', 'git_name' => 'siteopsalias', 'languages' => 'ja', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/siteops.php');
$json = json_decode($out, true);
test('siteops alias supports partial update', is_array($json) && $json['ok'] === true && $json['updated'] === 1, $out);

resetRequest();
$_POST = ['csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$out = runApiEndpoint(dirname(__DIR__) . '/api/siteops.php');
$json = json_decode($out, true);
test('siteops alias rejects GET', is_array($json) && $json['ok'] === false && strpos($json['error'], 'method not allowed') !== false, $out);

// --- api/site_ops.php ---
resetRequest();
$_POST = ['post_domain' => '', 'post_gitname' => '', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/site_ops.php');
$json = json_decode($out, true);
test('site_ops missing domain returns 400', is_array($json) && $json['ok'] === false && strpos($json['error'], 'post_domain and post_gitname') !== false && strpos($out, 'forbidden') === false, $out);

resetRequest();
$_POST = ['domain' => '', 'git_name' => 'opsalias', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/site_ops.php');
$json = json_decode($out, true);
test('site_ops missing domain alias returns 400', is_array($json) && $json['ok'] === false, $out);

resetRequest();
$_POST = ['post_gitname' => 'opssite', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/site_ops.php');
$json = json_decode($out, true);
test('site_ops missing git_name returns 400', is_array($json) && $json['ok'] === false, $out);

resetRequest();
$_POST = [
    'domain' => 'opssite1.com',
    'git_name' => 'opssite',
    'site_title' => 'Ops Site One',
    'site_logo' => 'logo1.png',
    'languages' => 'en',
    'status' => 'aidone',
    'sns_id' => 'sns-1',
    'csrf_token' => 'uivision-key-1',
];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/site_ops.php');
$json = json_decode($out, true);
test('site_ops inserts via API', is_array($json) && $json['ok'] === true && $json['created'] === 1 && $json['updated'] === 0, $out);
test('site_ops reports row action created', is_array($json) && $json['rows'][0]['action'] === 'created', $out);
test('site_ops alias mapped site_title', is_array($json) && $json['rows'][0]['site_title'] === 'Ops Site One', $out);
test('site_ops alias mapped languages', is_array($json) && $json['rows'][0]['languages'] === 'en', $out);
test('site_ops returns ctx_id', is_array($json) && $json['rows'][0]['ctx_id'] !== '', $out);
$rows = db("SELECT * FROM siteops WHERE domain = 'opssite1.com'");
test('site_ops wrote row to siteops', count($rows) === 1, json_encode($rows));

// 部分更新：仅传 languages，其余列必须保留原值
resetRequest();
$_POST = ['post_domain' => 'opssite1.com', 'post_gitname' => 'opssite', 'post_lang' => 'ja', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/site_ops.php');
$json = json_decode($out, true);
test('site_ops upsert reports updated', is_array($json) && $json['ok'] === true && $json['created'] === 0 && $json['updated'] === 1, $out);
$rows = db("SELECT * FROM siteops WHERE domain = 'opssite1.com'");
test('site_ops did not duplicate row on upsert', count($rows) === 1, json_encode($rows));
test('site_ops partial update applied lang', count($rows) === 1 && $rows[0]['languages'] === 'ja', json_encode($rows));
test('site_ops partial update kept site_title', count($rows) === 1 && $rows[0]['site_title'] === 'Ops Site One', json_encode($rows));
test('site_ops partial update kept site_logo', count($rows) === 1 && $rows[0]['site_logo'] === 'logo1.png', json_encode($rows));
test('site_ops partial update kept status', count($rows) === 1 && $rows[0]['status'] === 'aidone', json_encode($rows));
test('site_ops partial update kept sns_id', count($rows) === 1 && $rows[0]['sns_id'] === 'sns-1', json_encode($rows));
$decodedJson = json_decode(isset($rows[0]['json']) ? (string)$rows[0]['json'] : '{}', true);
test('site_ops partial update json mirrors merged lang', is_array($decodedJson) && $decodedJson['languages'] === 'ja', json_encode($decodedJson));
test('site_ops partial update json mirrors preserved title', is_array($decodedJson) && $decodedJson['site_title'] === 'Ops Site One', json_encode($decodedJson));
test('site_ops partial update json mirrors preserved status', is_array($decodedJson) && $decodedJson['status'] === 'aidone', json_encode($decodedJson));

// 显式传空串仍应清空该列
resetRequest();
$_POST = ['post_domain' => 'opssite1.com', 'post_gitname' => 'opssite', 'post_sns_id' => '', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/site_ops.php');
$rows = db("SELECT * FROM siteops WHERE domain = 'opssite1.com'");
test('site_ops explicit empty clears only that column', count($rows) === 1 && $rows[0]['sns_id'] === '' && $rows[0]['languages'] === 'ja', json_encode($rows));

resetRequest();
$_POST = ['csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$out = runApiEndpoint(dirname(__DIR__) . '/api/site_ops.php');
$json = json_decode($out, true);
test('site_ops rejects GET', is_array($json) && $json['ok'] === false && strpos($json['error'], 'method not allowed') !== false, $out);

resetRequest();
$_POST = ['post_domain' => 'x.com', 'post_gitname' => 'x', 'csrf_token' => 'bad-key'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/site_ops.php');
$json = json_decode($out, true);
test('site_ops rejects bad API token', is_array($json) && $json['ok'] === false && strpos($json['error'], 'forbidden') !== false, $out);

resetRequest();
$_SERVER['REQUEST_METHOD'] = 'OPTIONS';
$out = runApiEndpoint(dirname(__DIR__) . '/api/site_ops.php');
test('site_ops OPTIONS returns empty 204', $out === '', $out);

// --- Database::each 游标（大表流式导出基础，topic_query/keywordquery/sitequery 等依赖） ---
$rowsA = \App\Database::fetchAll('SELECT "ctx_id", "json" FROM "sitetopic" ORDER BY "id"');
$rowsB = [];
foreach (\App\Database::each('SELECT "ctx_id", "json" FROM "sitetopic" ORDER BY "id"') as $rEach) {
    $rowsB[] = $rEach;
}
test('Database::each matches fetchAll rows', count($rowsA) > 0 && count($rowsA) === count($rowsB) && json_encode($rowsA) === json_encode($rowsB), count($rowsA) . ' vs ' . count($rowsB));

$nEach = 0;
foreach (\App\Database::each('SELECT "ctx_id" FROM "sitetopic" WHERE "ctx_id" = :c', [':c' => 'no-such-each-xyz']) as $rEach) {
    $nEach++;
}
test('Database::each yields nothing on empty result', $nEach === 0, (string)$nEach);

// --- api/topic_query.php ---
resetRequest();
$_GET = ['t' => 'all', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topic_query.php');
$json = json_decode($out, true);
test('topic_query all returns JSON array', is_array($json), $out);
$dbTopicCount = (int)\App\Database::fetchOne('SELECT COUNT(*) AS "n" FROM "sitetopic"')['n'];
test('topic_query all streams full table count', is_array($json) && count($json) === $dbTopicCount, (is_array($json) ? count($json) : 'null') . ' vs ' . $dbTopicCount);
test('topic_query all rows carry id', is_array($json) && count($json) > 0 && isset($json[0]['id']) && $json[0]['id'] !== '', substr($out, 0, 120));

resetRequest();
$_GET = ['t' => 'no-such-kw-xyz', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topic_query.php');
test('topic_query unknown keyword returns empty array', trim($out) === '[]', $out);

// aigc_status.php scope=all 同为全表导出：流式输出后总数须与 DB 一致
resetRequest();
$_GET = ['scope' => 'all', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$out = runApiEndpoint(dirname(__DIR__) . '/aigc_status.php');
$items = json_decode($out, true);
$dbAigcCount = (int)\App\Database::fetchOne('SELECT COUNT(*) AS "n" FROM "aigc_status"')['n'];
test('aigc_status all streams full table count', is_array($items) && count($items) === $dbAigcCount, (is_array($items) ? count($items) : 'null') . ' vs ' . $dbAigcCount);

resetRequest();
$_GET = ['csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topic_query.php');
test('topic_query missing t returns 400', strpos($out, 'error') !== false, $out);

resetRequest();
$_GET = ['csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topic_query.php');
test('topic_query rejects POST', strpos($out, 'error') !== false, $out);

// --- api/topic_task.php ---
db("INSERT OR IGNORE INTO sitetopic (ctx_id, git_name, domain, keyword, pubdir, status, lang, geo, json, time) VALUES ('APITASK1', 'task-site', 'task.com', 'task kw1', 'article', 'enable', 'en', 'US', '{\"keyword\":\"task kw1\",\"git_name\":\"task-site\",\"domain\":\"task.com\",\"lang\":\"en\",\"geo\":\"US\",\"status\":\"enable\",\"pubdir\":\"article\"}', '2026-08-01 09:00:00')");
resetRequest();
$_GET = ['t' => 'task kw1', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topic_task.php');
test('topic_task returns pipe-delimited line', strpos($out, 'APITASK1') !== false && strpos($out, '|') !== false, $out);
$rows = db("SELECT * FROM sitetopic WHERE ctx_id = 'APITASK1'");
test('topic_task updates lasttask', count($rows) === 1 && $rows[0]['lasttask'] === date('Ymd'), json_encode($rows));

resetRequest();
$_GET = ['t' => 'all', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topic_task.php');
test('topic_task all returns a line', trim($out) !== '', $out);

resetRequest();
$_GET = ['csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$out = runApiEndpoint(dirname(__DIR__) . '/api/topic_task.php');
test('topic_task missing t returns 400', strpos($out, 'FAIL') !== false, $out);

// --- api/keyword_query (keywordtask) ---
// 清理其他 keyword 行的 lasttask，确保只有 APIKWTASK1 满足 task 查询条件（避免 RANDOM 导致的不确定性）
db("UPDATE keywordmonitorlist SET lasttask = '" . date('Ymd') . "', json = json_set(CASE WHEN json_valid(json) THEN json ELSE '{}' END, '$.lasttask', '" . date('Ymd') . "')");
db("INSERT OR IGNORE INTO keywordmonitorlist (ctx_id, git_name, keyword, pubdir, status, lang, geo, json, time) VALUES ('APIKWTASK1', 'kwtask-site', 'kwtask kw1', 'article', 'enable', 'en', 'US', '{\"keyword\":\"kwtask kw1\",\"git_name\":\"kwtask-site\",\"lang\":\"en\",\"geo\":\"US\",\"status\":\"enable\",\"pubdir\":\"article\"}', '2026-08-01 09:00:00')");
resetRequest();
$_GET = ['t' => 'all', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$out = runApiEndpoint(dirname(__DIR__) . '/api/keyword_task.php');
test('keyword_task returns pipe-delimited line', strpos($out, 'APIKWTASK1') !== false && strpos($out, '|') !== false, $out);
$rows = db("SELECT * FROM keywordmonitorlist WHERE ctx_id = 'APIKWTASK1'");
test('keyword_task updates lasttask', count($rows) === 1 && $rows[0]['lasttask'] === date('Ymd'), json_encode($rows));

resetRequest();
$_GET = ['csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$out = runApiEndpoint(dirname(__DIR__) . '/api/keyword_task.php');
test('keyword_task missing t returns 400', strpos($out, 'FAIL') !== false, $out);

// --- api/site_query.php ---
resetRequest();
$_GET = ['t' => 'all', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$out = runApiEndpoint(dirname(__DIR__) . '/api/site_query.php');
$json = json_decode($out, true);
test('site_query all returns JSON array', is_array($json), $out);

resetRequest();
$_GET = ['t' => 'no-such-domain-xyz', 'csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$out = runApiEndpoint(dirname(__DIR__) . '/api/site_query.php');
test('site_query unknown search empty output', trim($out) === '' || $out === '[]', $out);

resetRequest();
$_GET = ['csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$out = runApiEndpoint(dirname(__DIR__) . '/api/site_query.php');
test('site_query missing t returns 400', strpos($out, 'FAIL') !== false || strpos($out, 'error') !== false, $out);

resetRequest();
$_GET = ['csrf_token' => 'uivision-key-1'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$out = runApiEndpoint(dirname(__DIR__) . '/api/site_query.php');
test('site_query rejects POST', strpos($out, 'FAIL') !== false || strpos($out, 'error') !== false, $out);

// --- 根端点 sitequery.php（此前零覆盖；验证重构后仍走 Database 层/Config 解析）---
echo "== root sitequery.php endpoint ==\n";
db("INSERT INTO siteops (ctx_id, git_name, domain, status, json, time) VALUES ('SQROOT1', 'sqroot1', 'sqroot1.com', 'enable', '{\"status\":\"done\",\"site_title\":\"SQ Root Done\"}', '2026-08-01 09:30:00')");
db("INSERT INTO siteops (ctx_id, git_name, domain, status, json, time) VALUES ('SQROOT2', 'sqroot2', 'sqroot2.com', 'enable', '{\"status\":\"pending\",\"site_title\":\"SQ Root Pending\"}', '2026-08-01 09:30:00')");
db("INSERT INTO sitetopic (ctx_id, git_name, domain, keyword, pubdir, status, lang, geo, lasttask, json, time) VALUES ('SQT1', 'sqt', 'sqroot1.com', 'sq topic', 'article', 'enable', 'en', 'US', '', '{}', '2026-08-01 09:30:00')");

// 数据源断言：Config::dbFile() 必须解析到测试库（重构前硬编码 sitedata.sqlite 会读到生产库）
test('sitequery resolves db file via Config', \App\Config::dbFile() === $testDb, \App\Config::dbFile());

$out = runRequest(['t' => 'all', 'csrf_token' => 'uivision-key-1'], [], 'GET', [], '/sitequery.php');
$json = json_decode($out, true);
test('sitequery root all returns JSON array', is_array($json), substr($out, 0, 150));
test('sitequery root all includes done fixture', is_array($json) && in_array('SQROOT1', array_column($json, 'id'), true), substr($out, 0, 200));
test('sitequery root all excludes non-done fixture', is_array($json) && !in_array('SQROOT2', array_column($json, 'id'), true), substr($out, 0, 200));

$out = runRequest(['t' => 'sqroot1.com', 'csrf_token' => 'uivision-key-1'], [], 'GET', [], '/sitequery.php');
$json = json_decode($out, true);
test('sitequery root domain filters to done rows', is_array($json) && count($json) === 1 && $json[0]['id'] === 'SQROOT1', substr($out, 0, 200));

$out = runRequest(['t' => 'no-such-site-xyz', 'csrf_token' => 'uivision-key-1'], [], 'GET', [], '/sitequery.php');
test('sitequery root unknown search returns empty array', trim($out) === '[]', var_export($out, true));

$out = runRequest(['csrf_token' => 'uivision-key-1'], [], 'GET', [], '/sitequery.php');
test('sitequery root missing t returns 400', strpos($out, 'error') !== false, var_export($out, true));

// limited 分支：LEFT JOIN sitetopic 域名计数 + ORDER BY RANDOM()
$out = runRequest(['t' => 'all', 'limit' => '1', 'csrf_token' => 'uivision-key-1'], [], 'GET', [], '/sitequery.php');
$json = json_decode($out, true);
test('sitequery root limit branch returns at most limit rows', is_array($json) && count($json) <= 1, substr($out, 0, 200));

echo "== uivision_upload seodata sync ==\n";
function runUivUpload(string $fileContent, string $savename, string $endpoint = '/api/uivision_upload.php'): string {
    resetRequest();
    $tmp = tempnam(sys_get_temp_dir(), 'uivtest');
    file_put_contents($tmp, $fileContent);
    $_FILES = ['uploadedFile' => ['name' => $savename, 'type' => 'text/plain', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)]];
    $_POST = ['savename' => $savename, 'csrf_token' => 'uivision-key-1'];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $out = runApiEndpoint(dirname(__DIR__) . $endpoint);
    // temp file may have been moved, cleanup if still exists
    if (file_exists($tmp)) @unlink($tmp);
    return $out;
}
$validJson = json_encode(["post_uuid"=>"UIVTEST001","title"=>["text"=>["UIV Test"]],"pubdomain"=>["example.com"],"lang"=>"en","topic"=>"kw","content"=>["html"=>["<p>hi</p>"]]]);
$out = runUivUpload("https://example.com|Test Topic|".$validJson, "UIVTEST001.txt");
test('uivision_upload valid JSON saves', strpos($out, 'File successfully uploaded') !== false, $out);
test('uivision_upload seodata json exists', file_exists($GLOBALS['dataDir'] . '/seodata/json/UIVTEST001.json'), $GLOBALS['dataDir'] . '/seodata/json/UIVTEST001.json');
test('uivision_upload aigc_status exists', file_exists($GLOBALS['dataDir'] . '/seodata/aigc_status.json'), '');
if (file_exists($GLOBALS['dataDir'] . '/seodata/aigc_status.json')) {
    $aigc = json_decode((string)file_get_contents($GLOBALS['dataDir'] . '/seodata/aigc_status.json'), true);
    $found = false; foreach ((array)$aigc as $row) if (($row['ctx_id'] ?? '') === 'UIVTEST001') $found = true;
    test('uivision_upload aigc_status contains ctx_id', $found, json_encode($aigc));
}
$out = runUivUpload("https://example.com|Topic|workflowerror", "UIVTEST002.txt");
test('uivision_upload workflowerror still uploads file', strpos($out, 'File successfully uploaded') !== false, $out);
test('uivision_upload workflowerror no seodata', !file_exists($GLOBALS['dataDir'] . '/seodata/json/UIVTEST002.json'), '');

$out = runUivUpload("https://example.com|Topic|{invalid json", "UIVTEST003.txt");
test('uivision_upload invalid JSON still uploads', strpos($out, 'File successfully uploaded') !== false, $out);
test('uivision_upload invalid JSON no seodata', !file_exists($GLOBALS['dataDir'] . '/seodata/json/UIVTEST003.json'), '');

$out = runUivUpload("https://example.com|Topic|".json_encode(["title"=>["text"=>["No UUID"]]]), "UIVTEST004.txt");
test('uivision_upload missing post_uuid no seodata', !file_exists($GLOBALS['dataDir'] . '/seodata/json/UIVTEST004.json'), $out);

$wrapped = json_encode([["post_uuid"=>"UIVTEST005","title"=>["text"=>["Wrapped"]],"pubdomain"=>["example.com"],"lang"=>"en"]]);
$out = runUivUpload("https://example.com|Topic|".$wrapped, "UIVTEST005.txt");
test('uivision_upload wrapped array saves', file_exists($GLOBALS['dataDir'] . '/seodata/json/UIVTEST005.json'), $out);

$out = runUivUpload($validJson, "UIVTEST006.txt");
test('uivision_upload pure JSON saves', file_exists($GLOBALS['dataDir'] . '/seodata/json/UIVTEST001.json'), $out);

// cleanup uploaded files
@unlink($GLOBALS['dataDir'] . '/seodata/json/UIVTEST001.json');
@unlink($GLOBALS['dataDir'] . '/seodata/json/UIVTEST005.json');
@unlink($GLOBALS['dataDir'] . '/seodata/json/UIVTEST006.json');
@unlink($GLOBALS['dataDir'] . '/uiaigcdatas/UIVTEST001.txt');
@unlink($GLOBALS['dataDir'] . '/uiaigcdatas/UIVTEST002.txt');
@unlink($GLOBALS['dataDir'] . '/uiaigcdatas/UIVTEST003.txt');
@unlink($GLOBALS['dataDir'] . '/uiaigcdatas/UIVTEST004.txt');
@unlink($GLOBALS['dataDir'] . '/uiaigcdatas/UIVTEST005.txt');
@unlink($GLOBALS['dataDir'] . '/uiaigcdatas/UIVTEST006.txt');

echo "== publish_edit JSON merge (only form fields) ==\n";
putenv('APP_API_CSRF_TOKENS=');
putenv('APP_AUTH_USER');
putenv('APP_AUTH_PASSWORD');
putenv('APP_AUTH_SECRET');
App\Config::reset();
App\Support\Security::reset();

$mergeId = '1700000000MERGE01';
$mergeDir = $dataDir . '/seodata/json';
if (!is_dir($mergeDir)) {
    @mkdir($mergeDir, 0755, true);
}
$mergeFile = $mergeDir . '/' . $mergeId . '.json';
file_put_contents($mergeFile, json_encode([[
    'post_uuid' => $mergeId,
    'url' => 'https://example.com/original.html',
    'title' => ['text' => ['Old Title'], 'extra' => 'keep-title-extra'],
    'topic' => 'Old Topic',
    'savename' => ['text' => ['old-slug']],
    'slug' => ['text' => ['old-slug']],
    'content' => ['html' => ['<p>old</p>'], 'oldhtml' => 'keep-me'],
    'description' => ['text' => ['old description']],
    'keywords' => ['text' => ['k1']],
    'tags' => ['text' => ['t1']],
    'createAt' => ['text' => [1600000000]],
    'lang' => 'en',
    'pubdir' => 'article',
    'series' => 'news',
    'globalpublish' => 'no',
    'pubdomain' => ['oldpoker.com'],
    'translate_to_langs' => ['fr'],
    'upload_site' => 'https://custom.example.com',
    'custom_key' => ['nested' => 'untouched'],
    'aigc' => ['score' => 99],
]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$html = runRequest(['eid' => $mergeId], [], 'GET', [], '/publish_edit.php');
preg_match('/id="post_savename"[^>]*value="([^"]*)"/', $html, $sm);
test('publish_edit form prefills savename', isset($sm[1]) && $sm[1] === 'old-slug', isset($sm[1]) ? $sm[1] : 'no match');
test('publish_edit form prefills title from json', strpos($html, 'value="Old Title"') !== false, substr($html, 0, 200));

$mergePost = [
    'post_uuid' => $mergeId,
    'post_url' => 'https://example.com/changed.html',
    'post_title' => 'New Title',
    'post_static_thumbnail' => 'https://img.example.com/new.jpg',
    'post_iframesrc' => 'https://img.example.com/new.gif',
    'post_tag' => 'poker,new',
    'post_keyword' => 'new keyword',
    'post_description' => 'New description',
    'post_ckeditor_contents' => '<p>new body</p>',
    'post_lang' => 'zh',
    'post_series' => 'news',
    'post_pubdir' => 'article',
    'post_savename' => 'new-slug',
    'post_pubdomain' => ['newpoker.com'],
    'post_globalpublish' => 'yes',
    'post_translate_to_langs' => ['de'],
    'csrf_token' => 'x',
    'setupNum' => 'ckeditorFormated',
];
$html = runRequest(['eid' => $mergeId], $mergePost, 'POST', [], '/publish_edit.php');
test('publish_edit update renders confirm', strpos($html, 'seodata 已同步') !== false, substr($html, 0, 200));
$raw = json_decode((string)file_get_contents($mergeFile), true);
$merged = is_array($raw) && isset($raw[0]) && is_array($raw[0]) ? $raw[0] : $raw;
test('publish_edit keeps wrapped array format', is_array($raw) && isset($raw[0]) && ($merged['post_uuid'] ?? '') === $mergeId, substr((string)file_get_contents($mergeFile), 0, 120));
test('publish_edit keeps custom keys untouched', ($merged['custom_key']['nested'] ?? '') === 'untouched' && ($merged['aigc']['score'] ?? null) === 99, json_encode($merged['custom_key'] ?? null));
test('publish_edit keeps content sibling keys', ($merged['content']['oldhtml'] ?? '') === 'keep-me', json_encode($merged['content'] ?? null));
test('publish_edit keeps title sibling keys', ($merged['title']['extra'] ?? '') === 'keep-title-extra', json_encode($merged['title'] ?? null));
test('publish_edit keeps createAt original', (int)($merged['createAt']['text'][0] ?? 0) === 1600000000, json_encode($merged['createAt'] ?? null));
test('publish_edit keeps upload_site original', ($merged['upload_site'] ?? '') === 'https://custom.example.com', (string)($merged['upload_site'] ?? ''));
test('publish_edit replaces title text without append', ($merged['title']['text'] ?? null) === ['New Title'], json_encode($merged['title']['text'] ?? null));
test('publish_edit replaces content html without append', ($merged['content']['html'] ?? null) === ['<p>new body</p>'], json_encode($merged['content']['html'] ?? null));
test('publish_edit writes adjusted savename + slug', ($merged['savename']['text'][0] ?? '') === 'new-slug' && ($merged['slug']['text'][0] ?? '') === 'new-slug', json_encode($merged['slug'] ?? null));
test('publish_edit writes adjusted lang + pubdomain', ($merged['lang'] ?? '') === 'zh' && ($merged['pubdomain'] ?? null) === ['newpoker.com'] && ($merged['globalpublish'] ?? '') === 'yes', json_encode($merged['pubdomain'] ?? null));
test('publish_edit bumps update_date', !empty($merged['update_date']), (string)($merged['update_date'] ?? ''));

// slug 与 savename 不一致且字段未改动时，slug 保持原值
$mergeId2 = '1700000000MERGE02';
$mergeFile2 = $mergeDir . '/' . $mergeId2 . '.json';
file_put_contents($mergeFile2, json_encode([[
    'post_uuid' => $mergeId2,
    'url' => 'https://example.com/two.html',
    'title' => ['text' => ['Two']],
    'savename' => ['text' => ['other-name']],
    'slug' => ['text' => ['keep-slug']],
    'createAt' => ['text' => [1700000001]],
]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$html = runRequest(['eid' => $mergeId2], [], 'GET', [], '/publish_edit.php');
preg_match('/id="post_savename"[^>]*value="([^"]*)"/', $html, $sm2);
test('publish_edit shows diverged savename value', isset($sm2[1]) && $sm2[1] === 'other-name', isset($sm2[1]) ? $sm2[1] : 'no match');
$post2 = $mergePost;
$post2['post_uuid'] = $mergeId2;
$post2['post_title'] = 'Two Edited';
$post2['post_savename'] = 'other-name';
$post2['post_url'] = 'https://example.com/two.html';
$post2['post_lang'] = 'en';
$post2['post_globalpublish'] = 'no';
$post2['post_pubdomain'] = ['oldpoker.com'];
$post2['post_translate_to_langs'] = ['fr'];
$post2['post_static_thumbnail'] = '';
$post2['post_iframesrc'] = '';
$post2['post_description'] = 'Two description';
$post2['post_ckeditor_contents'] = '<p>two</p>';
$post2['post_tag'] = 't1';
$post2['post_keyword'] = 'k1';
$post2['post_series'] = 'news';
$post2['post_pubdir'] = 'article';
runRequest(['eid' => $mergeId2], $post2, 'POST', [], '/publish_edit.php');
$raw2 = json_decode((string)file_get_contents($mergeFile2), true);
$merged2 = is_array($raw2) && isset($raw2[0]) && is_array($raw2[0]) ? $raw2[0] : $raw2;
test('publish_edit keeps untouched slug value', ($merged2['slug']['text'][0] ?? '') === 'keep-slug', json_encode($merged2['slug'] ?? null));
test('publish_edit keeps untouched savename value', ($merged2['savename']['text'][0] ?? '') === 'other-name', json_encode($merged2['savename'] ?? null));
test('publish_edit edits title while slug untouched', ($merged2['title']['text'][0] ?? '') === 'Two Edited' && ($merged2['createAt']['text'][0] ?? 0) == 1700000001, json_encode($merged2['title'] ?? null));

// 改动保存名称时 slug 同步跟随
$post3 = $post2;
$post3['post_savename'] = 'renamed-slug';
runRequest(['eid' => $mergeId2], $post3, 'POST', [], '/publish_edit.php');
$raw2 = json_decode((string)file_get_contents($mergeFile2), true);
$merged2 = is_array($raw2) && isset($raw2[0]) && is_array($raw2[0]) ? $raw2[0] : $raw2;
test('publish_edit syncs slug when savename edited', ($merged2['savename']['text'][0] ?? '') === 'renamed-slug' && ($merged2['slug']['text'][0] ?? '') === 'renamed-slug', json_encode($merged2['slug'] ?? null));
// 仅剩 savename 没有 slug 的 JSON，回填 slug
$mergeId3 = '1700000000MERGE03';
$mergeFile3 = $mergeDir . '/' . $mergeId3 . '.json';
file_put_contents($mergeFile3, json_encode([['post_uuid' => $mergeId3, 'url' => 'https://example.com/three.html', 'title' => ['text' => ['Three']], 'slug' => ['text' => ['three-slug']]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$html = runRequest(['eid' => $mergeId3], [], 'GET', [], '/publish_edit.php');
preg_match('/id="post_savename"[^>]*value="([^"]*)"/', $html, $sm3);
test('publish_edit falls back to slug for savename', isset($sm3[1]) && $sm3[1] === 'three-slug', isset($sm3[1]) ? $sm3[1] : 'no match');
$post4 = $post2;
$post4['post_uuid'] = $mergeId3;
$post4['post_title'] = 'Three';
$post4['post_savename'] = 'three-slug';
$post4['post_url'] = 'https://example.com/three.html';
runRequest(['eid' => $mergeId3], $post4, 'POST', [], '/publish_edit.php');
$raw3 = json_decode((string)file_get_contents($mergeFile3), true);
$merged3 = is_array($raw3) && isset($raw3[0]) && is_array($raw3[0]) ? $raw3[0] : $raw3;
test('publish_edit backfills savename from slug', ($merged3['savename']['text'][0] ?? '') === 'three-slug' && ($merged3['slug']['text'][0] ?? '') === 'three-slug', json_encode($merged3['savename'] ?? null));

@unlink($mergeFile);
@unlink($mergeFile2);
@unlink($mergeFile3);

echo "== publish_edit 字符串格式 text（value 不得只剩首字符 / 保存保格式） ==\n";
$strId = '1700000000STRFMT1';
$strFile = $mergeDir . '/' . $strId . '.json';
file_put_contents($strFile, json_encode([[
    'post_uuid' => $strId,
    'url' => 'https://example.com/strfmt.html',
    'title' => ['text' => 'Casino Guide Title'],
    'topic' => 'Casino Topic',
    'savename' => ['text' => 'casino-guide'],
    'slug' => ['text' => 'casino-guide'],
    'description' => ['text' => 'Full description text'],
    'static_thumbnail' => ['text' => 'https://img.example.com/a.jpg'],
    'content' => ['html' => '<p>string body</p>', 'oldhtml' => 'keep-str'],
    'keywords' => ['text' => 'kw1,kw2'],
    'tags' => ['text' => 't1'],
    'iframesrc' => ['text' => 'https://i.example.com/a.gif'],
    'createAt' => ['text' => [1600000099]],
    'lang' => 'en',
    'pubdir' => 'article',
    'series' => 'news',
    'globalpublish' => 'no',
    'pubdomain' => ['strfmt.example.com'],
    'translate_to_langs' => ['fr'],
]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$html = runRequest(['eid' => $strId], [], 'GET', [], '/publish_edit.php');
preg_match('/id="post_savename"[^>]*value="([^"]*)"/', $html, $sm4);
test('string text savename prefills full value not first char', isset($sm4[1]) && $sm4[1] === 'casino-guide', isset($sm4[1]) ? $sm4[1] : 'no match');
test('string text title prefills full value', strpos($html, 'value="Casino Guide Title"') !== false, '');
test('string text description prefills full value', strpos($html, 'value="Full description text"') !== false, '');
test('string text keywords prefills full list', strpos($html, 'value="kw1,kw2"') !== false, '');
test('string text thumbnail prefills full value', strpos($html, 'value="https://img.example.com/a.jpg"') !== false, '');
test('string text content prefills full html', strpos($html, '&lt;p&gt;string body&lt;/p&gt;') !== false, '');

$strPost = [
    'post_uuid' => $strId,
    'post_url' => 'https://example.com/strfmt.html',
    'post_title' => 'Casino Guide Title',
    'post_static_thumbnail' => 'https://img.example.com/a.jpg',
    'post_iframesrc' => 'https://i.example.com/a.gif',
    'post_tag' => 't1',
    'post_keyword' => 'kw1,kw2',
    'post_description' => 'Full description text',
    'post_ckeditor_contents' => '<p>string body</p>',
    'post_lang' => 'en',
    'post_series' => 'news',
    'post_pubdir' => 'article',
    'post_savename' => 'casino-guide',
    'post_pubdomain' => ['strfmt.example.com'],
    'post_globalpublish' => 'no',
    'post_translate_to_langs' => ['fr'],
    'csrf_token' => 'x',
    'setupNum' => 'ckeditorFormated',
];
runRequest(['eid' => $strId], $strPost, 'POST', [], '/publish_edit.php');
$rawS = json_decode((string)file_get_contents($strFile), true);
$mS = is_array($rawS) && isset($rawS[0]) && is_array($rawS[0]) ? $rawS[0] : $rawS;
test('save keeps savename text string format', ($mS['savename']['text'] ?? null) === 'casino-guide', json_encode($mS['savename'] ?? null));
test('save keeps slug text string format', ($mS['slug']['text'] ?? null) === 'casino-guide', json_encode($mS['slug'] ?? null));
test('save keeps title text string format', ($mS['title']['text'] ?? null) === 'Casino Guide Title', json_encode($mS['title']['text'] ?? null));
test('save keeps description text string format', ($mS['description']['text'] ?? null) === 'Full description text', json_encode($mS['description']['text'] ?? null));
test('save keeps keywords text string format', ($mS['keywords']['text'] ?? null) === 'kw1,kw2', json_encode($mS['keywords']['text'] ?? null));
test('save keeps content html string format with siblings', ($mS['content']['html'] ?? null) === '<p>string body</p>' && ($mS['content']['oldhtml'] ?? '') === 'keep-str', json_encode($mS['content'] ?? null));
$strRow = db("SELECT title, savename, keyword, tags, content FROM article WHERE ctx_id = '$strId'");
test('article row keeps full string values', count($strRow) === 1 && $strRow[0]['title'] === 'Casino Guide Title' && $strRow[0]['savename'] === 'casino-guide' && $strRow[0]['keyword'] === 'kw1,kw2' && $strRow[0]['tags'] === 't1' && $strRow[0]['content'] === '<p>string body</p>', json_encode($strRow[0] ?? null));
$strAigc = db("SELECT keyword FROM aigc_status WHERE ctx_id = '$strId'");
test('aigc index keyword keeps full topic', count($strAigc) === 1 && $strAigc[0]['keyword'] === 'Casino Topic', json_encode($strAigc[0] ?? null));

// 改名后仍保持字符串格式（不得变成 ["value"] 数组）
$strPost2 = $strPost;
$strPost2['post_savename'] = 'renamed-guide';
runRequest(['eid' => $strId], $strPost2, 'POST', [], '/publish_edit.php');
$rawS = json_decode((string)file_get_contents($strFile), true);
$mS = is_array($rawS) && isset($rawS[0]) && is_array($rawS[0]) ? $rawS[0] : $rawS;
test('rename keeps savename and slug string format', ($mS['savename']['text'] ?? null) === 'renamed-guide' && ($mS['slug']['text'] ?? null) === 'renamed-guide', json_encode([$mS['savename'] ?? null, $mS['slug'] ?? null]));

// savename 缺失、slug.text 为字符串时回填完整 slug（而非首字符）
$strId2 = '1700000000STRFMT2';
$strFile2 = $mergeDir . '/' . $strId2 . '.json';
file_put_contents($strFile2, json_encode([[
    'post_uuid' => $strId2,
    'url' => 'https://example.com/strfmt2.html',
    'title' => ['text' => ['Str Two']],
    'slug' => ['text' => 'slug-string-name'],
    'createAt' => ['text' => [1600000098]],
]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$html = runRequest(['eid' => $strId2], [], 'GET', [], '/publish_edit.php');
preg_match('/id="post_savename"[^>]*value="([^"]*)"/', $html, $sm5);
test('string text slug fallback prefills full value', isset($sm5[1]) && $sm5[1] === 'slug-string-name', isset($sm5[1]) ? $sm5[1] : 'no match');

@unlink($strFile);
@unlink($strFile2);

echo "== publish_edit json_edit form ==\n";
putenv('APP_API_CSRF_TOKENS=');
App\Config::reset();
App\Support\Security::reset();

$formId = '1700000000FORM001';
if (!is_dir($mergeDir)) {
    @mkdir($mergeDir, 0755, true);
}
$formFile = $mergeDir . '/' . $formId . '.json';
file_put_contents($formFile, json_encode([[
    'post_uuid' => $formId,
    'url' => 'https://example.com/form.html',
    'title' => ['text' => ['Form Test']],
    'pubdir' => 'custom-dir-not-in-config',
    'savename' => ['text' => ['form-test']],
    'slug' => ['text' => ['form-test']],
    'pubdomain' => ['ghost.example.com'],
    'globalpublish' => 'yes',
    'translate_to_langs' => ['ja'],
    'createAt' => ['text' => [1700000002]],
]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$html = runRequest(['eid' => $formId], [], 'GET', [], '/publish_edit.php');
test('publish_edit hides globalpublish switch', strpos($html, 'id="post_globalpublish"') === false && strpos($html, 'id="autopublish"') === false, substr($html, 0, 200));
test('publish_edit hides autotranslate block', strpos($html, 'id="autotranslate"') === false && strpos($html, 'name="post_translate_to_langs[]"') === false, substr($html, 0, 200));
test('publish_edit keeps pubdir config value as option', strpos($html, '<option data-subtext="配置值" value="custom-dir-not-in-config" selected>') !== false, 'pubdir fallback missing');
test('publish_edit keeps unknown pubdomain as option', strpos($html, '<option data-subtext="原配置值" selected>ghost.example.com</option>') !== false, 'pubdomain fallback missing');
test('publish_edit emits site menu script', strpos($html, 'js/sops-site-menus.js') !== false && strpos($html, 'sopsAttachSiteMenus({') !== false && strpos($html, "triggerId: 'post_pubdomain'") !== false, substr($html, -600));
test('publish_edit select carries data-id', strpos($html, 'data-id="post_pubdir"') !== false && strpos($html, 'data-id="post_pubdomain"') !== false, '');
$htmlArticle = runRequest([], [], 'GET', [], '/article_new.php');
test('article_new hides publish/autotranslate switch', strpos($htmlArticle, 'id="post_globalpublish"') === false && strpos($htmlArticle, 'id="autopublish"') === false && strpos($htmlArticle, 'id="autotranslate"') === false && strpos($htmlArticle, 'name="post_translate_to_langs[]"') === false, substr($htmlArticle, 0, 200));
test('article_new emits site menu script', strpos($htmlArticle, 'js/sops-site-menus.js') !== false && strpos($htmlArticle, 'sopsAttachSiteMenus({') !== false && strpos($htmlArticle, "triggerId: 'post_pubdomain'") !== false && strpos($htmlArticle, "targetId: 'post_pubdir'") !== false && strpos($htmlArticle, 'data-id="post_pubdir"') !== false, '');
preg_match('/id="post_pubdir".*?<\/select>/s', $htmlArticle, $mPubdir);
$pubdirHtml = isset($mPubdir[0]) ? $mPubdir[0] : '';
test('article_new pubdir keeps config options only', $pubdirHtml !== '' && strpos($pubdirHtml, 'data-subtext="未设置"') === false && strpos($pubdirHtml, 'data-subtext="home"') !== false, substr($pubdirHtml, 0, 200));

$json = runRequest([], ['action' => 'site_list', 'csrf_token' => 'x'], 'POST', [], '/publish_edit.php');
$siteRows = json_decode($json, true);
test('publish_edit site_list returns topnav_menus', is_array($siteRows) && isset($siteRows['rows'][0]) && array_key_exists('topnav_menus', $siteRows['rows'][0]), substr($json, 0, 200));
$json = runRequest([], ['action' => 'site_list', 'csrf_token' => 'x'], 'POST', [], '/article_new.php');
$siteRows = json_decode($json, true);
test('article_new site_list returns topnav_menus', is_array($siteRows) && isset($siteRows['rows'][0]) && array_key_exists('topnav_menus', $siteRows['rows'][0]), substr($json, 0, 200));

// 缺省提交（表单已移除发布/翻译开关）时保留原 JSON 的 globalpublish / translate_to_langs
$formPost = [
    'post_uuid' => $formId,
    'post_url' => 'https://example.com/form.html',
    'post_title' => 'Form Test Edited',
    'post_static_thumbnail' => '',
    'post_iframesrc' => '',
    'post_tag' => 't',
    'post_keyword' => 'k',
    'post_description' => 'd',
    'post_ckeditor_contents' => '<p>d</p>',
    'post_lang' => 'en',
    'post_series' => 'news',
    'post_pubdir' => 'custom-dir-not-in-config',
    'post_savename' => 'form-test',
    'post_pubdomain' => ['ghost.example.com'],
    'csrf_token' => 'x',
    'setupNum' => 'ckeditorFormated',
];
runRequest(['eid' => $formId], $formPost, 'POST', [], '/publish_edit.php');
$rawForm = json_decode((string)file_get_contents($formFile), true);
$mergedForm = is_array($rawForm) && isset($rawForm[0]) && is_array($rawForm[0]) ? $rawForm[0] : $rawForm;
test('publish_edit keeps globalpublish when field removed', ($mergedForm['globalpublish'] ?? '') === 'yes', (string)($mergedForm['globalpublish'] ?? ''));
test('publish_edit keeps translate_to_langs when field removed', ($mergedForm['translate_to_langs'] ?? null) === ['ja'], json_encode($mergedForm['translate_to_langs'] ?? null));
test('publish_edit keeps custom pubdir value', ($mergedForm['pubdir'] ?? '') === 'custom-dir-not-in-config', (string)($mergedForm['pubdir'] ?? ''));
test('publish_edit keeps unknown pubdomain value', ($mergedForm['pubdomain'] ?? null) === ['ghost.example.com'], json_encode($mergedForm['pubdomain'] ?? null));

// 提交了这两个字段时仍然覆盖
$formPost2 = $formPost;
$formPost2['post_globalpublish'] = 'no';
$formPost2['post_translate_to_langs'] = ['de'];
runRequest(['eid' => $formId], $formPost2, 'POST', [], '/publish_edit.php');
$rawForm = json_decode((string)file_get_contents($formFile), true);
$mergedForm = is_array($rawForm) && isset($rawForm[0]) && is_array($rawForm[0]) ? $rawForm[0] : $rawForm;
test('publish_edit overwrites when fields submitted', ($mergedForm['globalpublish'] ?? '') === 'no' && ($mergedForm['translate_to_langs'] ?? null) === ['de'], json_encode([$mergedForm['globalpublish'] ?? null, $mergedForm['translate_to_langs'] ?? null]));

// pubdir 为空时补「未设置」项，避免被首个配置项覆盖
$emptyId = '1700000000FORM002';
$emptyFile = $mergeDir . '/' . $emptyId . '.json';
file_put_contents($emptyFile, json_encode([['post_uuid' => $emptyId, 'url' => 'https://example.com/e.html', 'title' => ['text' => ['E']], 'pubdir' => '', 'createAt' => ['text' => [1700000003]]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$html = runRequest(['eid' => $emptyId], [], 'GET', [], '/publish_edit.php');
test('publish_edit empty pubdir keeps blank option', strpos($html, '<option data-subtext="未设置" value="" selected>（未设置）</option>') !== false, substr($html, 0, 300));

@unlink($formFile);
@unlink($emptyFile);

echo "== dash: 数据看板 ==\n";
$html = runRequest([], [], 'GET', [], '/dash.php');
test('dash renders page', strpos($html, '>数据看板</h1>') !== false && strpos($html, 'id="dashApp"') !== false, substr($html, 0, 200));
test('dash loads apexcharts locally', strpos($html, 'js/apexcharts.min.js') !== false && file_exists(dirname(__DIR__) . '/js/apexcharts.min.js'), '');
test('dash embeds stats json', preg_match('/<script type="application\/json" id="dash-stats">(.*?)<\/script>/s', $html, $dashMatch) === 1 && $dashMatch[1] !== '', 'stats json missing');
$dashStats = isset($dashMatch[1]) ? json_decode($dashMatch[1], true) : null;
test('dash stats json valid', is_array($dashStats) && isset($dashStats['summary']) && isset($dashStats['generated_at']), isset($dashMatch[1]) ? substr($dashMatch[1], 0, 160) : '');
// 「数据更新于」必须按显示时区（默认北京时间）渲染：bootstrap 固定 UTC，直接 date() 会比本地时钟慢 8 小时
$genAt = (string)($dashStats['generated_at'] ?? '');
$genDt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $genAt, new DateTimeZone('Asia/Shanghai'));
$genNowSh = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
test('dash generated_at matches beijing display clock', $genDt instanceof DateTimeImmutable && abs($genDt->getTimestamp() - $genNowSh->getTimestamp()) <= 120, $genAt . ' vs now=' . $genNowSh->format('Y-m-d H:i:s'));
$dashSvcSrc = (string)file_get_contents($root . '/app/Services/DashboardService.php');
test('dash generated_at source uses display timezone', strpos($dashSvcSrc, 'Config::displayTimezone()') !== false && strpos($dashSvcSrc, "'generated_at' => date(") === false, substr($dashSvcSrc, (int)strpos($dashSvcSrc, 'generated_at'), 160));
test('dash kpi cards rendered', strpos($html, 'data-kpi-value="sites"') !== false && strpos($html, 'data-kpi-value="topics"') !== false && strpos($html, 'data-kpi-value="keywords"') !== false && strpos($html, 'data-kpi-value="articles"') !== false && strpos($html, 'data-kpi-value="new_sites_today"') !== false && strpos($html, 'data-kpi-value="new_topics_week"') !== false && strpos($html, 'data-kpi-value="new_keywords_month"') !== false && strpos($html, 'data-kpi-value="new_articles_month"') !== false && strpos($html, 'data-kpi-value="new_today"') === false && strpos($html, 'data-kpi-value="published"') === false && strpos($html, 'data-kpi-value="rate"') === false && strpos($html, 'data-kpi-sub="rate"') === false && substr_count($html, 'col-6 col-lg-3 mb-3') === 4 && substr_count($html, '<a class="card dash-kpi') === 4, 'kpi: 4 cards each with total row + newcomer row expected');
test('dash kpi icon before label with unified color', preg_match('/<i class="bi [^"]*dash-kpi-icon" aria-hidden="true"><\/i>\s*<span class="dash-kpi-label/', $html) === 1 && strpos($html, '.dash-page .bi { color: var(--sops-sidebar-icon);') !== false && strpos($html, 'color: var(--sops-indigo);') !== false && strpos($html, 'dash-kpi-icon text-') === false, substr($html, strpos($html, 'dash-kpi-icon') - 120, 320));
test('dash icons covered by unified color rule', strpos($html, 'container app-page dash-page') !== false && strpos($html, '<div class="card-header"><i class="bi bi-pie-chart mr-1"') !== false, '');
test('dash btn icon hover matches btn text color', strpos($html, '.dash-page .btn:hover .bi { color: #fff; }') !== false && strpos($html, '.dash-page a:hover .bi,') !== false && strpos($html, '.dash-page .btn:hover .bi { color: var(--sops-indigo); }') === false, substr($html, (int)strpos($html, '.dash-page .btn:hover .bi'), 80));
test('dash charts use single-hue lightness palette', strpos($html, '--sops-chart-base: #6f6af8') !== false && strpos($html, 'function rampColors') !== false && strpos($html, 'var top = 94') !== false && strpos($html, 'base.l + (span * i)') !== false && strpos($html, 'labelColorOf') !== false && strpos($html, 'colors: ramp,') !== false && strpos($html, 'colors: barRamp,') !== false && strpos($html, 'colors: rampColors(2)') !== false && strpos($html, 'style: { colors: labelColorsOf(barRamp) }') !== false && strpos($html, '#4e79a7') === false && strpos($html, 'var palette') === false && strpos($html, 'background: var(--sops-chart-base)') !== false, substr($html, 0, 200));
test('dash donut metrics moved outside slices', substr_count($html, 'dataLabels: { enabled: false }') >= 2 && strpos($html, "label: '合计'") !== false && strpos($html, "value: { show: true, color: chartText()") !== false && strpos($html, "return label + ' · ' + value + '（' + pct + '%）';") !== false && strpos($html, "show: true,\n                name: { show: true") !== false && strpos($html, 'function (label, opts)') !== false, 'donut labels still on slices');
test('dash chart text themed to page tokens', strpos($html, 'function themeColor') !== false && substr_count($html, 'foreColor: chartText()') >= 3 && strpos($html, 'style: { colors: muted, fontSize') !== false && strpos($html, 'style: { colors: chartMuted(), fontSize') !== false && strpos($html, 'grid: { borderColor: chartBorder() }') !== false && strpos($html, "tooltip: { theme: 'light'") !== false && strpos($html, '#2b2f42') === false && strpos($html, '#6c757d') === false && strpos($html, '#adb5bd') === false && strpos($html, 'font-variant-numeric: tabular-nums') !== false, 'hardcoded chart text colors remain');
$dashCharts = ['chartSiteStatus', 'chartSiteLang', 'chartTopicStatus', 'chartTopicDomain', 'chartKeywordGeo', 'chartKeywordLang', 'chartArticlePublish', 'chartTrend'];
$dashMissing = [];
foreach ($dashCharts as $chartId) {
    if (strpos($html, 'id="' . $chartId . '"') === false) {
        $dashMissing[] = $chartId;
    }
}
test('dash all chart containers rendered', count($dashMissing) === 0, implode(',', $dashMissing));
test('dash summary tables rendered', strpos($html, 'id="dashTopSites"') !== false && strpos($html, 'id="dashRecentPublish"') !== false, '');
test('dash kpi value uses text color deltas keep chart palette', strpos($html, '.dash-kpi .dash-kpi-value { font-size: 1.9rem; font-weight: 600; line-height: 1.1; font-variant-numeric: tabular-nums; color: var(--sops-text); }') !== false && strpos($html, '.dash-kpi .dash-kpi-delta-value { font-size: 1.35rem; font-weight: 600; line-height: 1.05; font-variant-numeric: tabular-nums; color: var(--sops-chart-base); }') !== false && strpos($html, 'class="dash-kpi-value ') === false && !preg_match('/class="dash-kpi-value"[^>]*text-(primary|success|info|warning|danger|secondary)/', $html), 'kpi value color or delta palette wrong');
test('dash article pubdir chart removed', strpos($html, 'chartArticlePubdir') === false && strpos($html, '文章按发布目录') === false && strpos($html, "drawBar('chartArticlePubdir'") === false, 'pubdir chart still present');
test('dash publish domain chart removed', strpos($html, 'chartPublishDomain') === false && strpos($html, '发布域名分布') === false && strpos($html, "drawDonut('chartPublishDomain'") === false && !isset($dashStats['publish']['by_domain']), 'publish domain chart/stats still present');

// 移动端适配：表格表内横向滚动 + 小屏字号/高度 + 断点重绘
test('dash tables scroll inside card not page', substr_count($html, 'table-responsive dash-table-wrap') === 2 && strpos($html, 'dash-table-wrap') !== false && strpos($html, 'style="max-width:160px;"') === false && strpos($html, 'style="max-width:150px;"') === false, 'inline max-width cells or missing wrapper');
test('dash mobile media query shrinks kpi and charts', strpos($html, '@media (max-width: 576px)') !== false && strpos($html, '.dash-kpi .dash-kpi-value { font-size: 1.35rem; }') !== false && strpos($html, '.dash-chart { min-height: 220px; }') !== false && strpos($html, '.dash-cell-site, .dash-cell-domain, .dash-cell-keyword { max-width: 104px; }') !== false, '');
test('dash charts adapt mobile breakpoint', strpos($html, "matchMedia('(max-width: 576px)')") !== false && strpos($html, 'function axisFont()') !== false && strpos($html, 'maxWidth: isMobile() ? 96 : 160') !== false && strpos($html, "isMobile() && pairs.length > 6 ? 360 : 300") !== false, '');
test('dash redraws on viewport resize across breakpoint', strpos($html, "window.addEventListener('resize'") !== false && strpos($html, 'renderAll(readStats()); }, 300)') !== false, '');
test('dash table cells use classes not inline width', strpos($html, "style: 'max-width:") === false && strpos($html, "align: 'text-truncate dash-cell-keyword'") !== false && strpos($html, "align: 'text-truncate dash-cell-site'") !== false && strpos($html, 'dash-cell-time') !== false, '');

$dashCount = function ($sql) {
    try {
        return count(db($sql));
    } catch (\Throwable $e) {
        return -1;
    }
};
$dashNewTables = ['sites' => 'siteops', 'topics' => 'sitetopic', 'keywords' => 'keywordmonitorlist', 'articles' => 'article'];
$dashNewCount = function ($table, $window) {
    $from = $window === 'week'
        ? date('Y-m-d H:i:s', time() - 7 * 86400)
        : ($window === 'month' ? date('Y-m-01 00:00:00') : date('Y-m-d 00:00:00'));
    try {
        return count(db("SELECT \"time\" FROM \"" . $table . "\" WHERE \"time\" >= '" . $from . "' AND \"time\" <> ''"));
    } catch (\Throwable $e) {
        return -1;
    }
};
if (is_array($dashStats)) {
    test(
        'dash summary matches db counts',
        (int)($dashStats['summary']['sites'] ?? -1) === $dashCount('SELECT "domain" FROM "siteops"')
            && (int)($dashStats['summary']['topics'] ?? -1) === $dashCount('SELECT "keyword" FROM "sitetopic"')
            && (int)($dashStats['summary']['keywords'] ?? -1) === $dashCount('SELECT "keyword" FROM "keywordmonitorlist"')
            && (int)($dashStats['summary']['articles'] ?? -1) === $dashCount('SELECT "ctx_id" FROM "article"'),
        json_encode($dashStats['summary'] ?? [])
    );
    test(
        'dash dimensions well formed',
        isset($dashStats['sites']['by_status'], $dashStats['sites']['by_language'], $dashStats['topics']['by_status'], $dashStats['topics']['by_domain'], $dashStats['keywords']['by_geo'], $dashStats['keywords']['by_lang'], $dashStats['articles']['by_pubdir'], $dashStats['articles']['by_pubdomain'], $dashStats['top_sites']),
        implode(',', array_keys($dashStats))
    );
    $dashNewOk = !isset($dashStats['newcomers']['today']) && substr_count($html, '<a class="card dash-kpi') === 4 && substr_count($html, 'class="dash-kpi-deltas"') === 4;
    $dashNewDetail = [];
    foreach ($dashNewTables as $dashName => $dashTable) {
        $dashBox = isset($dashStats['newcomers'][$dashName]) && is_array($dashStats['newcomers'][$dashName]) ? $dashStats['newcomers'][$dashName] : null;
        foreach (['today', 'week', 'month'] as $dashWin) {
            $dashExpect = $dashNewCount($dashTable, $dashWin);
            $dashActual = is_array($dashBox) && isset($dashBox[$dashWin]) ? $dashBox[$dashWin] : null;
            if (!is_int($dashActual) || $dashActual !== $dashExpect) {
                $dashNewOk = false;
                $dashNewDetail[] = $dashName . '.' . $dashWin . '=' . json_encode($dashActual) . ' != ' . $dashExpect;
            }
        }
        if (is_array($dashBox) && (int)($dashBox['today'] ?? -1) > (int)($dashBox['week'] ?? -1)) {
            $dashNewOk = false;
            $dashNewDetail[] = $dashName . ' today > week';
        }
    }
    test(
        'dash newcomers stats split per item',
        $dashNewOk,
        implode('; ', $dashNewDetail) ?: json_encode($dashStats['newcomers'] ?? null)
    );
    test(
        'dash trend months aligned',
        is_array($dashStats['trend'] ?? null)
            && count($dashStats['trend']['months'] ?? []) === count($dashStats['trend']['created'] ?? [])
            && count($dashStats['trend']['months'] ?? []) === count($dashStats['trend']['published'] ?? []),
        json_encode($dashStats['trend'] ?? null)
    );
}

$dashNav = null;
foreach (App\Config::headerModules() as $navModule) {
    if (isset($navModule['url']) && $navModule['url'] === 'dash.php') {
        $dashNav = $navModule;
        break;
    }
}
test('dash registered in header nav', is_array($dashNav) && ($dashNav['title'] ?? '') === '数据看板' && ($dashNav['perm'] ?? '') === 'report.view', json_encode($dashNav));

// 带真实会话的场景：新建只读用户（report.view）与无权限用户
$dashViewerRole = db("SELECT id FROM roles WHERE name='viewer'");
$dashViewerId = App\Repositories\UserRepository::create([
    'username' => 'dashviewer',
    'password_hash' => password_hash('pass1234', PASSWORD_DEFAULT),
    'display_name' => 'Dash Viewer',
    'status' => 'active',
]);
App\Repositories\UserRepository::setRoles((int)$dashViewerId, [(int)$dashViewerRole[0]['id']]);
$dashAuth = App\Support\Security::buildAuthCookie('dashviewer', time() + 3600);
$dashCookie = ['siteops_uid' => 'dashv1', 'siteops_auth' => $dashAuth];
$htmlViewer = runRequest([], [], 'GET', $dashCookie, '/dash.php');
test('dash allowed for viewer role', strpos($htmlViewer, 'id="dashApp"') !== false, substr($htmlViewer, 0, 160));

db("INSERT INTO roles (name, description) VALUES ('dashnone', 'no permission')");
$dashNoneRole = db("SELECT id FROM roles WHERE name='dashnone'");
$dashNoneId = App\Repositories\UserRepository::create([
    'username' => 'dashnone',
    'password_hash' => password_hash('pass1234', PASSWORD_DEFAULT),
    'display_name' => 'Dash None',
    'status' => 'active',
]);
App\Repositories\UserRepository::setRoles((int)$dashNoneId, [(int)$dashNoneRole[0]['id']]);
$dashNoneAuth = App\Support\Security::buildAuthCookie('dashnone', time() + 3600);
$htmlNoPerm = runRequest([], [], 'GET', ['siteops_uid' => 'dashn1', 'siteops_auth' => $dashNoneAuth], '/dash.php');
test('dash blocked without report.view', strpos($htmlNoPerm, 'forbidden: insufficient permission') !== false, substr($htmlNoPerm, 0, 160));

$dashResp = runRequest([], ['action' => 'stats', 'csrf_token' => 'x'], 'POST', [], '/dash.php');
$dashPayload = json_decode($dashResp, true);
test('dash stats action returns fresh stats', is_array($dashPayload) && ($dashPayload['ok'] ?? false) === true && isset($dashPayload['stats']['summary']), substr($dashResp, 0, 200));
$dashRespBad = runRequest([], ['action' => 'stats', 'csrf_token' => 'deadbeefdeadbeefdeadbeefdeadbeef'], 'POST', $dashCookie, '/dash.php');
$dashPayloadBad = json_decode($dashRespBad, true);
test('dash stats action rejects bad csrf', is_array($dashPayloadBad) && ($dashPayloadBad['ok'] ?? true) === false, substr($dashRespBad, 0, 160));

echo "\n== 时间显示口径：存储 UTC / 显示浏览器时区 ==\n";
$sopsTimeJsSrc = (string)file_get_contents($root . '/js/sops-time.js');
$siteopsJsSrc = (string)file_get_contents($root . '/js/siteops.js');
$layoutHeadSrc = (string)file_get_contents($root . '/views/layout_head.php');
$pubListSrc = (string)file_get_contents($root . '/views/publish_list.php');
$topicListSrc = (string)file_get_contents($root . '/views/topic_list.php');
$articleListSrc = (string)file_get_contents($root . '/views/article_list.php');
$dashSrc = (string)file_get_contents($root . '/views/dashboard.php');
$bootstrapSrc = (string)file_get_contents($root . '/app/bootstrap.php');
test('bootstrap pins storage timezone to utc', strpos($bootstrapSrc, "date_default_timezone_set('UTC')") !== false && date_default_timezone_get() === 'UTC', substr($bootstrapSrc, 0, 200));
test('shared time helper converts storage utc to browser tz', strpos($sopsTimeJsSrc, 'window.sopsFormatTime') !== false && strpos($sopsTimeJsSrc, "replace(' ', 'T') + 'Z'") !== false && strpos($sopsTimeJsSrc, 'toLocaleString(undefined, opts)') !== false && strpos($sopsTimeJsSrc, 'timeZone: tz') !== false && strpos($sopsTimeJsSrc, '/^\\d{4}-\\d{2}-\\d{2}$/') !== false, substr($sopsTimeJsSrc, 0, 200));
test('time helper loaded in head before page scripts', strpos($layoutHeadSrc, '<script src="js/sops-time.js">') !== false, substr($layoutHeadSrc, 0, 200));
test('time helper degrades if script missing (head shim)', strpos($layoutHeadSrc, "typeof window.sopsFormatTime !== 'function'") !== false, '');
test('time helper degrades in table formatters', substr_count($topicListSrc, "typeof window.sopsFormatTime === 'function'") >= 1 && substr_count($pubListSrc, "typeof window.sopsFormatTime === 'function'") >= 1 && substr_count($articleListSrc, "typeof window.sopsFormatTime === 'function'") >= 1 && substr_count($dashSrc, "typeof window.sopsFormatTime === 'function'") >= 1, '');
test('siteops.js no longer sole definition of time helper', strpos($siteopsJsSrc, 'window.sopsFormatTime =') === false, '');
test('publish list no longer strips Z as local time', strpos($pubListSrc, 'sopsFormatTime(value)') !== false && strpos($pubListSrc, "replace('T',' ')") === false && strpos($pubListSrc, "replace('Z','')") === false, '');
test('topic list uses shared time helper', strpos($topicListSrc, 'sopsFormatTime(value)') !== false, '');
test('article list uses shared time helper', strpos($articleListSrc, 'sopsFormatTime(value)') !== false, '');
test('dashboard recent publish time uses shared helper', strpos($dashSrc, 'sopsFormatTime(row.publishAt') !== false, '');

echo "\n== 移动端侧边栏抽屉（汉堡导航） ==\n";
$html = runRequest([], [], 'GET', [], '/siteops.php');
test('header renders mobile nav backdrop', strpos($html, 'id="sopsNavBackdrop"') !== false && strpos($html, 'class="sops-nav-backdrop"') !== false, substr($html, (int)strpos($html, 'sops-nav-backdrop'), 160));
test('topbar toggle present for mobile hamburger', strpos($html, 'id="sidebarToggle"') !== false && strpos($html, 'sops-topbar') !== false, '');
$themeCssSrc = (string)file_get_contents($root . '/css/theme.css');
test('sidebar becomes off-canvas drawer on mobile', strpos($themeCssSrc, '@media (max-width: 991.98px)') !== false && strpos($themeCssSrc, 'body[data-mobile-nav="open"] .sops-sidebar') !== false && strpos($themeCssSrc, 'transform: translateX(-100%);') !== false && strpos($themeCssSrc, 'body[data-mobile-nav="open"] .sops-nav-backdrop { display: block; }') !== false && strpos($themeCssSrc, 'body[data-mobile-nav="open"] { overflow: hidden; }') !== false, '');
test('old horizontal strip menu rules removed', strpos($themeCssSrc, '.sops-sidebar-menu { flex-direction: row; }') === false && strpos($themeCssSrc, '.sops-sidebar-toggle { display: none; }') === false && strpos($themeCssSrc, '.sops-sidebar-brand .sops-sidebar-title { display: none; }') === false, 'legacy mobile strip rules remain');
test('mobile hamburger button visible not hidden', preg_match('/@media \(max-width: 991\.98px\).*?\.sops-sidebar-toggle \{ display: inline-flex; \}/s', $themeCssSrc) === 1, 'inline-flex toggle inside 991.98 block expected');
$siteopsJsSrc = (string)file_get_contents($root . '/js/siteops.js');
test('siteops toggles mobile drawer via body attr', strpos($siteopsJsSrc, "matchMedia('(max-width: 991.98px)')") !== false && strpos($siteopsJsSrc, 'setMobileNav') !== false && strpos($siteopsJsSrc, "document.body.setAttribute('data-mobile-nav'") !== false && strpos($siteopsJsSrc, "document.body.removeAttribute('data-mobile-nav')") !== false, '');
test('mobile parent link only expands submenu no jump', strpos($siteopsJsSrc, "closest('a.sops-sidebar-link-parent')") !== false && strpos($siteopsJsSrc, 'e.preventDefault();') !== false, '');
test('drawer closes via backdrop esc and nav link', strpos($siteopsJsSrc, 'backdrop.addEventListener') !== false && strpos($siteopsJsSrc, "e.key === 'Escape'") !== false && strpos($siteopsJsSrc, "closest('.sops-sidebar-nav a')") !== false && strpos($siteopsJsSrc, 'setMobileNav(false)') !== false, '');
test('drawer scrolls active item and resets on breakpoint', strpos($siteopsJsSrc, 'scrollIntoView') !== false && strpos($siteopsJsSrc, "mobileNavQuery.addEventListener('change'") !== false && strpos($siteopsJsSrc, "'打开导航菜单'") !== false, '');

echo "\n== ctx_id 时区安全：epoch 前缀、不走 date() ==\n";
$topicSvcSrc = (string)file_get_contents($root . '/app/Services/TopicService.php');
$ctxDefault = \App\Services\TopicService::defaultForm();
$ctxId = (string)$ctxDefault['ctx_id'];
$ctxEpoch = (int)substr($ctxId, 0, 10);
test('ctx id generated from epoch prefix', ctype_digit(substr($ctxId, 0, 10)) && abs($ctxEpoch - time()) <= 1, $ctxId);
test('ctx id format uniqid entropy', preg_match('/^[0-9a-f]{21,}$/', substr($ctxId, 10)) === 1 && strlen($ctxId) >= 31, $ctxId);
test('ctx id generation independent of date.timezone', strpos($topicSvcSrc, "date('") === false && substr_count($topicSvcSrc, 'uniqid(time(), true)') === 3, 'date_count=' . substr_count($topicSvcSrc, "date('") . ' uniqid=' . substr_count($topicSvcSrc, 'uniqid(time(), true)'));
$ctxFiles = glob($root . '/app/{Services,Repositories,Support}/*.php', GLOB_BRACE);
$ctxBad = [];
foreach ($ctxFiles as $ctxFile) {
    foreach (file($ctxFile, FILE_IGNORE_NEW_LINES) as $ctxLineNo => $ctxLine) {
        if (strpos($ctxLine, 'ctx_id') !== false && preg_match("/date\\(/", $ctxLine) === 1) {
            $ctxBad[] = basename($ctxFile) . ':' . ($ctxLineNo + 1);
        }
    }
}
test('no ctx_id built with date() anywhere', $ctxBad === [], implode(',', $ctxBad));

echo "\n========================================\n";
echo "PASS: {$GLOBALS['pass']}  FAIL: {$GLOBALS['fail']}\n";
exit($GLOBALS['fail'] > 0 ? 1 : 0);