<?php
/**
 * 数据库连接 - PDO 单例
 */

// bootstrap.php 已加载 app.php 并存入 $GLOBALS['config']，直接复用避免重复加载
$config = $GLOBALS['config'] ?? require_once __DIR__ . '/app.php';
$db = $config['db'];

// 校验必要配置
if (empty($db['user']) || empty($db['name'])) {
    http_response_code(500);
    die('Database configuration missing. Please check .env file.');
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $db['host'],
    $db['port'],
    $db['name'],
    $db['charset']
);

try {
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,  // 禁用模拟预处理，强制使用真实预处理（防注入）
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    // 生产环境不暴露数据库错误详情
    if ($config['app']['debug']) {
        die('Database connection failed: ' . $e->getMessage());
    }
    die('Database connection failed.');
}

return $pdo;
