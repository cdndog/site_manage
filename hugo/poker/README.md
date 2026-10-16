# V2 HUGO 建站手工录入系统 — 安装说明

`siteops.php` 站点录入/管理子系统的部署、配置与运维说明。本系统为原 `siteops.php`（已备份为 `siteops_legacy.php`）的重构版本：**URL 与外部契约完全兼容**，新增安全加固与多 key 图床轮询能力。

---

## 1. 系统简介

- 功能：站点（siteops）手工录入、回填编辑、提交入库、备份、导出
- 技术栈：原生 PHP（无 Composer / 无框架）+ SQLite3 + Bootstrap（本地 vendored）
- 单机部署，入口 URL 与原版一致：`siteops.php`

## 2. 环境要求

| 项目 | 要求 |
|---|---|
| PHP | 7.4+（测试环境 8.5），必选扩展：`sqlite3`、`curl`；可选：`fileinfo`（缺失时图片检测自动降级为 `getimagesize`，无需任何扩展）、`mbstring` |
| Web 服务器 | Apache / Nginx / 任意支持 PHP-FPM 的服务器（本项目为 UNIX 风格路径） |
| 数据库 | SQLite3（文件 `sitedata.sqlite`，含 `siteops` / `serverlist` 等表） |
| 目录权限 | 如下方「目录与权限」一节 |

验证扩展：

```bash
php -m | grep -iE "sqlite3|curl|fileinfo|mbstring"
```

## 3. 安装步骤

### 3.1 文件部署

将本项目整体拷贝到 Web 服务器目录（例如 `http://localhost:8888/hugo_wpk/`），确保目录结构完整：

```
poker/
├── siteops.php            # 入口（站点录入页）
├── imgbb_proxy.php        # 图片上传代理（服务端持有 imgbb key）
├── siteops_legacy.php     # 旧版备份（仅回滚用，不需要可删除）
├── global_config.php      # 全局配置
├── sitedata.sqlite        # SQLite 数据库（含 siteops/serverlist 表）
├── css/                   # Bootstrap 等前端资源（原生拷贝，无需构建）
├── js/                    # 前端脚本（含 siteops.js 业务脚本）
├── app/                   # 应用代码
│   ├── bootstrap.php      # 自动加载与常量
│   ├── Config.php         # 配置（支持环境变量覆盖）
│   ├── Database.php       # SQLite 连接单例
│   ├── Controllers/       # SiteController / AuthController / ImgBBController
│   ├── Services/          # SiteService / ExportService / ImgBBService
│   ├── Repositories/      # SiteRepository / ServerRepository
│   └── Support/           # Security（CSRF/登录）/ Logger（审计日志）等
├── views/                 # 视图：layout_head / header（导航模块）/ site_form / keyword_form / keyword_confirm / report_table / site_confirm / footer / layout_tail / login / error
├── var/                   # 运行态数据（自动创建，须可写）
├── tests/                 # 功能测试（run_tests.php）
└── siteops_setting.txt    # 导出文件（提交后自动生成）
```

### 3.2 目录与权限

| 路径 | 用途 | 权限要求 |
|---|---|---|
| `var/` | CSRF 密钥、imgbb key 轮询计数 | Web 用户可写（自动创建） |
| `sitebulkops/` | 每次提交的 JSON 备份 | Web 用户可写（自动创建） |
| `siteops_setting.txt` | 全量导出（下游 seo_report.php 等消费） | Web 用户可写 |
| `siteops_submit.log` | 提交审计日志 | Web 用户可写（自动追加） |
| `sitedata.sqlite` | 数据库 | Web 用户可读可写 |

```bash
chmod -R 755 poker
# 若 Web 用户非文件属主：
chown -R www-data:www-data poker/var poker/sitebulkops poker/siteops_setting.txt poker/siteops_submit.log
```

### 3.3 Web 服务器配置

Apache 示例（`httpd.conf` / vhost）：

