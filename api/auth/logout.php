<?php
// 只允许POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'method_not_allowed']);
    exit;
}

require_once __DIR__ . '/account-jwt.php';

// 获取账号JWT（从HttpOnly Cookie）
$account_jwt = isset($_COOKIE['account_jwt']) ? $_COOKIE['account_jwt'] : '';

if ($account_jwt) {
    // 将JWT加入强制登出列表
    ban_jwt_token($account_jwt);
}

// 清除HttpOnly Cookie
setcookie('account_jwt', '', [
    'expires' => time() - 3600,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);

header('Content-Type: application/json');
echo json_encode(['status' => 'ok']);
exit;
?>
