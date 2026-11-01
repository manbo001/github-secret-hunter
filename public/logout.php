<?php
require_once __DIR__ . '/../includes/bootstrap.php';

// 防 CSRF 强制登出：登出需携带有效 CSRF 令牌（GET ?t= 或 POST 表单）
if (Auth::check()) {
    $token = $_GET['t'] ?? $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf(is_string($token) ? $token : '')) {
        redirect('dashboard.php');
    }
}

Auth::logout();
redirect('index.php?loggedout=1');
