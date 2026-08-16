# 发布指引：用户与角色权限（RBAC）

> 适用范围：本版本新增「用户管理 / 角色权限（RBAC）」模块。
> 变更内容：新增 5 张表、种子数据（权限/角色/映射）、首次启动自动创建初始 admin 用户。

---

## 1. 变更总览

| 项目 | 说明 |
|---|---|
| 新增表 | `users`、`roles`、`permissions`、`user_roles`、`role_permissions` |
| 种子数据 | 8 项权限、3 个内置角色（admin/editor/viewer）、角色-权限映射、初始 admin 用户 |
| 数据库文件 | `sitedata.sqlite`（默认 `APP_PATH/sitedata.sqlite`，可用 `APP_DB_FILE` 覆盖） |
| 新页面 | `users.php` / `user_edit.php` / `roles.php` / `role_edit.php` |

**自动迁移说明**：应用启动首次访问数据库时，`Database::migrate()` 会执行
`CREATE TABLE IF NOT EXISTS`（幂等）并自动写入种子数据。**无需手工执行 SQL**，
下方 SQL 供离线建库 / 手工核对使用。

---

## 2. 表结构（SQL）

```sql
CREATE TABLE IF NOT EXISTS "users" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    "username" VARCHAR UNIQUE NOT NULL,
    "password_hash" VARCHAR NOT NULL,
    "display_name" VARCHAR DEFAULT '',
    "status" VARCHAR DEFAULT 'active',
    "created_at" DATETIME,
    "updated_at" DATETIME,
    "last_login_at" DATETIME
);

CREATE TABLE IF NOT EXISTS "roles" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    "name" VARCHAR UNIQUE NOT NULL,
    "description" VARCHAR DEFAULT ''
);

CREATE TABLE IF NOT EXISTS "permissions" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    "code" VARCHAR UNIQUE NOT NULL,
    "name" VARCHAR NOT NULL,
    "description" VARCHAR DEFAULT ''
);

CREATE TABLE IF NOT EXISTS "user_roles" (
    "user_id" INTEGER NOT NULL,
    "role_id" INTEGER NOT NULL,
    PRIMARY KEY ("user_id", "role_id")
);

CREATE TABLE IF NOT EXISTS "role_permissions" (
    "role_id" INTEGER NOT NULL,
    "permission_id" INTEGER NOT NULL,
    PRIMARY KEY ("role_id", "permission_id")
);

CREATE TABLE IF NOT EXISTS "app_configs" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    "config_key" VARCHAR UNIQUE NOT NULL,
    "config_value" TEXT NOT NULL DEFAULT '',
    "description" VARCHAR DEFAULT '',
    "updated_at" DATETIME,
    "updated_by" VARCHAR DEFAULT ''
);
```

> `app_configs` 为「配置管理」模块（`config_list.php` / `config_edit.php`）的业务字典存储。
> 首次迁移自动从 `global_config.php` 初始化 8 组字典（languages/countries/categories/statuses/series/pubdir/themetype/sitetype），
> 之后 `Config::all()` 对字典键采用「数据库有值则用数据库、无则回退文件」策略；
> 部署/密钥类配置（base.*、gitaccount、gitserver、imgbb key）仍保留在 `global_config.php`（密钥以 env 优先）。

---

## 3. 种子数据（自动写入，供核对）

### 3.1 权限（permissions）

| code | name |
|---|---|
| `site.view` | 站点-查看 |
| `site.manage` | 站点-录入编辑 |
| `keyword.view` | 关键词-查看 |
| `keyword.manage` | 关键词-录入编辑 |
| `topic.view` | 话题-查看 |
| `topic.manage` | 话题-录入编辑 |
| `report.view` | 报表-查看 |
| `user.manage` | 系统-用户角色管理 |
| `config.manage` | 系统-配置管理 |

### 3.2 内置角色（roles）

| name | 说明 | 权限 |
|---|---|---|
| `admin` | 超级管理员 | 全部 8 项 |
| `editor` | 录入编辑 | site/keyword/topic 的 view+manage、report.view |
| `viewer` | 只读 | site/keyword/topic/report 的 view |

> 内置角色名 `admin`/`editor`/`viewer` 在界面上不可删除、不可修改名称。

---

## 4. 初始 admin 用户（密码配置）

