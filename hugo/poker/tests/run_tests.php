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
test('headerModules grouped by 5 domains', is_array($mods) && count($mods) === 5 && $mods[0]['title'] === '站点管理' && $mods[1]['title'] === '关键词管理' && $mods[2]['title'] === '话题管理' && $mods[3]['title'] === '文章管理' && $mods[4]['title'] === '系统管理');
test('headerModules contains all entries', in_array('siteops.php', $flatUrls, true) && in_array('seo_report.php?reporttype=sitelist', $flatUrls, true) && in_array('keywordops.php', $flatUrls, true) && in_array('seo_report.php?reporttype=wordlist', $flatUrls, true) && in_array('seo_report.php?reporttype=relateword', $flatUrls, true) && in_array('topicops.php', $flatUrls, true) && in_array('topiclist.php', $flatUrls, true) && in_array('topictable.php', $flatUrls, true) && in_array('article_new.php', $flatUrls, true) && in_array('article_list.php', $flatUrls, true));
$html = runRequest();
test('header module nav renders', strpos($html, 'sops-sidebar-link') !== false);
foreach ($flatUrls as $url) {
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
    test('inserted time set', preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)$row['time']) === 1, $row['time']);
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
test('json column equals backup file', file_exists($backupFile) && file_get_contents($backupFile) === $rows[0]['json'] ?? '');
test('json column shown in confirm textarea', strpos($html, htmlspecialchars($rows[0]['json'])) !== false);

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
runRequest([], validPost([
    'post_gitname' => 'testpoker',
    'post_domain' => 'testpoker.com',
    'post_uuid' => 'NEWUUID002',
    'post_sitetitle' => 'Updated Title',
    'post_status' => 'draft',
]), 'POST');
$after = db("SELECT * FROM siteops");
test('same domain updates, no new row', count($after) === 3);
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
$html = runRequest([], [], 'GET', [], '/topictask.php');
test('api token required on task script without session', strpos($html, 'forbidden: missing or invalid API token') !== false, substr($html, 0, 120));
db("CREATE TABLE IF NOT EXISTS \"sitetopic\" (
    \"id\" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    \"ctx_id\" VARCHAR UNIQUE NOT NULL,
    \"git_name\" VARCHAR, \"domain\" VARCHAR, \"keyword\" VARCHAR,
    \"pubdir\" VARCHAR, \"status\" VARCHAR, \"lang\" VARCHAR, \"geo\" VARCHAR,
    \"lasttask\" VARCHAR, \"json\" VARCHAR, \"time\" DATETIME)");
db("INSERT INTO sitetopic (ctx_id, git_name, domain, keyword, pubdir, status, lang, geo, json, time) VALUES ('APITOK1', 'apitok', 'apitok.com', 'poker api token', 'article', 'enable', 'en', 'US', '{}', '2026-08-01 09:30:00')");
$html = runRequest(['t' => 'poker api token'], [], 'GET', [], '/topictask.php', [], 'apitoken-2');
test('api token allows task script via header', strpos($html, 'poker') !== false, substr($html, 0, 120));
db("DELETE FROM sitetopic WHERE ctx_id = 'APITOK1'");
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
putenv('APP_API_CSRF_TOKENS');
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
$html = runRequest([], [], 'GET', [], '/topictask.php');
test('auth+token: task script still 403 for session-less request', strpos($html, 'forbidden: missing or invalid API token') !== false, substr($html, 0, 120));
$html = runRequest([], [], 'GET', ['siteops_uid' => 'client1']);
test('auth+token: uid cookie without auth cookie sees login', strpos($html, 'name="login_action"') !== false, substr($html, 0, 120));
putenv('APP_API_CSRF_TOKENS');
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
test('article GET language/translateto options', strpos($html, '>en</option>') !== false && strpos($html, 'name="post_translate_to_langs[]"') !== false && strpos($html, '🇺🇸 en (English)') !== false);
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
putenv('APP_WECHAT_COOKIE_FILE');
\App\Config::reset();
$wc = \App\Config::wechatConfig();
test('wechat missing cookie file falls back', $wc['cookie'] === '' && count($wc['http_headers']) >= 10);
test('wechat_cookie_php exists in app root', is_file(\App\Config::wechatConfigFile()));

