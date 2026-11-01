# 敏感信息监测聚合平台 - Rocky 9 公网云服务器部署教程（详细版）

> 适用：阿里云 / 腾讯云 / 华为云 / AWS 等，Rocky Linux 9.x，全程 root 执行
> 每一步都附「验证方法」和「常见报错」。★ 标记的是实测踩过的坑。

---

## 〇、APP_URL 能不能用公网 IP？

**可以**，它只用于拼接通知里的「查看全部命中结果」链接：

```ini
APP_URL=http://123.45.67.89        # 有域名用 https://your.domain
```

- 裸 IP 拿不到 Let's Encrypt 证书（LE 不给纯 IP 签发），只能 HTTP 或自签证书；
  公网 HTTP 意味着登录密码和 GitHub Token 明文传输——**测试可以，长期用建议注册域名上 HTTPS**
- `.env` 每次请求重新加载，改完即时生效，无需重启

---

## 一、云服务器与安全组

购买 Rocky Linux 9.x 实例（2C2G 起步即可），在**云控制台安全组**放行：

| 方向 | 端口 | 来源 | 用途 |
|------|------|------|------|
| 入 | 22/tcp | 你的 IP/32 | SSH |
| 入 | 80/tcp | 0.0.0.0/0 | HTTP |
| 入 | 443/tcp | 0.0.0.0/0 | HTTPS |
| 入 | 3306 | **不放行** | MySQL 绝不对公网开放 |

SSH 密钥登录并关闭密码登录（云控制台注入密钥后）：

```bash
sed -i 's/^#*PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
systemctl restart sshd
```

## 二、系统初始化

```bash
# 2.1 更新系统（几分钟）
dnf update -y

# 2.2 时区
timedatectl set-timezone Asia/Shanghai
timedatectl | grep Time    # 验证：Time zone: Asia/Shanghai (CST, +0800)

# 2.3 国内云换 USTC 镜像源（海外服务器跳过这步！）
sed -i -e 's|^mirrorlist=|#mirrorlist=|g' \
       -e 's|^#baseurl=http://dl.rockylinux.org/$contentdir|baseurl=https://mirrors.ustc.edu.cn/rocky|g' \
       /etc/yum.repos.d/rocky*.repo
dnf clean all
dnf makecache    # 验证：无 "Cannot download repomd.xml" 报错
```

> ★ 坑：如果 `dnf` 卡在 "Cannot download repomd.xml: All mirrors were tried"，
> 说明源不可达。先 `curl -m 8 -s -o /dev/null -w '%{http_code}' https://mirrors.ustc.edu.cn/rocky/9/BaseOS/x86_64/os/repodata/repomd.xml`
> 返回 200 才说明源可用（Rocky 9.8 的路径是 /rocky/9/，阿里云源对 9.8 会 404，用 USTC 或南大）。

## 三、安装 LNMP（逐条详解）

### 3.1 查看可用的 PHP 版本流

```bash
dnf module list php
```

输出类似：

```
Rocky Linux 9 - AppStream
Name Stream    Profiles                Summary
php  7.2 [d]   common [d], devel, minimal PHP scripting language
php  7.3       common [d], devel, minimal PHP scripting language
php  7.4       common [d], devel, minimal PHP scripting language
php  8.0       common [d], devel, minimal PHP scripting language
php  8.2       common [d], devel, minimal PHP scripting language
```

**必须启用 8.2 流**（默认流可能还是 7.x/8.0，直接 install 会装到旧版——本项目要求 ≥ 8.2）：

```bash
dnf module enable php:8.2 -y
dnf module list php | grep 8.2    # 验证：8.2 前面有 [e] 标记（enabled）
```

### 3.2 一次性安装四个组件

```bash
dnf install -y nginx mariadb-server php-fpm php-cli php-mysqlnd php-mbstring
```

各组件作用：

