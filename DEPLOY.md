# 敏感信息监测聚合平台 - 部署说明

## 一、环境要求

| 组件 | 最低版本 | 说明 |
|------|---------|------|
| PHP | 8.2+ | 需安装 `pdo_mysql` `openssl` `curl` `mbstring` 扩展 |
| MariaDB | 10.3+ | 或 MySQL 8.0+，字符集 utf8mb4 |
| Nginx | 1.18+ | 或 Apache，需支持 PHP-FPM |
| 操作系统 | Linux | 推荐 Rocky Linux / CentOS 8+ / Ubuntu 22.04+ |

### PHP 版本安全要求（依据 PHP 官方公告，2026-10）

1. **必须使用已修复严重 RCE 漏洞的 PHP 版本**：PHP 官方 2026-10-21 发布严重安全公告
   （CVSS 9.2，未认证远程代码执行），修复版本为 **8.2.35 / 8.3.36 / 8.4.27 / 8.5.12** 及以上，
   涉及 CVE-2026-109618、CVE-2026-109619。部署前用 `php -v` 确认版本不低于上述修复版本。
2. **PHP 8.2 分支将于 2026-12-31 结束安全支持（EOL）**，新部署建议直接选择 8.3+ 并规划升级至 8.4。
3. **`session.upload_progress` 必须关闭**（官方 2026-10-23 公告建议，CVE-2026-115560）：
   本项目已在 `includes/bootstrap.php` 中运行时强制关闭；同时建议在 `php.ini` 中
   设置 `session.upload_progress.enabled=0` 双重保险。
4. **php.ini 推荐加固**：`expose_php=Off`、`display_errors=Off`（本项目已运行时强制）、
   合理设置 `post_max_size` 与 `memory_limit`。

### PHP 必需扩展检查

```bash
php -m | grep -E 'pdo_mysql|openssl|curl|mbstring'
```

如果缺少，用 yum/dnf 安装：

```bash
# Rocky Linux / CentOS
sudo dnf install php-pdo php-mysqlnd php-openssl php-curl php-mbstring

# Ubuntu / Debian
sudo apt install php8.2-mysql php8.2-curl php8.2-mbstring
```

---

## 二、部署步骤

### 1. 上传项目

将整个项目目录上传到服务器，例如 `/var/www/html/github-secret-hunter/`。

```bash
# 方式一：scp 上传
scp -r ./github-secret-hunter root@<服务器IP>:/var/www/html/

# 方式二：rsync（排除 .git）
rsync -avz --exclude='.git' ./github-secret-hunter/ root@<服务器IP>:/var/www/html/github-secret-hunter/
```

### 2. 创建数据库

```bash
mysql -u root -p
```

```sql
CREATE DATABASE github_secret_hunter CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
EXIT;
```

### 3. 导入表结构

```bash
mysql -u root -p github_secret_hunter < /var/www/html/github-secret-hunter/sql/schema.sql
```

验证（应显示 8 张表）：

```bash
mysql -u root -p -e "USE github_secret_hunter; SHOW TABLES;"
```

### 4. 配置环境变量

```bash
cd /var/www/html/github-secret-hunter
cp .env.example .env
```

编辑 `.env`，至少修改以下三项：

```ini
DB_USER=你的数据库用户名
DB_PASS=你的数据库密码
APP_ENCRYPTION_KEY=用下面的命令生成
```

生成加密密钥：

```bash
ENCRYPTION_KEY=$(openssl rand -base64 32)
echo $ENCRYPTION_KEY
```

将输出的密钥填入 `APP_ENCRYPTION_KEY=` 后面。

### 5. 设置文件权限

```bash
sudo chown -R nginx:nginx /var/www/html/github-secret-hunter
sudo chmod -R 755 /var/www/html/github-secret-hunter
sudo chmod 640 /var/www/html/github-secret-hunter/.env
```

> 如果 PHP-FPM 运行用户是 `www-data`（Ubuntu/Debian），则把上面的 `nginx` 替换为 `www-data`。

### 6. 配置 Nginx

创建站点配置 `/etc/nginx/conf.d/github-secret-hunter.conf`：

```nginx
server {
    listen 80;
    server_name _;
    root /var/www/html/github-secret-hunter/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php-fpm/www.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # 禁止访问敏感文件
    location ~ /\.(env|git) {
        deny all;
    }
}
```

> PHP-FPM 的 sock 路径根据实际安装而定：
> - Rocky/CentOS：`/run/php-fpm/www.sock`
> - Ubuntu/Debian：`/run/php/php8.2-fpm.sock`

### 7. 启动服务

```bash
sudo systemctl start php-fpm
sudo systemctl start nginx
sudo systemctl enable php-fpm nginx
```

### 8. 验证

浏览器访问 `http://<服务器IP>/`，使用默认账号登录：

- 用户名：`admin`（若 .env 配置了 `ADMIN_USER`，则首次访问登录页时会自动改名为该值，用新用户名登录）
- 密码：`Admin@123456`

首次登录会强制要求修改密码。部署后可在「系统设置 → 管理员账号」中修改用户名（需原密码确认）。

