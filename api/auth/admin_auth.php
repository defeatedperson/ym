<?php

require_once __DIR__ . '/ip-cail.php';
require_once __DIR__ . '/ip-ban.php';
require_once __DIR__ . '/jwt.php';

// 管理员访问鉴权函数
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

    // 验证访问JWT - 从Authorization头获取
    $auth_header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if (!$auth_header || !preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'visit_jwt_missing',
            'message' => '访问JWT不存在，需要刷新令牌',
            'action' => 'refresh_token'
        ]);
        exit;
    }

    $visit_jwt = $matches[1];
    $info = get_user_info($visit_jwt, $ip);
    if (!$info['valid']) {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'visit_jwt_invalid',
            'message' => '访问JWT已过期或无效，需要刷新令牌',
            'action' => 'refresh_token'
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