### 4.1 自动创建规则

首次启动时（`users` 表为空）且配置了认证环境变量，则自动创建初始 admin：

```
APP_AUTH_USER=admin
APP_AUTH_PASSWORD=<明文密码 或 password_hash 格式>
```

- 明文密码：自动 `password_hash()` 后入库
- `$2y$...` / `$2a$...` / `$argon2...` 开头：按已加密密码直接入库（推荐生产使用）
- 自动授予 `admin` 角色

Apache：

```apache
SetEnv APP_AUTH_USER admin
SetEnv APP_AUTH_PASSWORD '$2y$10$...'
```

Nginx：

```nginx
fastcgi_param APP_AUTH_USER admin;
fastcgi_param APP_AUTH_PASSWORD '$2y$10$...';
```

生成 bcrypt 哈希：

```bash
php -r "echo password_hash('你的密码', PASSWORD_DEFAULT), PHP_EOL;"
```

### 4.2 已有库（users 非空）时的处理

种子逻辑在 `users` 表非空时**跳过**，不会覆盖现有用户。
已部署生产库（`sitedata.sqlite`）当前初始 admin 密码为 `Pass1234`，
**上线后请立即修改密码**（登录后 → 用户管理 → 编辑 → 重置密码），
或手工执行：

```sql
-- 生产库手工重置 admin 密码（先在本机生成哈希）
UPDATE "users" SET
    "password_hash" = '$2y$10$...生成的新哈希...',
    "updated_at" = datetime('now')
WHERE "username" = 'admin';
```

### 4.3 其他环境变量

| 变量 | 说明 |
|---|---|
| `APP_AUTH_SECRET` | 登录 cookie 签名密钥（缺省取 `APP_CSRF_SECRET`） |
| `APP_CSRF_SECRET` | CSRF 签名密钥（缺省自动生成并持久化到 `var/csrf_secret`） |

---

## 5. 部署步骤（checklist）

1. 备份数据库：`cp sitedata.sqlite sitedata.sqlite.bak.$(date +%Y%m%d)`
2. 发布代码（PHP 入口与 `css/`、`js/`、`app/`、`views/` 同目录）
3. 配置认证环境变量（见第 4 节），重启 PHP-FPM
4. 打开任意管理页面触发迁移；确认 `users`、`roles` 等 5 张表已建且 `permissions` 有 8 行
5. 登录验证：初始 admin → 确认角色管理（`roles.php`）可见全部 3 个内置角色
6. 修改初始密码（见 4.2）
7. 回归验证：`php tests/run_tests.php`（应全部通过，当前基线 270 PASS / 0 FAIL）

---

## 6. 权限保护规则（新行为说明）

- 用户删除保护：不能删除自己、不能删除最后一个 `admin` 角色用户；`admin` 角色用户不可被删除
- 角色删除保护：内置角色 `admin`/`editor`/`viewer` 不可删；已分配给用户的角色不可删
- 报表/话题列表删除（站点/关键词/话题）均需对应 `*.manage` 权限 + CSRF 校验

---

## 7. gitserver IP 白名单（免鉴权访问）

配置来源：`global_config.php` 的 `gitserver` 数组（值为服务器 IP），**不入数据库**。

| 请求来源 | 业务接口（站点/关键词/话题/报表，含 GET/POST） | 系统管理（用户/角色/配置管理） |
|---|---|---|
| 白名单 IP（gitserver 服务器） | ✅ 免登录、免鉴权（`site/keyword/topic/report` 全部权限） | ❌ 必须真实登录（`user.manage`/`config.manage` 仍校验） |
| 其他 IP | 需登录 + 对应权限 | 需登录 + 对应权限 |

实现说明：
- `Security::isGitServerIp()` 依据 `REMOTE_ADDR`（兼容 `::ffff:` IPv6 映射前缀）匹配 `gitserver` IP 列表
- `Security::requirePermission()`：白名单 IP 且非系统权限（`user.manage`/`config.manage`）→ 直接放行；系统权限 → 照常校验（未登录抛 403）
- 白名单 IP 在业务控制器跳过登录跳转；系统管理控制器（User/Config）**不**参与白名单放行
- POST 业务操作白名单 IP 同样受 CSRF 保护（无 uid cookie 的自动化调用不受影响）
