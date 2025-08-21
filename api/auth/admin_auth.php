<?php

require_once __DIR__ . '/ip-cail.php';
require_once __DIR__ . '/ip-ban.php';
require_once __DIR__ . '/jwt.php';

// 管理员访问鉴权函数 - 使用账号JWT和IP绑定进行验证
function admin_auth_check() {
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

    // 验证账号JWT - 从Cookie获取
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

    $info = get_user_info($account_jwt, $ip);
    if (!$info['valid']) {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'account_jwt_invalid',
            'message' => '账号JWT已过期或无效，请重新登录',
            'redirect' => 'login'
        ]);
        exit;
    }

    // 验证管理员权限
    if (!$info['is_admin']) {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'permission_denied',
            'message' => '仅管理员可以访问此接口'
        ]);
        exit;
    }

    // 鉴权通过，返回用户信息
    return $info;
}

?>