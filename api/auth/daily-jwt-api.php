<?php
// 只允许POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'method_not_allowed']);
    exit;
}

require_once __DIR__ . '/ip-cail.php';
require_once __DIR__ . '/ip-ban.php';
require_once __DIR__ . '/account-jwt.php';
require_once __DIR__ . '/security-headers.php';

// 获取真实IP并检测是否被封禁
$ip = get_real_ip();
$ban = is_ip_banned($ip);
if ($ban['banned']) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ip_banned',
        'ip' => $ip,
        'until' => $ban['until']
    ]);
    exit;
}

// 获取账号JWT（从HttpOnly Cookie）
$account_jwt = isset($_COOKIE['account_jwt']) ? $_COOKIE['account_jwt'] : '';
if (!$account_jwt) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'account_jwt_missing',
        'message' => '账号JWT不存在，请重新登录',
        'redirect' => 'login'
    ]);
    exit;
}

// 验证账号JWT
$info = validate_jwt_token($account_jwt, $ip);
if (!$info['valid']) {
    // 清除过期的cookie
    setcookie('account_jwt', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    
    // 同时清除用户名cookie
    setcookie('username', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => true,
        'httponly' => false, // 允许JavaScript访问
        'samesite' => 'Strict'
    ]);
    
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'account_jwt_invalid',
        'message' => '账号JWT已过期或无效，请重新登录',
        'reason' => $info['reason'] ?? 'unknown',
        'redirect' => 'login'
    ]);
    exit;
}

// 设置用户名cookie（允许JavaScript访问）
setcookie('username', $info['username'], [
    'expires' => time() + 86400 * 7, // 7天有效期
    'path' => '/',
    'secure' => true,
    'httponly' => false, // 允许JavaScript访问
    'samesite' => 'Strict'
]);

header('Content-Type: application/json');
echo json_encode([
    'status' => 'ok',
    'username' => $info['username'],
    'is_admin' => $info['is_admin']
]);
exit;