```apache
DocumentRoot /var/www/hugo_wpk
<Directory /var/www/hugo_wpk>
    Require all granted
    AllowOverride All
</Directory>
```

Nginx + PHP-FPM 示例：

```nginx
server {
    listen 8888;
    root /var/www/hugo_wpk;
    index siteops.php index.php;
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
}
```

> 本项目使用相对路径引用 `css/`、`js/`，需与 PHP 入口同目录部署。

### 3.4 环境变量（可选，推荐生产使用）

| 变量 | 说明 | 缺省 |
|---|---|---|
| `IMGBB_API_KEY` | imgbb key，**逗号分隔支持多 key 轮询**（优先级最高） | global_config.php 中 `imgbb_api_key` 数组 |
| `IMGBB_API_URL` | imgbb 上传端点（测试/代理场景可覆盖） | `https://api.imgbb.com/1/upload` |
| `APP_CSRF_SECRET` | CSRF 签名密钥 | 自动生成并持久化到 `var/csrf_secret` |
| `APP_AUTH_SECRET` | 登录 cookie 签名密钥 | 取 CSRF 密钥 |
| `APP_AUTH_USER` | 启用登录时允许的用户名（不设则不启用） | 未启用 |
| `APP_AUTH_PASSWORD` | 登录密码，支持明文或 `password_hash` 格式（`$2y$...`） | 未启用 |
| `APP_DB_FILE` | 数据库文件绝对路径 | `APP_PATH/sitedata.sqlite` |
| `APP_DATA_DIR` | 导出/备份/日志目录（`siteops_setting.txt`、`sitebulkops/`、`siteops_submit.log` 均基于此） | `APP_PATH` |
| `APP_VAR_DIR` | 运行态目录（`csrf_secret`、imgbb 轮询计数） | `APP_PATH/var` |

Apache `SetEnv`：

```apache
SetEnv APP_AUTH_USER admin
SetEnv APP_AUTH_PASSWORD '$2y$10$...'
SetEnv IMGBB_API_KEY "key1,key2,key3"
```

Nginx：

```nginx
location ~ \.php$ {
    fastcgi_param APP_AUTH_USER admin;
    fastcgi_param APP_AUTH_PASSWORD '$2y$10$...';
}
```

## 4. 配置说明（global_config.php）

| 键 | 说明 |
|---|---|
| `base.imgbb_api_key` | **多 key 数组**：`['key1', 'key2', ...]`，上传时按数组顺序轮询，配额耗尽的 key 自动跳过尝试下一个 |
| `base.header_modules` | 顶部导航功能入口（header 模块）：`[['title'=>名称,'url'=>入口,'icon'=>bootstrap-icons 类名], ...]`。未配置时使用内置默认（站点录入 / 关键词配置 / SEO 报表），新增功能入口只需在此追加一行 |
| `base.database` | 默认数据库文件名（与 `APP_DB_FILE` 二选一） |
| `gitaccount` / `gitserver` | 代码库名 / 部署服务器 IP 下拉选项 |
| `languages` / `sitetype` / `themetype` | 语言 / 站点归类 / 模板下拉选项 |

## 5. 功能与安全特性

