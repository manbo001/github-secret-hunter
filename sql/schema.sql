-- ============================================
-- GitHub Secret Hunter - 数据库结构
-- MariaDB 10.3+ / utf8mb4
-- ============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- 用户表
-- ----------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`         VARCHAR(64)  NOT NULL,
    `password_hash`    VARCHAR(255) NOT NULL COMMENT 'bcrypt 哈希，不存明文',
    `email`            VARCHAR(128) DEFAULT NULL,
    `role`             ENUM('admin','user') NOT NULL DEFAULT 'user',
    `status`           TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1=启用 0=禁用',
    `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=首次登录强制改密',
    `failed_attempts`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '连续失败次数',
    `lock_until`       DATETIME     DEFAULT NULL COMMENT '锁定截止时间',
    `last_login_ip`    VARCHAR(45)  DEFAULT NULL,
    `last_login_at`    DATETIME     DEFAULT NULL,
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户表';

-- ----------------------------
-- GitHub Token 表（AES 加密存储）
-- ----------------------------
DROP TABLE IF EXISTS `github_tokens`;
CREATE TABLE `github_tokens` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`          INT UNSIGNED NOT NULL,
    `platform`         ENUM('github','gitee','gitcode') NOT NULL DEFAULT 'github' COMMENT '所属平台',
    `label`            VARCHAR(64)  NOT NULL COMMENT 'Token 备注名',
    `token_encrypted`  TEXT         NOT NULL COMMENT 'AES-256-GCM 认证加密密文',
    `token_hint`       VARCHAR(8)   NOT NULL COMMENT 'Token 后4位，用于显示',
    `last_used_at`     DATETIME     DEFAULT NULL,
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    CONSTRAINT `fk_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='GitHub Token 加密存储';

-- ----------------------------
-- 搜索任务表（后台分页抓取 + 定时增量复查）
-- ----------------------------
DROP TABLE IF EXISTS `search_tasks`;
CREATE TABLE `search_tasks` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`          INT UNSIGNED NOT NULL,
    `platform`         ENUM('github','gitee','gitcode') NOT NULL DEFAULT 'github' COMMENT '所属平台',
    `token_id`         INT UNSIGNED DEFAULT NULL COMMENT '绑定的 GitHub Token',
    `query`            VARCHAR(512) NOT NULL COMMENT '搜索关键词',
    `status`           ENUM('pending','running','completed','failed') NOT NULL DEFAULT 'pending',
    `per_page`         TINYINT UNSIGNED NOT NULL DEFAULT 100 COMMENT '每页条数(10-100)',
    `notify`           TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '新增命中是否推送通知',
    `current_page`     INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '当前抓取页',
    `total_pages`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '总页数(上限10页=1000条)',
    `retries`          TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '连续失败次数(限流退避不计)',
    `last_new_count`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最近一页新增条数',
    `pending_notify_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '待推送的新增累计',
    `recheck_minutes`  INT UNSIGNED NOT NULL DEFAULT 30 COMMENT '复查间隔分钟(0=不复查)',
    `error_msg`        VARCHAR(512) DEFAULT NULL,
    `paused`           TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1=已暂停 0=正常',
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at`     DATETIME     DEFAULT NULL,
    `next_run_at`      DATETIME     DEFAULT NULL COMMENT '下次抓取时间(翻页调度)',
    `next_check_at`    DATETIME     DEFAULT NULL COMMENT '下次增量复查时间',
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_status` (`status`),
    KEY `idx_worker` (`status`,`next_run_at`),
    KEY `idx_recheck` (`status`,`next_check_at`),
    CONSTRAINT `fk_tasks_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='搜索任务';

-- ----------------------------
-- 搜索子查询表（大查询拆分：GitHub/Gitee 结果 >1000 条时按 language/size 切分）
-- ----------------------------
DROP TABLE IF EXISTS `search_subqueries`;
CREATE TABLE `search_subqueries` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `task_id`      INT UNSIGNED NOT NULL COMMENT '所属搜索任务',
    `query`        VARCHAR(800) NOT NULL COMMENT '子查询（原查询 + language/size 限定符）',
    `status`       ENUM('pending','running','completed') NOT NULL DEFAULT 'pending',
    `current_page` INT UNSIGNED NOT NULL DEFAULT 1,
    `total_count`  INT UNSIGNED NOT NULL DEFAULT 0,
    `total_pages`  INT UNSIGNED NOT NULL DEFAULT 0,
    `retries`      INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_task_status` (`task_id`, `status`, `id`),
    CONSTRAINT `fk_subqueries_task` FOREIGN KEY (`task_id`) REFERENCES `search_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='搜索任务子查询（大查询拆分）';

-- ----------------------------
-- 搜索结果表
-- ----------------------------
DROP TABLE IF EXISTS `search_results`;
CREATE TABLE `search_results` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `task_id`          INT UNSIGNED NOT NULL,
    `platform`         ENUM('github','gitee','gitcode') NOT NULL DEFAULT 'github' COMMENT '所属平台（随任务写入）',
    `repo_full_name`   VARCHAR(255) NOT NULL,
    `file_path`        VARCHAR(512) NOT NULL,
    `html_url`         VARCHAR(1024) NOT NULL,
    `match_snippet`    TEXT         DEFAULT NULL COMMENT '命中片段',
    `score`            DOUBLE       DEFAULT NULL,
    `found_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_task_id` (`task_id`),
    KEY `idx_platform` (`platform`),
    -- 增量复查去重：同一任务同一 URL 只入库一次（配合 INSERT IGNORE）
    UNIQUE KEY `uk_task_url` (`task_id`,`html_url`(191)),
    CONSTRAINT `fk_results_task` FOREIGN KEY (`task_id`) REFERENCES `search_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='搜索命中结果';