| 包 | 作用 |
|----|------|
| nginx | Web 服务器，处理静态文件 + 转发 PHP 请求 |
| mariadb-server | MySQL 数据库（存放任务/结果/用户）|
| php-fpm | PHP 进程管理器（FastCGI），真正执行项目代码 |
| php-cli | 命令行 php（本教程里 `php -l`、`php -v` 都靠它）|
| php-mysqlnd | PHP 的 MySQL 驱动（pdo_mysql），**缺了会报 "could not find driver"** |
| php-mbstring | 多字节字符串扩展（项目Validator用 mb_substr），缺了登录报 500 |

> openssl / curl 扩展已内置在 php-common 里，无需单独装。
> 若版本低于 8.2.35（老镜像），接 Remi 源升级：
> ```bash
> dnf install -y https://rpms.remirepo.net/enterprise/remi-release-9.rpm
> dnf module reset php -y && dnf module enable php:remi-8.2 -y
> dnf update -y 'php*'
> ```

### 3.3 验证 PHP 版本与扩展（关键！）

```bash
php -v | head -1
```

**必须看到 `PHP 8.2.35` 或更高**（8.2.29 及以下有官方公告的未修复 RCE，部署不合规）：

```
PHP 8.2.35 (cli) (built: Oct 19 2026 07:29:52) (NTS gcc x86_64)
```

```bash
php -m | grep -E 'pdo_mysql|openssl|curl|mbstring'
```

四行都必须出现：

```
curl
mbstring
pdo_mysql
openssl
```

### 3.4 启动并设置开机自启

```bash
systemctl enable --now mariadb php-fpm nginx
```

逐个验证：

```bash
systemctl is-active mariadb php-fpm nginx
# 期望三行都是 active

ss -tlnp | grep -E ':80|:3306'
# 期望：nginx 监听 :80，mariadb 监听 127.0.0.1:3306（不是 0.0.0.0:3306）

curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1/
# 期望：403 或 200（nginx 默认页），说明 Web 服务通了
```

### 3.5（可选但建议）数据库安全初始化

```bash
mysql_secure_installation
# 全部回车用 unix_socket 认证 → 设置 root 密码(可跳过) → 移除匿名用户 Y
# → 禁止 root 远程 Y → 移除 test 库 Y → 重载 Y
```

## 四、创建数据库与账号

```bash
mysql <<'SQL'
CREATE DATABASE github_secret_hunter CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'gsh'@'localhost' IDENTIFIED BY '这里换成强密码Abc123!';
GRANT ALL PRIVILEGES ON github_secret_hunter.* TO 'gsh'@'localhost';
FLUSH PRIVILEGES;
SQL
```

验证：

```bash
mysql -u gsh -p -e "SHOW DATABASES;"
# 期望：能看到 github_secret_hunter
# 记下这个密码，.env 的 DB_PASS 用它
```

> 注意：账号是 `gsh@localhost`（仅本机），配合 MariaDB 默认只监听 127.0.0.1，
> 数据库不暴露公网。**不要**创建 `gsh@%`。

## 五、上传代码

**方式一：从 Windows 用 scp（PowerShell）**

```powershell
scp -r F:\tianfa\github-secret-hunter root@123.45.67.89:/var/www/html/
```

**方式二：服务器上用 git**

```bash
dnf install -y git
cd /var/www/html && git clone <仓库地址> github-secret-hunter
```

验证目录结构（public 是网站根，.env 不在代码里是正常的）：

```bash
ls /var/www/html/github-secret-hunter
# 期望：config  includes  public  sql  DEPLOY.md  DEPLOY_CLOUD.md  .env.example ...

ls /var/www/html/github-secret-hunter/public
# 期望：index.php  dashboard.php  api  partials  .htaccess ...
```

导入数据库：

```bash
mysql -u gsh -p github_secret_hunter < /var/www/html/github-secret-hunter/sql/schema.sql

# 验证：8 张表 + 1 个管理员
mysql -u gsh -p -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='github_secret_hunter';"
# 期望：8
mysql -u gsh -p -N -e "SELECT username FROM github_secret_hunter.users;"
# 期望：admin
```

## 六、配置 .env