- **站点录入**：GET 打开空表单（默认 themetype=poker、sitetype=cta、deploy=cloudflare），`?eid=<ctx_id>` 回填编辑
- **关键词配置**（`keywordops.php`）：站点下拉来自 siteops 表，支持批量（逗号分隔）、按 keyword upsert、写 `keywordmonitor/{ctx_id}.json` 备份、导出 `keyword_monitor_list.txt`、审计日志
- **报表查看**（`seo_report.php`）：`reporttype=wordlist/sitelist/relateword` 三视图，读取 `keyword_monitor_list.txt` / `siteops_setting.txt` / `table_relatedword.txt`，bootstrap-table 搜索/排序/分页，sitelist 行内编辑直达 `siteops.php?eid=`
- **数据看板**（`dash.php`）：4 张 KPI 卡（站点/话题/关键词/文章），卡内上行总数、下行 当日/本周/本月 新增三个数字（本周为滚动近 7 天；总数取主题正文色 `--sops-text`，增量数字取图表色阶首色 `--sops-chart-base`，不再各用一种 bootstrap 彩色），8 个图表（状态、语言、地域、月度创建/发布趋势）+ 话题 TOP 站点、最近发布两张表；ApexCharts 本地渲染（`js/apexcharts.min.js`，不依赖 CDN，无 JS 时回退为进度条），统计由 `DashboardService` 聚合（缺表/查询失败降级为 0），`POST action=stats` 带 CSRF 可刷新（「数据更新于」时间戳按显示时区渲染，默认北京时间——bootstrap 固定 UTC，此前直接 `date()` 会比本地时钟慢 8 小时）；侧边栏入口「数据看板」，权限 `report.view`；**移动端适配**：TOP 站点/最近发布两表包在 `table-responsive` 内表内横向滚动（列宽由 `dash-cell-*` class 控制、≤576px 收窄至 104px，不再把整页撑出横向滚动条），KPI 卡与图表按 `@media (max-width:576px)` 缩字号/高度，bar 图 y 轴长域名标签 `maxWidth` 限宽、类目多时加高，`matchMedia` 断点 + `resize` 防抖 300ms 跨断点重绘
- **看板配色**：全部图表取单一基准色 `--sops-chart-base`（默认 `#6f6af8`，`.dash-page` 处改一行即可整体换色），`rampColors()` 固定色相/饱和度、亮度逐级**变浅**派生（类目越多步长越小，上限 94%，首色=基准色），无 JS 回退条同色；侧边栏/卡片图标同用 `--sops-sidebar-icon`（hover 变 `--sops-indigo`）
- **看板文字**：图内文字一律取主题 token，不写死灰色——`chart.foreColor`/图例 = `--sops-text`，坐标轴/合计标签 = `--sops-muted`，网格与轴线 = `--sops-border`，提示框 `theme:'light'`，空态与回退条同色；数值列 `font-variant-numeric: tabular-nums`
- **指标放图外**：环形图不再往扇区压百分比，`dataLabels` 关闭，数值与占比放图例（`标签 · 数值（占比%）`）、合计放中心孔（`合计 18`）；条形图受 Apex 限制（`dataLabels.offsetX` 对 bar 强制为 0、`position` 仅对纵向生效）数值留在条内，但按 WCAG 对比度自动取白字或主题正文字；单月趋势用 `markers` 显示数据点（折线/面积在单点时画不出来）
- **移动端导航（侧边栏抽屉）**：≤991.98px 时 `<aside class="sops-sidebar">` 从横向滚动菜单条改为**离屏抽屉**——默认 `transform: translateX(-100%)` 移出视口，顶栏汉堡按钮（原 `.sops-sidebar-toggle`，移动端不再隐藏）切换 `body[data-mobile-nav=open]`，遮罩（`#sopsNavBackdrop`）点击 / Esc / 选中菜单项后收起；抽屉内为竖排菜单 + 手风琴子菜单（父级点击 `preventDefault` 只展开不跳转，桌面保持原 href 行为），激活项 `scrollIntoView` 滚入可视区，触摸目标 ≥40px，开启时锁 body 滚动，`matchMedia` 断点变化（旋转/缩放回桌面）自动复位；aria-label 随开合切换「打开/关闭导航菜单」
- **显示时区**（系统管理→配置管理）：`config_list.php` 顶部「显示时区」卡片，IANA 下拉（bootstrap-select 可搜索）默认北京时间 `Asia/Shanghai`，`POST action=save-display-tz`（CSRF + `DateTimeZone` 校验，非法拒绝并保持原值）落 `app_configs.display_timezone`（标量 JSON，`Config::dbDictionary()` 会把字符串值并入 `Config::get`），`Config::displayTimezone()` 非法/缺失回落北京时间；`layout_head.php` 注入 `window.sopsTimeZone`，`js/sops-time.js` 用 `toLocaleString(undefined, {timeZone})` 换算（另提供 `sopsSetTimeZone()` 运行时切换，Intl 非法标识降级浏览器本地），侧栏时钟（`header.php`）同口径渲染；**存储始终 UTC**，切换只影响显示
- **时间口径**：存储一律 UTC（`app/bootstrap.php` 固定 `date_default_timezone_set('UTC')`；`time` 列为 `Y-m-d H:i:s`，`publishAt/createAt` 为 ISO8601 带 `Z`），显示一律按「显示时区」配置换算（默认北京时间，未配置/非法降级浏览器本地时区）——公共 helper `window.sopsFormatTime()`（`js/sops-time.js`，由 `layout_head.php` 提前加载，`js/siteops.js` 不再定义）供 topiclist 日期、publish/article 列表时间、看板最近发布共用；helper 缺失时降级为原值显示（head 内兜底 shim + 各 formatter `typeof` 守卫），绝不因 ReferenceError 把 bootstrap-table 卡在 Loading；纯日期（`YYYY-MM-DD`）无时区语义原样显示；`ctx_id` 前缀为 `time()` epoch（与 `date.timezone` 无关，全部门经测试固化）
- **大表流式导出**：全表查询接口不再整表驻留内存——`Database::each()`（generator 游标）+ 逐行 `decode/encode` 直接写出，峰值内存≈单行大小（实测 2000 行×30KB json：修复前 128MB 内存耗尽，修复后 2MB）；涉及 `api/topic_query.php`（`t=all` 曾触发 `Allowed memory size ... exhausted`）、`keywordquery.php`、`api/site_query.php`、`sitequery.php`、`aigc_status.php`，以及 `seodata/aigc_status.json` 缓存重写（`AigcStatusRepository::writeCacheFile()`，publish 删除/更新、publish_edit、上传保存均走它）；`'['` 在拿到首行后才输出，DB 异常仍返回干净 500
- **图片关键词搜索**（`api/image_search.php`，服务层 `app/Services/ImageSearchService.php`）：`GET ?q=<关键词>&limit=<1-20>`（兼容 `keyword` 别名），按关键词检索图片并返回真实图片地址数组（JSON：`{ok,keyword,engine,count,images,source}`）。**双引擎策略**：先走 Google（`tbm=isch` + `tbs=isz:lt,islt:xga,iar:w,itp:,ift:,sur:,qdr:,ic:` + `safe=active`/`nfpr=1`/`filter=0`，结果按条目截断、排除 `.gstatic.com`、改写 `i1.wp.com` 代理）；**Google 现对服务端请求返回 JS 引导页（enablejs 墙）常 0 条，故自动回退 Bing Images**——Bing 服务端直接渲染 `class="iusc"` 锚点的 `m="{...murl...}"`（HTML 实体编码 JSON），无需 JS 即可拿到真实图片地址，`engine` 字段标明实际命中引擎（google/bing/none）。**代理支持**：网络层 curl 优先、流上下文回退，可通过 `setProxy()` 或环境变量 `APP_IMAGE_SEARCH_PROXY`（如 `127.0.0.1:7890`）配置 HTTP 代理，直连受限环境必要（实测经该代理可返回真实图片）。超时/异常返回空数组或 502（`SOPS_TESTING` 下可注入 fixture 便于离线测试）。**可访问性/图片类型/版权校验（默认开启，`?validate=0` 跳过）**：候选地址先剔除版权/图库受限域名黑名单（shutterstock/gettyimages/alamy/dreamstime/123rf/adobe stock/envato/freepik 等，主域+子域+URL 内嵌命中），再并发探测（curl_multi HEAD 优先、失败回退 Range GET 取 1 字节判 Content-Type）：要求 2xx 且 Content-Type 为 `image/*`（无 Content-Type 回退 URL 扩展名），仅保留可直接外链的图片地址；响应含 `validated/checked/count`，`SOPS_TESTING` 下可用 `$GLOBALS['__imageSearchValidateStub']` 离线注入探测结果。同时修复原正则的跨条目贪婪匹配 bug（`(?:(?!.gstatic.com).)+` 会把多张图拼成畸形 URL，改为按闭合引号逐条捕获 + 代码侧过滤 gstatic）
- **话题列表批量操作**（`topiclist.php`）：表格勾选列多选，头部「批量编辑」「批量删除」按钮随勾选数启用并显示计数（`onCheck/onUncheck/onCheckAll/onUncheckAll/onLoadSuccess` 驱动）；批量删除 = `POST action=delete-topics&ctx_ids=a,b`（支持数组或逗号分隔，逐条校验存在、最后统一导出一次，不存在的计入 missing 说明）；批量编辑 = `POST action=update-topics` + 白名单字段（keyword/git_name/domain/pubdir/status/lang/geo/lasttask，**空值字段跳过不覆盖**、值内竖线剔除与录入页一致），走 `TopicRepository::upsertByTopic($record, $providedColumns)` 部分更新——只写提交列并按合并结果重算 json，单条失败（唯一键冲突等）计数不中断其余；两接口均要求 `topic.manage` + CSRF；弹窗提供 status/lang/geo/pubdir 字典下拉，全部留空提交会拒绝
- **提交**：POST 落库（按 domain upsert，保留原 ctx_id）、写 `sitebulkops/{ctx_id}.json` 备份、全量导出 `siteops_setting.txt`、追加审计日志
- **接口兼容**：`setupNum=ckeditorFormated` 是提交标志；无 cookie 的请求视为 API（`text2database_merge.sh` 等脚本回灌不受 CSRF/登录影响）
- **CSRF 防护**：浏览器请求强制校验签名 token（表单隐藏域 / 上传用 `X-CSRF-Token` 头）
- **可选登录**：设置 `APP_AUTH_USER` + `APP_AUTH_PASSWORD` 后启用，签名 cookie 12 小时有效；登录态过期后访问任意页面统一渲染登录页并提示「登录状态已失效，请登录后再操作」（XHR/`format=json` 返回 `401 {"code":401,"message":...}`，前端提示后自动刷新跳登录），登录成功回跳原地址（含查询串）
- **图片上传**：前端不再持有 imgbb key，统一经 `imgbb_proxy.php` 服务端代理（限 10MB、仅 image/*）
- **错误处理**：异常统一渲染 500 错误页并写入 PHP error log，不再白屏

## 6. 外部集成契约（勿破坏）

1. **POST 参数**（`text2database_merge.sh` 跨服回灌）：`post_uuid / post_gitname / post_gitaccount / post_domain / post_sitedeploy / post_sitehostip / post_lang / post_sitetype / post_themename / post_themetype / post_status / post_sitetitle / post_description / post_sitelogo / post_keyword / post_sns_id / post_topnavmenus / post_sitedir / local_deploy / local_hostip / setupNum=ckeditorFormated / post_json`
2. **导出文件** `siteops_setting.txt`，每行 12 列（固定顺序）：
   `ctx_id|git_name|git_account|status|theme_type|languages|domain|sns_id|topnav_menus|site_title|site_subtitle|json`
3. **导出文件** `keyword_monitor_list.txt`，每行 7 列（固定顺序）：
   `ctx_id|keyword|status|git_name|pubdir|lang|json`
4. **下游消费者**：`seo_report.php`（报表）、`sitequery.php`（status=done + json 展开）、`topicops.php`（下拉）、`keywordops.php`（站点下拉）等
5. **备份文件**：`sitebulkops/{ctx_id}.json`（siteops 表 json 列）、`keywordmonitor/{ctx_id}.json`（keywordmonitorlist 表 json 列）
6. **查询接口输出**：`topic_query/keywordquery/aigc_status` 无匹配时返回 `200 + []`；`sitequery/site_query` 无匹配时不输出 body；以上均为**服务端流式写出**（响应结构、Content-Type、`X-Result-Count` 等头部不变，调用方无需改动）

## 7. 功能测试

```bash
php tests/run_tests.php
```

- 结果示例：`PASS: 1035  FAIL: 0`
- 测试使用独立沙箱（`tests/tmp/` 下的临时数据库与数据目录），不影响生产数据
- 覆盖：默认表单、入库/更新（upsert 保留 ctx_id）、管道符清洗、HTML 转义、导出 12 列、eid 回填、SQL 注入 payload、空 domain、无效 POST、merge 脚本契约、CSRF 全部分支、登录开关、imgbb 校验与轮询、**imgbb 异常兜底（超限/网络失败/服务器异常均返回 JSON）**、关键词录入（单条/批量/upsert/CSRF/备份/7 列导出）、报表三视图（wordlist/sitelist/relateword 解析与空态）、header/footer 模块、审计日志、错误页、**数据看板（入口/权限/统计刷新/图表与图标配色；「数据更新于」与当日/本周/本月新增统计均以【配置显示时区】为界——如设为上海时区则以上海 0 点为一天/一月起点而非 UTC 0 点，本周为滚动近 7 天时长口径）**、**大表查询接口流式化（topic_query/keywordquery/sitequery/aigc_status 全表导出计数一致、`Database::each` 游标、aigc_status.json 缓存流式重写）**、**时间 helper 加载顺序与降级（head 提前加载、脚本缺失兜底、formatter `typeof` 守卫）**、**话题列表批量操作（勾选列渲染与计数、批量删除/批量编辑计数与边界：空选择/全空字段/不存在 id/管道符清洗/ctx_ids 数组形态）**、**显示时区（配置管理下拉默认北京时间、保存即时生效并注入 `window.sopsTimeZone`、非法时区拒绝、侧栏时钟同口径）**、**ctx_id 时区安全（epoch 前缀 ±1s、uniqid 熵段、全部门不走 `date()` 拼 id）**、**publish_edit 兼容字符串格式 text（保存名称/标题/描述/关键词预填完整值而非首字符 `value="c"`，保存后字符串/数组格式原样保持，article 表与 aigc 索引同口径）**、**publish_edit 链接名称绑定 slug（`post_slug` 直接读写 JSON `slug`，标签「链接名称」，savename 保留不动；article_new 仍用 `post_savename` 双写 savename+slug）**、**数据看板移动端适配（表格表内横向滚动与 class 列宽、小屏 KPI/图表字号高度、bar 图 y 轴限宽、resize 跨断点重绘）**、**移动端侧边栏抽屉（遮罩渲染、离屏抽屉 CSS、汉堡开合、父级只展开不跳转、Esc/遮罩/选中收起、断点复位与 aria 语义）**、**图片关键词搜索（Google buildUrl 参数/编码/逐条解析/gstatic 排除/跨条目贪婪匹配修复/i1 代理/limit 截断、Bing buildUrl/murl 解析与去重/entity 正则回退、Google→Bing 兜底 engine 契约、代理 setProxy/env 读取、端到端 fixture JSON 契约、400/403/405 分支、经代理 127.0.0.1:7890 实网返回真实图片；版权黑名单剔除、Content-Type 图片类型校验、并发 HEAD/Range GET 可访问性探测、validate stub 离线注入、validate=0 跳过、实网校验返回可外链图片）**、**网站历史档（完整迁移 wayback_tools 四步流程）**：站点管理下新增「网站历史档」（perm `site.view`，viewer/editor 可见）与「历史档配置」（perm `site.manage`，viewer 被拒）两个导航入口；**步骤1 查询历史名称**——CDX `matchType=domain&limit=150000&fl=timestamp,original,statuscode&filter=statuscode:200` 全量快照（JSON 失败回退按 14 位时间戳的文本解析）、仅首页且默认每年一个（可勾选关闭）、`curl_multi` 5 并发 + 70s 截止抓取 `<title>`（UTF-8 校验→GB18030/GBK 回退、≤100 字节记空响应、3 次退避重试、无 curl_multi 扩展回退顺序），结果以 AJAX JSON `{ok,html,count,elapsed}` 注入页面容器（保留站点 chrome），行内含显示时区格式化时间、带工具条回放链接（非 `id_`）、空态/错误态/域名净化/进度 Modal；域名输入框为可搜索 combobox——`#domain` 聚焦/输入即弹出站点联想（取 `siteops` 表 `domain`/`site_title` 去重、新→旧，按域名/站点名子串过滤最多 50 条，域名主行 + 站点名辅行，HTML 转义防注入），↑/↓ 移动高亮、Enter 选中（无高亮时 Enter 交给表单提交）、Esc 关闭、鼠标点击选中、失焦延时收起，手工录入完全不受影响，空站点表时退化为纯手工输入；**步骤2 归档基线**——点选记录后「选择为基线」写入隐藏 `archive_ts/archive_url/archive_domain` 并联动站点名与仓库名；**步骤3 创建仓库**——账号下拉自动回填用户名与 Token、Token 实时格式校验（`ghp_/gho_/ghu_/ghs_/ghr_/github_pat_` + ≥15 位）、预览（AJAX 返回占位符替换后的 README 与 GitHub 地址）、创建私有仓库→写入 README（`{{SITE_NAME}}/{{ARCHIVE_PAGE}}/{{ARCHIVE_DATE}}/{{ARCHIVE_URL}}/{{ARCHIVE_DOMAIN}}`）→按上传目录递归上传（跳过 `.DS_Store`）→记录 `created_repos.json`，缺基线/缺用户名/缺仓库名/Token 格式错/GitHub API 错误均整页提示；**步骤4 已创建仓库**列表（仓库链接/标题/归档日期/创建人/创建时间）；测试代理按钮对 CDX 发最小查询计时回显；GitHub API 支持 `$GLOBALS['__githubApiStub']` 离线注入（未覆盖请求即抛错）；**配置一律文件化不入库**（`ArchiveConfigService`：`Config::dataDir()/config/` 下 `proxy.json/accounts.json/templates.json/uploads.json/created_repos.json`（结构对齐 `wbm/config`，保存时保留 `api_token` 等未知顶层键）与项目根 `templates/*.md`，临时文件+rename 原子写、id 与模板文件名防路径穿越、缺文件回退默认代理预设与内置模板正文），【历史档配置】页可增删改代理预设（http/https/socks4/socks4a/socks5/socks5h 白名单、非法地址拒绝）、GitHub 账号（列表打码）、README 模板（默认标记 + 正文编辑器）与上传目录（base + 条目、路径禁 `..`、相对路径仅按 dataDir 解析不按 CWD 误传项目自身目录）与**原始 JSON 编辑器**（`?raw=<kind>` 展示并整份覆盖保存 `proxy/accounts/templates/uploads`，非法 JSON 或缺顶层键拒绝，便于直接粘贴 `wbm/config` 同款 JSON）；**`api/proxy.php` 脚本入库**（自 `wbm/proxy.php` 迁移，功能一致）：`X-API-Token` 头或 `token` 参数与 `proxy.json` 顶层 `api_token` 比对（文件缺失 500、未配置/不匹配 401），支持 `proxy=user:pass@host:port` 组合（无前缀默认 socks5h）、分离字段与 JSON body，scheme 白名单 `http/https/socks4/socks4a/socks5/socks5h`、IPv6 方括号、用户名/密码字符校验，幂等 upsert（id=`p-`+`sha256(scheme://user@host:port)` 前 12 位不含密码：同 url 原样返回不写盘、凭据轮换原位更新 id 不变、原子写入保留 `api_token`），响应打码密码；**实网验证**（`php -S` 内置服务器直连 `api/proxy.php`）：无 token / 错 token 分别返回 401 且不落盘，正确 token 组合写法新增返回打码 url 与 `p-<sha256 前12>` id，同 url 重复提交幂等 `updated:false` 不写盘，凭据轮换 `updated:true` 且 id 不变、文件原位更新 url，JSON body + GET `token` 亦可入库且保留 `api_token` 顶层键；【配置管理】原 DB 版 `wayback_proxy` 卡片与保存路由已移除，`WaybackService::proxy()` 仅读环境变量（WAYBACK_PROXY/APP_WAYBACK_PROXY/HTTPS_PROXY/https_proxy/HTTP_PROXY/http_proxy）+ 自动模式「代理优先→直连」重试，显式预设或手工录入强制走该代理；**超时兜底**：控制器 `set_time_limit(0)` + `max_execution_time=0` 放开 PHP 上限，curl 单次 15s/全量快照 45s/标题 25s/代理测试 25s、连接 ≤10s，网络不可达时优雅报错而非 fatal；**实网验证**：经代理 127.0.0.1:7890 查询 `www.sdwenkong.com` 返回 611 条 HTTP 200 快照（9.5s）、每年首页 11 条、并发标题与回放链接真实返回））

## 8. 升级与回滚

- **升级**：新代码已上线后，原入口仍为 `siteops.php`，URL 不变，无需改动脚本与其他页面
- **回滚**：将 `siteops.php` 恢复为 `siteops_legacy.php` 的内容（或在替换前 `cp siteops.php siteops_legacy.php` 保留旧版）即可
- **数据安全**：`sitedata.sqlite`、`sitebulkops/`、`siteops_setting.txt` 部署前建议整体备份

## 9. 常见问题

| 现象 | 处理 |
|---|---|
| 页面提示 `file global_config.php not found.` | 确认 PHP 入口与 `global_config.php` 同目录 |
| 上传报 `imgbb api key not configured` | 检查 `IMGBB_API_KEY` 环境变量与 `base.imgbb_api_key` 配置 |
| 上传全部失败 | 检查多 key 轮询计数文件 `var/imgbb_key_index` 是否可写、网络是否可达 imgbb |
| 上传报"图片超过服务器限制" | PHP 自身 `upload_max_filesize`（默认仅 2M）/`post_max_size`（默认 8M）低于应用 10MB 上限，需调大：`upload_max_filesize=20M`、`post_max_size=24M` 并重启 PHP-FPM |
| 上传提示"服务器返回异常 (HTTP xxx)" | 服务端返回了非 JSON（多为 PHP 错误/警告输出或 500 页），弹窗会附响应片段；同时检查 `php -m` 中 `curl`/`fileinfo` 扩展、PHP 版本（8.5 需移除已弃用的 `curl_close()` 等调用，本项目已兼容） |
| 上传报 `server error: Class "finfo" not found` | 服务器未装 `fileinfo` 扩展（常见于 Alpine/Docker 精简镜像）。已支持自动降级：`fileinfo` 缺失时用 PHP 内置 `getimagesize()` 检测（PNG/JPG/GIF/WebP 均可），无需安装扩展即可使用 |
| 上传提示"上传过程中出错，请重试" | 网络层失败（浏览器无法连接代理端点）。弹窗现会附带浏览器错误原因；若代理可达但超时，检查服务器出口网络/防火墙 |
| 表单提交提示 CSRF token invalid | 清浏览器 cookie（`siteops_uid`）后重新打开页面 |
| 登录后仍跳回登录页 | 服务器时间偏移导致 cookie 过期，或 `APP_AUTH_SECRET` 变更 |
| 测试目录残留 | `tests/tmp/` 可随时删除，测试会自建 |