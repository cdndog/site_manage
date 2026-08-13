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
- **提交**：POST 落库（按 domain upsert，保留原 ctx_id）、写 `sitebulkops/{ctx_id}.json` 备份、全量导出 `siteops_setting.txt`、追加审计日志
- **接口兼容**：`setupNum=ckeditorFormated` 是提交标志；无 cookie 的请求视为 API（`text2database_merge.sh` 等脚本回灌不受 CSRF/登录影响）
- **CSRF 防护**：浏览器请求强制校验签名 token（表单隐藏域 / 上传用 `X-CSRF-Token` 头）
- **可选登录**：设置 `APP_AUTH_USER` + `APP_AUTH_PASSWORD` 后启用，签名 cookie 12 小时有效
- **图片上传**：前端不再持有 imgbb key，统一经 `imgbb_proxy.php` 服务端代理（限 10MB、仅 image/*）
- **错误处理**：异常统一渲染 500 错误页并写入 PHP error log，不再白屏

## 6. 外部集成契约（勿破坏）

1. **POST 参数**（`text2database_merge.sh` 跨服回灌）：`post_uuid / post_gitname / post_gitaccount / post_domain / post_sitedeploy / post_sitehostip / post_lang / post_sitetype / post_themename / post_themetype / post_status / post_sitetitle / post_description / post_sitelogo / post_keyword / post_sns_id / post_topnavmenus / post_sitedir / local_deploy / local_hostip / setupNum=ckeditorFormated / post_json`
2. **导出文件** `siteops_setting.txt`，每行 12 列（固定顺序）：
   `ctx_id|git_name|git_account|status|theme_type|languages|domain|sns_id|topnav_menus|site_title|site_subtitle|json`
3. **导出文件** `keyword_monitor_list.txt`，每行 7 列（固定顺序）：
   `ctx_id|keyword|status|git_name|pubdir|lang|json`
4. **下游消费者**：`seo_report.php`（报表）、`sitequery.php`（status=done + json 展开）、`topicedit.php` / `topicops.php`（下拉）、`keywordops.php`（站点下拉）等
5. **备份文件**：`sitebulkops/{ctx_id}.json`（siteops 表 json 列）、`keywordmonitor/{ctx_id}.json`（keywordmonitorlist 表 json 列）

## 7. 功能测试

```bash
php tests/run_tests.php
```

- 结果示例：`PASS: 136  FAIL: 0`
- 测试使用独立沙箱（`tests/tmp/` 下的临时数据库与数据目录），不影响生产数据
- 覆盖：默认表单、入库/更新（upsert 保留 ctx_id）、管道符清洗、HTML 转义、导出 12 列、eid 回填、SQL 注入 payload、空 domain、无效 POST、merge 脚本契约、CSRF 全部分支、登录开关、imgbb 校验与轮询、**imgbb 异常兜底（超限/网络失败/服务器异常均返回 JSON）**、关键词录入（单条/批量/upsert/CSRF/备份/7 列导出）、报表三视图（wordlist/sitelist/relateword 解析与空态）、header/footer 模块、审计日志、错误页

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