```bash
cd /var/www/html/github-secret-hunter
cp .env.example .env

# 生成加密密钥（复制输出值）
openssl rand -base64 32
```

编辑 `.env`，公网环境必须逐项确认：

```ini
# ---- 数据库（第四步的值）----
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=github_secret_hunter
DB_USER=gsh
DB_PASS=这里换成第四步的强密码

# ---- 加密密钥（openssl rand -base64 32 的输出，勿用示例值！）----
APP_ENCRYPTION_KEY=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx=

# ---- 环境：公网必须 production + false ----
APP_ENV=production
APP_DEBUG=false

# ---- 对外地址：通知里的结果页链接用 ----
APP_URL=http://123.45.67.89

# ---- 出站白名单：按需增删，改完即时生效 ----
SSRF_ALLOWED_HOSTS=api.github.com,oapi.dingtalk.com,qyapi.weixin.qq.com,open.feishu.cn,open.larksuite.com,smtp.qq.com,smtp.163.com

# ---- 后台分页与复查 ----
GITHUB_SEARCH_INTERVAL=7
RECHECK_INTERVAL_MINUTES=30

# ---- 登录防爆破 ----
LOGIN_MAX_ATTEMPTS=5
LOGIN_LOCK_MINUTES=15

# ---- 初始管理员用户名（可选）----
# 首次访问登录页时自动把默认 admin 改名为该值；部署后可在「系统设置 → 管理员账号」修改
ADMIN_USER=admin

# ---- 会话超时（秒）----
SESSION_LIFETIME=28800
```

## 七、★ PHP-FPM 配置（三个必踩坑）

### 7.1 FPM 运行身份改为 nginx（默认是 apache）

```bash
sed -i -e 's/^user = .*/user = nginx/' \
       -e 's/^group = .*/group = nginx/' \
       -e 's/^listen.acl_users.*/listen.acl_users = nginx/' \
       /etc/php-fpm.d/www.conf

# 验证（应输出 nginx nginx 和 listen.acl_users = nginx）：
grep -E '^(user|group|listen.acl_users)' /etc/php-fpm.d/www.conf
```

### 7.2 ★ session 目录属主（不改 = 登录静默失效！）

Rocky 的 session 目录默认属于 `root:apache`，FPM 换成 nginx 后写不进去，
表现为：**登录永远提示失败、CSRF 永远校验不通过，但页面显示正常 200**——最难排查的坑：

```bash
chown nginx:nginx /var/lib/php/session /var/lib/php/wsdlcache
ls -ld /var/lib/php/session
# 期望：drwxrwx--- 2 nginx nginx ...
```

### 7.3 限流目录 + php.ini 加固

```bash
mkdir -p /var/lib/php/tmp
chown nginx:nginx /var/lib/php/tmp
chmod 700 /var/lib/php/tmp

cat > /etc/php.d/99-gsh-hardening.ini <<'INI'
; PHP 官方 2026-10-23 公告建议关闭（CVE-2026-115560 RCE 向量）
session.upload_progress.enabled = 0
expose_php = Off
display_errors = Off
; 应用的限流文件目录（Security::rateLimit 写入 sys_get_temp_dir()）
sys_temp_dir = /var/lib/php/tmp
INI

systemctl restart php-fpm
systemctl is-active php-fpm    # 期望 active
```

## 八、nginx 站点配置

### 8.1 去掉默认站点（避免 80 端口 default_server 冲突）

```bash
# 用 python 移除 nginx.conf 中 http{} 内的默认 server 块
python3 - <<'PY'
import re
p = '/etc/nginx/nginx.conf'
s = open(p).read()
s2 = re.sub(r'\n    server \{.*?\n    \}\n', '\n', s, flags=re.S)
open(p, 'w').write(s2)
print('removed' if s2 != s else 'no default block')
PY
```

### 8.2 站点配置

```bash
cat > /etc/nginx/conf.d/gsh.conf <<'NGINX'
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

    # 禁止访问 .env / .git
    location ~ /\.(env|git) {
        deny all;
    }
}
NGINX

nginx -t
# 期望：syntax is ok / test is successful
systemctl restart nginx
```