echo "== wechatops: POST save ==\n";
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
$astatus = $dataDir . '/aigc_status.json';
test('article publish writes aigc_status.json', file_exists($astatus), '');
if (file_exists($astatus)) {
    $astatusJson = json_decode((string)file_get_contents($astatus), true);
    test('article aigc_status entry format', is_array($astatusJson) && isset($astatusJson[0]['ctx_id']) && $astatusJson[0]['ctx_id'] === $pubCtx && isset($astatusJson[0]['keyword']) && $astatusJson[0]['keyword'] === 'Publishable Article' && isset($astatusJson[0]['lang']) && $astatusJson[0]['lang'] === 'en' && isset($astatusJson[0]['pubdomain']) && $astatusJson[0]['pubdomain'] === 'testpoker.com' && isset($astatusJson[0]['createAt']) && isset($astatusJson[0]['publishAt']), substr((string)file_get_contents($astatus), 0, 200));
    unlink($astatus);
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
$json = runRequest(['format' => 'json'], [], 'GET', [], '/topiclist.php');
$payload = json_decode($json, true);
test('topic list json returns total+rows', is_array($payload) && isset($payload['total']) && $payload['total'] >= 1 && isset($payload['rows'][0]['ctx_id']), substr($json, 0, 120));
test('topic list json contains exported topic', strpos($json, 'poker strategy guide') !== false);
test('topic list json rows carry ctx_id for edit', strpos($json, 'topicedit.php?eid=') !== false || (is_array($payload) && isset($payload['rows'][0]['ctx_id']) && $payload['rows'][0]['ctx_id'] !== ''));
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
$dbw = new SQLite3($testDb);
$dbw->enableExceptions(true);
$dbw->exec("INSERT INTO siteops (ctx_id, git_name, domain, status) VALUES ('DELETECONTEXT1', 'delsite', 'delsite.com', 'enable')");
$dbw->close();
$json = runRequest([], ['action' => 'delete-site', 'ctx_id' => 'DELETECONTEXT1', 'csrf_token' => 'x'], 'POST', [], '/seo_report.php');
$payload = json_decode($json, true);
test('report site delete removes row', is_array($payload) && $payload['ok'] === true && App\Repositories\SiteRepository::findByCtxId('DELETECONTEXT1') === null, substr($json, 0, 120));
$json = runRequest([], ['action' => 'delete-site', 'ctx_id' => 'NOPE-MISSING', 'csrf_token' => 'x'], 'POST', [], '/seo_report.php');
$payload = json_decode($json, true);
test('report site delete unknown ctx rejected', is_array($payload) && $payload['ok'] === false, substr($json, 0, 120));

echo "== report word delete ==\n";
$dbw = new SQLite3($testDb);
$dbw->enableExceptions(true);
$dbw->exec("INSERT INTO keywordmonitorlist (ctx_id, keyword, status, git_name, pubdir, lang) VALUES ('DELETEWORD1', 'delete word test', 'enable', 'delword', 'article', 'en')");
$dbw->exec("INSERT INTO sitetopic (ctx_id, git_name, domain, keyword, pubdir, status, lang, geo, lasttask, json, time) VALUES ('DELETETOPIC1', 'deltopic', 'deltopic.com', 'delete topic test', 'article', 'enable', 'en', 'US', '20260801093000', '{}', '2026-08-01 09:30:00')");
$dbw->close();
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
test('config import syncs all dictionary keys', count($importedMap) === 8 && isset($importedMap['sitetype']) && $importedMap['sitetype'] === $fileCfg['sitetype'], implode(',', array_keys($importedMap)));
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
$json = runRequest(['t' => 'all'], [], 'GET', [], '/topicquery.php');
$payload = json_decode($json, true);
test('topicquery all returns rows with id+json', is_array($payload) && count($payload) >= 1 && isset($payload[0]['id']) && isset($payload[0]['keyword']), substr($json, 0, 150));
$found = false;
foreach ($payload as $item) {
    if (isset($item['keyword']) && $item['keyword'] === 'poker strategy guide') {
        $found = true;
    }
}
test('topicquery all contains fixture keyword', $found, substr($json, 0, 150));
$json = runRequest(['t' => 'poker strategy guide'], [], 'GET', [], '/topicquery.php');
$payload = json_decode($json, true);
test('topicquery keyword filters rows', is_array($payload) && count($payload) === 1 && $payload[0]['keyword'] === 'poker strategy guide', substr($json, 0, 150));
$out = runRequest(['t' => 'no-such-keyword-xyz'], [], 'GET', [], '/topicquery.php');
test('topicquery unknown keyword empty output', trim($out) === '', var_export($out, true));
db("INSERT INTO sitetopic (ctx_id, git_name, domain, keyword, pubdir, status, lang, geo, lasttask, json, time) VALUES ('TASKDONE1', 'done-site', 'done.com', 'done topic', 'article', 'enable', 'en', 'US', '" . date('Ymd') . "', '{\"git_name\":\"done-site\",\"keyword\":\"done topic\",\"status\":\"enable\",\"lasttask\":\"" . date('Ymd') . "\"}', '2026-08-01 09:30:00')");
db("INSERT INTO sitetopic (ctx_id, git_name, domain, keyword, pubdir, status, lang, geo, lasttask, json, time) VALUES ('WRITEBACK1', 'wb-site', 'wb.com', 'writeback only', 'article', 'enable', 'en', 'US', '', '{\"git_name\":\"wb-site\",\"keyword\":\"writeback only\",\"status\":\"enable\",\"lang\":\"en\",\"geo\":\"US\",\"pubdir\":\"article\",\"domain\":\"wb.com\"}', '2026-08-01 09:30:00')");
$out = runRequest(['t' => 'writeback only'], [], 'GET', [], '/topictask.php');
$line = trim($out);
$wparts = explode('|', $line);
test('topictask writeback outputs row', count($wparts) === 8 && $wparts[1] === 'writeback only', $line);
$wbrow = db("SELECT * FROM sitetopic WHERE ctx_id='WRITEBACK1'");
$wbjson = is_array($wbrow) && isset($wbrow[0]['json']) ? json_decode($wbrow[0]['json'], true) : null;
test('topictask writeback sets json lasttask today', is_array($wbjson) && isset($wbjson['lasttask']) && $wbjson['lasttask'] === date('Ymd'), json_encode($wbjson));
test('topictask writeback sets json status enable', is_array($wbjson) && $wbjson['status'] === 'enable', json_encode($wbjson));
test('topictask writeback updates status column', is_array($wbrow) && isset($wbrow[0]['status']) && $wbrow[0]['status'] === 'enable', json_encode($wbrow));
test('topictask writeback updates lasttask column', is_array($wbrow) && isset($wbrow[0]['lasttask']) && $wbrow[0]['lasttask'] === date('Ymd'), json_encode($wbrow));
$out = runRequest(['t' => 'done topic'], [], 'GET', [], '/topictask.php');
test('topictask skips lasttask today rows', trim($out) === '', var_export($out, true));
$out = runRequest(['t' => 'all'], [], 'GET', [], '/topictask.php');
$line = trim($out);
$parts = explode('|', $line);
$jsondata = json_decode(end($parts), true);
test('topictask all outputs single file-format line', is_array($jsondata) && count($parts) === 8 && !empty($parts[0]), $line);
test('topictask selected row is enable', count($parts) === 8 && $parts[2] === 'enable', $line);
$dbrow = db("SELECT * FROM sitetopic WHERE ctx_id='" . $parts[0] . "'");
test('topictask row exists in database', count($dbrow) === 1, 'ctx=' . $parts[0]);
db("UPDATE sitetopic SET json = '{\"git_name\":\"testpoker\",\"domain\":\"testpoker.com\",\"keyword\":\"poker strategy guide\",\"pubdir\":\"article\",\"status\":\"enable\",\"lang\":\"en\",\"geo\":\"US\"}' WHERE ctx_id = 'TOPICCTX001'");
$out = runRequest(['t' => 'poker strategy guide'], [], 'GET', [], '/topictask.php');
$line = trim($out);
$parts = explode('|', $line);
test('topictask keyword returns matching row', count($parts) === 8 && $parts[1] === 'poker strategy guide', $line);
db("INSERT INTO sitetopic (ctx_id, git_name, domain, keyword, pubdir, status, lang, geo, json, time) VALUES ('BUGST1', 'bg', 'bg.com', 'bugstatus1', 'article', 'enable', 'en', 'US', '{\"keyword\":\"bugstatus1\",\"git_name\":\"bg\",\"lang\":\"en\",\"geo\":\"US\"}', '2026-08-01 09:30:00')");
db("INSERT INTO sitetopic (ctx_id, git_name, domain, keyword, pubdir, status, lang, geo, json, time) VALUES ('BUGPU1', 'bp', 'bp.com', 'bugpostuuid', 'article', 'enable', 'en', 'US', '{\"keyword\":\"bugpostuuid\",\"git_name\":\"bp\",\"lang\":\"en\",\"geo\":\"US\",\"post_uuid\":\"OTHER1\"}', '2026-08-01 09:30:00')");
$out = runRequest(['t' => 'bugstatus1'], [], 'GET', [], '/topictask.php');
$brow = db("SELECT * FROM sitetopic WHERE ctx_id='BUGST1'");
test('topictask writeback keeps status column when json lacks it', is_array($brow) && count($brow) === 1 && $brow[0]['status'] === 'enable', json_encode($brow));
$out = runRequest(['t' => 'bugpostuuid'], [], 'GET', [], '/topictask.php');
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
test('keywordquery unknown keyword empty output', trim($out) === '', var_export($out, true));
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

echo "\n========================================\n";
echo "PASS: {$GLOBALS['pass']}  FAIL: {$GLOBALS['fail']}\n";
exit($GLOBALS['fail'] > 0 ? 1 : 0);