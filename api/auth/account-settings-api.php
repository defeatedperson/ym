<?php
// 账号设置API - 安全的账号管理接口
// 注意：已统一使用账号JWT进行身份验证，支持IP绑定，提高安全性
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'method_not_allowed']);
    exit;
}

require_once __DIR__ . '/ip-cail.php';
require_once __DIR__ . '/ip-ban.php';
require_once __DIR__ . '/jwt.php'; // 使用统一的JWT验证模块
require_once __DIR__ . '/safe-input.php';
require_once __DIR__ . '/set-account.php';
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

$username = $info['nickname'];
$user_id = $info['user_id'];
$is_admin = $info['is_admin'];
$action = isset($_POST['action']) ? validate_common_input($_POST['action']) : '';

header('Content-Type: application/json');

switch ($action) {
    case 'get_info':
        // 获取账号信息
        $result = get_account_mfa_and_email($username);
        if ($result['status'] === 'ok') {
            echo json_encode([
                'status' => 'ok',
                'username' => $username,
                'email' => $result['email'],
                'mfa_enabled' => $result['mfa_enabled']
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => '获取账号信息失败']);
        }
        break;

    case 'update_username':
        // 修改用户名
        $newUsername = isset($_POST['new_username']) ? validate_common_input($_POST['new_username']) : '';
        if (!$newUsername) {
            echo json_encode(['status' => 'error', 'message' => '新用户名格式无效']);
            break;
        }
        $result = update_account_name($username, $newUsername);
        echo json_encode($result['status'] === 'ok' 
            ? ['status' => 'ok', 'message' => '用户名修改成功'] 
            : ['status' => 'error', 'message' => '用户名修改失败']);
        break;

    case 'update_email':
        // 修改邮箱
        $newEmail = isset($_POST['new_email']) ? validate_email($_POST['new_email']) : false;
        if (!$newEmail) {
            echo json_encode(['status' => 'error', 'message' => '邮箱格式无效']);
            break;
        }
        $result = update_account_email($username, $newEmail);
        echo json_encode($result['status'] === 'ok' 
            ? ['status' => 'ok', 'message' => '邮箱修改成功'] 
            : ['status' => 'error', 'message' => '邮箱修改失败']);
        break;

    case 'update_password':
        // 修改密码
        $oldPassword = isset($_POST['old_password']) ? $_POST['old_password'] : '';
        $newPassword = isset($_POST['new_password']) ? validate_password($_POST['new_password']) : false;
        if (!$newPassword) {
            echo json_encode(['status' => 'error', 'message' => '新密码格式无效（至少8位，包含大写、小写、数字、特殊字符）']);
            break;
        }
        $result = update_account_password($username, $oldPassword, $newPassword);
        if ($result['status'] === 'ok') {
            echo json_encode(['status' => 'ok', 'message' => '密码修改成功']);
        } elseif ($result['status'] === 'fail') {
            echo json_encode(['status' => 'error', 'message' => '旧密码验证失败']);
        } else {
            echo json_encode(['status' => 'error', 'message' => '密码修改失败']);
        }
        break;

    case 'enable_mfa':
        // 启用MFA（旧接口，保持兼容）
        $result = enable_account_mfa($username);
        echo json_encode($result['status'] === 'ok' 
            ? ['status' => 'ok', 'message' => 'MFA启用成功', 'mfa_secret' => $result['mfa_secret'], 'username' => $result['username']] 
            : ['status' => 'error', 'message' => 'MFA启用失败']);
        break;

    case 'generate_mfa_secret':
        // 生成MFA密钥但不启用
        $result = generate_mfa_secret($username);
        echo json_encode($result['status'] === 'ok' 
            ? ['status' => 'ok', 'message' => 'MFA密钥生成成功', 'mfa_secret' => $result['mfa_secret'], 'username' => $result['username']] 
            : ['status' => 'error', 'message' => 'MFA密钥生成失败']);
        break;

    case 'verify_and_enable_mfa':
        // 验证验证码并启用MFA
        $secret = isset($_POST['secret']) ? validate_common_input($_POST['secret']) : '';
        $code = isset($_POST['code']) ? validate_common_input($_POST['code']) : '';
        if (!$secret || !$code) {
            echo json_encode(['status' => 'error', 'message' => '密钥或验证码不能为空']);
            break;
        }
        $result = verify_and_enable_mfa($username, $secret, $code);
        echo json_encode($result);
        break;

    case 'disable_mfa':
        // 禁用MFA
        $result = disable_account_mfa($username);
        echo json_encode($result['status'] === 'ok' 
            ? ['status' => 'ok', 'message' => 'MFA已禁用'] 
            : ['status' => 'error', 'message' => 'MFA禁用失败']);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => '无效的操作']);
        break;
}
exit;