### 8.3 HTTPS（有域名时）

```bash
dnf install -y certbot python3-certbot-nginx
certbot --nginx -d your.domain        # 自动改 nginx 配置并续期
```

然后给 443 的 server 块里 `location ~ \.php$` **加一行**（★ 不加则 HTTPS 下
`session.cookie_secure` 不生效、Cookie 走明文标记）：

```nginx
fastcgi_param HTTPS on;
```

并把 `.env` 的 `APP_URL` 改为 `https://your.domain`。

## 九、文件权限

```bash
chown -R nginx:nginx /var/www/html/github-secret-hunter
chmod -R 750 /var/www/html/github-secret-hunter
chmod 640 /var/www/html/github-secret-hunter/.env

ls -la /var/www/html/github-secret-hunter/.env
# 期望：-rw-r----- 1 nginx nginx
```

## 十、SELinux 与防火墙

```bash
# SELinux（getenforce 输出 Disabled 则跳过本段）
getenforce
setsebool -P httpd_can_network_connect_db 1     # 允许 PHP 连 MySQL
restorecon -R /var/www/html

# firewalld（云安全组是另一层，两边都要放行）
systemctl enable --now firewalld
firewall-cmd --permanent --add-service=http
firewall-cmd --permanent --add-service=https
firewall-cmd --reload
```

## 十一、最终验证

```bash
php -v | head -1                                    # ≥ 8.2.35
systemctl is-active nginx php-fpm mariadb           # 三个 active
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1/   # 200
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1/.env.example   # 403
```

浏览器打开 `http://公网IP/`，按顺序：

1. 登录 `admin / Admin@123456` → 强制改密 → **立即设强密码**
2. 「GitHub Token」页添加 Token → 「敏感信息搜索」发起搜索 → 任务页看后台分页进度
3. 「通知渠道」逐个「发送测试」确认钉钉/飞书/邮件可达

## 十二、常见报错速查

| 现象 | 原因 | 处理 |
|------|------|------|
| 登录一直失败但页面正常 | ★ session 目录属主不对（7.2） | `chown nginx:nginx /var/lib/php/session` |
| 页面 500 / 空白 | `.env` 缺失或 DB 配置错 | 看 `/var/log/php-fpm/www.log`；临时开 `APP_DEBUG=true` 排查 |
| "could not find driver" | 缺 php-mysqlnd | `dnf install php-mysqlnd && systemctl restart php-fpm` |
| 测试推送报"不在出站白名单" | 新 SMTP/机器人域名没加白 | 加到 `SSRF_ALLOWED_HOSTS`，即时生效 |
| 测试推送显示成功但没收到 | 飞书加签错误等"假成功"（本项目已修复）；或机器人关键词模式不匹配 | 确认机器人安全设置用「加签」；看发送日志的 response 字段 |
| 搜索一直 pending | 没有页面请求触发 worker | 随便刷新任意页面即可推进；或查任务 error_msg 是否 Token 无效 |
| 任务频繁"速率限制"退避 | 翻页间隔太小或多任务并发 | 调大 `GITHUB_SEARCH_INTERVAL`（≥7）|
| 502 Bad Gateway | php-fpm 没起或 sock 路径不对 | `systemctl status php-fpm`；核对 `/run/php-fpm/www.sock` 存在 |

## 十三、上线后安全加固清单

- [ ] 立即修改默认管理员密码
- [ ] `APP_DEBUG=false`、`.env` 640 权限
- [ ] HTTPS 上线（裸 IP 仅限临时测试）
- [ ] SSH 密钥登录、安全组限源、可选 fail2ban
- [ ] MariaDB 只监听 127.0.0.1、安全组不放 3306
- [ ] 每日备份：`crontab -e` 加
  `0 3 * * * mysqldump -u gsh -p'密码' github_secret_hunter | gzip > /root/backup/gsh_$(date +\%F).sql.gz`
- [ ] 定期 `dnf update` 跟进 PHP 安全公告
