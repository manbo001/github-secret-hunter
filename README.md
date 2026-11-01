# 敏感信息监测聚合平台

在自有资产和授权范围内，持续监测代码托管平台上泄露的 Access Key、Token、密码等敏感信息。

同一套关键词，同时跑 GitHub 代码搜索和 Gitee、GitCode 的仓库线索搜索，结果统一入库、去重、告警。单机部署，一个 PHP 站点加一个 MySQL 就能跑，后台任务随页面请求触发，不需要 Cron。

## 功能

- **多平台聚合搜索**：GitHub 代码搜索（支持 `filename:`、`extension:` 等限定符与代码片段命中）；Gitee / GitCode 按仓库名与描述做线索搜索
- **后台分页抓取**：任务提交后后台逐页抓取，翻页间隔自动遵守平台限速，被限流时按平台建议时间退避
- **令牌负载均衡**：同一用户配置多个 GitHub Token 时按最久未用轮转，翻页速度等效叠加各令牌的搜索配额
- **大查询自动拆分**：GitHub / Gitee 单次查询只能拿前 1000 条结果，命中超过 1000 时自动按 `language` 切分子查询，超大的切片再按文件 `size` 细分，结果合并去重入库
- **仓库更新监控**：对指定仓库定期拉取提交，发现新提交即入库并按需推送；令牌失效支持页面上直接换绑
- **通知渠道**：钉钉、企业微信、飞书、SMTP 邮件；搜索告警与仓库监控模板分开配置
- **结果导出**：CSV / Excel / JSON，自动处理 CSV 公式注入
- **其他**：数据保留期自动清理、登录防爆破锁定、会话不活动超时可配、亮暗主题切换、站点标题与图标自定义

## 环境要求

- PHP 8.2+（php-fpm，需 pdo_mysql、curl、openssl、mbstring）
- MySQL 5.7+ / MariaDB 10.3+
- nginx 或 Apache（任意能跑 PHP-FPM 的环境均可，支持 SamWaf 等反代）

三个平台中至少有一个有效 Token 才能发起对应平台的搜索。

## 快速开始

```bash
git clone https://github.com/manbo001/github-secret-hunter.git
cd github-secret-hunter

# 1. 配置环境变量
cp .env.example .env
vi .env    # 至少填 DB_USER / DB_PASS / APP_ENCRYPTION_KEY，可顺带配置 ADMIN_USER

# 2. 建库并导入表结构
mysql -u root -p -e "CREATE DATABASE github_secret_hunter CHARACTER SET utf8mb4;"
mysql -u root -p github_secret_hunter < sql/schema.sql

# 3. 权限与服务
chown -R www-data:www-data .
chmod 640 .env
systemctl restart php-fpm nginx
```

浏览器访问站点，用 `admin`（或 `.env` 中 `ADMIN_USER` 指定的用户名）/ `Admin@123456` 登录，首次登录强制修改密码。

详细步骤、常见坑（PHP-FPM 运行身份、时区、反代配置）见 [DEPLOY.md](DEPLOY.md)、[DEPLOY_CLOUD.md](DEPLOY_CLOUD.md)、[DEPLOY_SAMWAF.md](DEPLOY_SAMWAF.md)。

## 常用配置（.env）

| 配置项 | 说明 | 默认 |
|---|---|---|
| `APP_ENCRYPTION_KEY` | AES-256 密钥，加密存储平台 Token，更换后旧数据不可解密 | 必填 |
| `ADMIN_USER` | 部署时指定初始管理员用户名，替代默认 admin | admin |
| `APP_TIMEZONE` | PHP 时区，需与 MySQL 一致，否则日期统计错位 | Asia/Shanghai |
| `GITHUB_SEARCH_INTERVAL` | 翻页请求最小间隔（秒），过小会触发平台限流 | 7 |
| `RECHECK_INTERVAL_MINUTES` | 任务完成后自动复查新增的间隔（分钟），0 关闭 | 30 |
| `SSRF_ALLOWED_HOSTS` | 出站请求域名白名单（平台 API 与通知渠道域名） | 平台 + 常用通知域名 |

部署后的运行时配置（数据保留期、会话超时、GitHub 代理、站点标题图标等）在「系统设置」页面修改，优先级高于 .env。

## 目录结构

```
├── config/          # 应用与数据库配置（从 .env 读取，不硬编码密钥）
├── includes/        # 核心类：认证、安全、平台适配器、后台任务执行器、通知
├── public/          # Web 入口页面、静态资源、favicon 端点
├── sql/schema.sql   # 全量建表脚本（含默认数据与初始管理员）
└── DEPLOY*.md       # 部署文档
```

## 安全设计

- 全站预处理语句，无字符串拼接 SQL；输出统一转义；CSRF 全覆盖
- SSRF 出站白名单，仅允许访问配置内的域名
- Token、通知配置 AES-256-GCM 加密入库，密码 bcrypt(cost=12)
- 登录防爆破锁定，改密后其他会话自动失效
- 后台任务在页面响应后执行，不阻塞请求；错误全程审计日志

## 免责声明

本工具仅用于**授权范围内**的安全审计与自有资产的敏感信息监测。

使用者需自行确保遵守目标平台（GitHub / Gitee / GitCode）的服务条款及相关法律法规，不得用于未授权的第三方数据收集。因使用本工具产生的任何后果由使用者自行承担。

## License

[MIT](LICENSE)