-- ----------------------------
-- 通知渠道表（配置 AES 加密存储）
-- ----------------------------
DROP TABLE IF EXISTS `notification_channels`;
CREATE TABLE `notification_channels` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`          INT UNSIGNED NOT NULL,
    `channel_type`     ENUM('dingtalk','wechat','feishu','email') NOT NULL,
    `name`             VARCHAR(64)  NOT NULL COMMENT '渠道备注名',
    `config_encrypted` TEXT         NOT NULL COMMENT 'JSON 配置 AES 加密',
    `enabled`          TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_type` (`user_id`,`channel_type`),
    CONSTRAINT `fk_channels_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='通知渠道配置';

-- ----------------------------
-- 通知发送日志
-- ----------------------------
DROP TABLE IF EXISTS `notification_logs`;
CREATE TABLE `notification_logs` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `channel_id`       INT UNSIGNED NOT NULL,
    `result_id`        INT UNSIGNED DEFAULT NULL,
    `status`           ENUM('success','failed') NOT NULL,
    `response`         TEXT         DEFAULT NULL,
    `sent_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_channel_id` (`channel_id`),
    CONSTRAINT `fk_logs_channel` FOREIGN KEY (`channel_id`) REFERENCES `notification_channels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='通知发送日志';

-- ----------------------------
-- 审计日志（敏感字段脱敏）
-- ----------------------------
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`          INT UNSIGNED DEFAULT NULL,
    `action`           VARCHAR(64)  NOT NULL,
    `ip`               VARCHAR(45)  NOT NULL,
    `user_agent`       VARCHAR(255) DEFAULT NULL,
    `details`          TEXT         DEFAULT NULL COMMENT 'JSON，敏感字段已脱敏',
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_action` (`user_id`,`action`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='审计日志';

-- ----------------------------
-- 仓库监控表（定时检查仓库最新提交）
-- ----------------------------
DROP TABLE IF EXISTS `repo_monitors`;
CREATE TABLE `repo_monitors` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`          INT UNSIGNED NOT NULL,
    `platform`         ENUM('github','gitee','gitcode') NOT NULL DEFAULT 'github' COMMENT '所属平台',
    `token_id`         INT UNSIGNED DEFAULT NULL COMMENT '绑定的平台 Token',
    `repo`             VARCHAR(150) NOT NULL COMMENT '仓库全名 owner/name',
    `check_interval_minutes` INT UNSIGNED NOT NULL DEFAULT 60 COMMENT '检查间隔分钟',
    `notify`           TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '发现更新是否推送通知',
    `paused`           TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1=已暂停',
    `last_sha`         CHAR(40)     DEFAULT NULL COMMENT '最近一次检查到的提交 sha',
    `last_commit_message`  VARCHAR(500) DEFAULT NULL,
    `last_commit_author`   VARCHAR(100) DEFAULT NULL,
    `last_commit_time`     DATETIME     DEFAULT NULL,
    `last_checked_at`  DATETIME     DEFAULT NULL,
    `last_status`      ENUM('ok','error') DEFAULT NULL,
    `last_error`       VARCHAR(512) DEFAULT NULL,
    `retries`          TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `next_check_at`    DATETIME     DEFAULT NULL COMMENT '下次检查时间',
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_platform_repo` (`user_id`,`platform`,`repo`),
    KEY `idx_worker` (`paused`,`next_check_at`),
    CONSTRAINT `fk_repo_monitors_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='仓库监控';

-- ----------------------------
-- 仓库更新记录表（检测到的新提交）
-- ----------------------------
DROP TABLE IF EXISTS `repo_updates`;
CREATE TABLE `repo_updates` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `monitor_id`  INT UNSIGNED NOT NULL,
    `sha`         CHAR(40)     NOT NULL,
    `author`      VARCHAR(100) DEFAULT NULL,
    `message`     VARCHAR(500) DEFAULT NULL,
    `html_url`    VARCHAR(500) DEFAULT NULL,
    `committed_at` DATETIME    DEFAULT NULL,
    `found_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_monitor_sha` (`monitor_id`,`sha`),
    CONSTRAINT `fk_repo_updates_monitor` FOREIGN KEY (`monitor_id`) REFERENCES `repo_monitors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='仓库更新记录';

-- ----------------------------
-- 应用设置表（数据保留期、站点标题、网站图标等全局配置）
-- setting_value 用 MEDIUMTEXT：站点图标以 base64 存储（512KB 图标约 700KB 文本）
-- ----------------------------
DROP TABLE IF EXISTS `app_settings`;
CREATE TABLE `app_settings` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `setting_key`      VARCHAR(64)  NOT NULL,
    `setting_value`    MEDIUMTEXT   NOT NULL,
    `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='应用设置';

-- 默认设置：数据保留 90 天
INSERT INTO `app_settings` (`setting_key`, `setting_value`) VALUES
('data_retention_days', '90');

-- ----------------------------
-- 初始管理员账号
-- 用户名: admin（.env 配置 ADMIN_USER 后，首次访问登录页自动重命名为该值；
--          部署后也可在「系统设置 → 管理员账号」中修改）
-- 默认密码: Admin@123456
-- 首次登录后系统将强制要求修改密码
-- password_hash 由 bcrypt(cost=12) 生成，数据库中仅存储哈希不存明文
-- ----------------------------
INSERT INTO `users` (`username`, `password_hash`, `email`, `role`, `status`, `must_change_password`)
VALUES ('admin', '$2b$12$97IZ5IciIcErjx3t25VEn.mkRURcZKZB6YV.LcGImI.oAIcf2NP72', 'admin@example.com', 'admin', 1, 1);

SET FOREIGN_KEY_CHECKS = 1;