---

## 三、功能概览

| 功能 | 入口 | 说明 |
|------|------|------|
| 敏感信息搜索 | 左侧菜单「搜索」 | 调用 GitHub Code Search API |
| 搜索任务管理 | 左侧菜单「搜索任务」 | 暂停 / 恢复 / 删除任务 |
| 搜索结果 | 左侧菜单「搜索结果」 | 分页(30/50/100/200/500)、右侧滚动条 |
| 结果导出 | 结果页右上角 | 支持 CSV / Excel / JSON |
| GitHub Token | 左侧菜单「GitHub Token」 | AES-256 加密存储 |
| 通知渠道 | 左侧菜单「通知渠道」 | 钉钉 / 企业微信 / 飞书 / 邮件 |
| 数据设置 | 左侧菜单「数据设置」 | 数据保留期设置 + 自动清理 |

### 数据保留期与自动清理

- 在「数据设置」页面配置保留天数（永久 / 7 / 30 / 90 / 180 / 365 / 730 天）
- 系统每 24 小时自动清理过期数据，无需配置系统 Cron
- 保存保留期时立即执行一次清理
- 设置为「永久保留」时自动清理关闭

### 后台分页搜索与增量复查

- 搜索提交后立即返回，任务在后台自动分页抓取全部命中结果（无需保持页面打开）
- 翻页请求按 `.env` 中 `GITHUB_SEARCH_INTERVAL`（默认 7 秒）间隔执行，遵守 GitHub 速率限制；
  被限流（403/429）时按 GitHub 返回的 `Retry-After` / Reset 时间自动退避
- **GitHub Search API 单个查询最多返回前 1000 条结果**（100 条/页 × 10 页），超出部分 GitHub 不提供
- 任务完成后按 `RECHECK_INTERVAL_MINUTES`（默认 30 分钟）自动复查：只把**新增**结果入库并推送通知，
  已入库结果不会重复推送（按 任务+URL 唯一索引去重）
- 「搜索任务」页可暂停/恢复/立即复查/删除任务；无系统 Cron，调度由页面请求自动触发

---

## 四、数据库表说明

| 表名 | 说明 |
|------|------|
| `users` | 用户账号（bcrypt 哈希存储） |
| `github_tokens` | GitHub Token（AES-256-CBC 加密存储） |
| `search_tasks` | 搜索任务（含暂停状态 `paused` 字段） |
| `search_results` | 搜索命中结果 |
| `notification_channels` | 通知渠道配置（加密存储） |
| `notification_logs` | 通知发送日志 |
| `audit_logs` | 审计日志 |
| `app_settings` | 应用设置（保留期、清理时间等） |

---

## 五、安全说明

- 密码：bcrypt(cost=12)，首次登录强制改密
- 防爆破：连续失败 5 次锁定 15 分钟（可在 .env 调整）
- 会话：不活动超时自动登出（.env 中 `SESSION_LIFETIME` 配置，默认 8 小时）
- Token/通知配置：AES-256-GCM 认证加密存储（兼容解密历史 AES-256-CBC 数据）
- SSRF 防护：出站请求域名白名单（.env 中 `SSRF_ALLOWED_HOSTS` 配置），含 Webhook 与 SMTP 主机
- CSRF 防护：HMAC-SHA256 Token
- XSS 防护：所有输出经 `htmlspecialchars` 转义，前端渲染 GitHub API 数据亦做 HTML 转义
- 导出防公式注入：CSV/Excel 导出对 `= + - @` 开头的外部数据前置单引号
- `.env` 文件已被 `.gitignore` 排除，不会提交到版本控制

---

## 六、常见问题

### 访问页面报 500

1. 检查 `.env` 是否存在且配置正确
2. 查看错误日志：`sudo tail -30 /var/log/php-fpm/error.log`
3. 临时开启调试：将 `.env` 中 `APP_DEBUG` 改为 `true`，重启 PHP-FPM

### 数据库连接失败

确认 `.env` 中的 `DB_HOST` `DB_PORT` `DB_NAME` `DB_USER` `DB_PASS` 与实际一致。

### GitHub 搜索无结果

1. 在「GitHub Token」页面添加有效的 Personal Access Token
2. 确认 Token 有 `public_repo` 权限
3. GitHub Code Search API 限制：未认证 10 次/分钟，认证后 30 次/分钟

### 邮件通知发送失败（QQ 邮箱）

QQ 邮箱需使用**授权码**而非登录密码。开启路径：QQ邮箱 → 设置 → 账户 → POP3/SMTP 服务 → 开启 → 获取授权码。

---

## 七、重新部署（清空旧数据）

如需模拟全新部署环境：

```bash
# 1. 停止服务
sudo systemctl stop php-fpm nginx

# 2. 删除项目目录
sudo rm -rf /var/www/html/github-secret-hunter

# 3. 删除并重建数据库
mysql -u root -p -e "DROP DATABASE IF EXISTS github_secret_hunter; CREATE DATABASE github_secret_hunter CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 4. 重新上传项目 → 创建 .env → 导入 schema → 启动服务
# 按上面第二步开始重做即可
```
