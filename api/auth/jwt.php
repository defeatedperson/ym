<?php
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}
/**
 * 用户信息获取/鉴权模块（使用账号JWT信息）
 *
 * 用法：
 *   $info = get_user_info($account_jwt, $ip);
 *   if ($info['valid']) { $user_id = $info['user_id']; $nickname = $info['nickname']; $is_admin = $info['is_admin']; }
 *   else { // 未登录或无效 }
 * 
 * 优势：
 *   - 从账号JWT中获取用户ID、用户名、权限信息
 *   - 验证IP绑定，提高安全性
 *   - 账号JWT已包含完整用户信息和安全验证
 */

// 引入账号JWT模块
require_once __DIR__ . '/account-jwt.php';

/**
 * 获取用户信息（通过账号JWT）
 * @param string $account_jwt 账号JWT
 * @param string $ip 当前请求IP
 * @return array ['valid'=>bool, 'user_id'=>int|null, 'nickname'=>string|null, 'is_admin'=>bool, 'reason'=>string|null]
 *
 * 说明：
 *   从账号JWT中获取用户ID、用户名、权限信息，并验证IP绑定
 */
function get_user_info($account_jwt, $ip) {
    $jwt_info = validate_jwt_token($account_jwt, $ip);
    
    if (!$jwt_info['valid']) {
        return [
            'valid' => false, 
            'user_id' => null, 
            'nickname' => null, 
            'is_admin' => false, 
            'reason' => $jwt_info['reason'] ?? 'invalid_jwt'
        ];
    }
    
    // 从账号JWT中获取用户信息
    return [
        'valid' => true, 
        'user_id' => $jwt_info['user_id'], 
        'nickname' => $jwt_info['username'], 
        'is_admin' => $jwt_info['is_admin'],
        'reason' => null
    ];
}
?>
