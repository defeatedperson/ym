<?php
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}
/**
 * 用户信息获取/鉴权模块（直接使用访问JWT信息，无需数据库查询）
 *
 * 用法：
 *   $info = get_user_info($visit_jwt, $ip);
 *   if ($info['valid']) { $user_id = $info['user_id']; $nickname = $info['nickname']; $is_admin = $info['is_admin']; }
 *   else { // 未登录或无效 }
 * 
 * 优势：
 *   - 直接从访问JWT中获取用户ID、用户名、权限信息
 *   - 无需查询数据库，性能更优
 *   - 访问JWT已包含完整用户信息和安全验证
 */

// 移除数据库相关定义，不再需要
// define('DB_FILE', __DIR__ . '/data/main.db');
// define('ADMIN_TABLE', 'users');

require_once __DIR__ . '/visit-jwt.php';

/**
 * 获取用户信息（通过访问JWT，无需数据库查询）
 * @param string $visit_jwt 访问JWT
 * @param string $ip 当前请求IP
 * @return array ['valid'=>bool, 'user_id'=>int|null, 'nickname'=>string|null, 'is_admin'=>bool, 'reason'=>string|null]
 *
 * 说明：
 *   直接从访问JWT中获取用户ID、用户名、权限信息，避免数据库查询，提高性能
 */
function get_user_info($visit_jwt, $ip) {
    $jwt_info = validate_visit_jwt($visit_jwt, $ip);
    
    if (!$jwt_info['valid']) {
        return [
            'valid' => false, 
            'user_id' => null, 
            'nickname' => null, 
            'is_admin' => false, 
            'reason' => $jwt_info['reason'] ?? 'invalid_jwt'
        ];
    }
    
    // 直接从访问JWT中获取用户信息，无需数据库查询
    return [
        'valid' => true, 
        'user_id' => $jwt_info['user_id'], 
        'nickname' => $jwt_info['username'], 
        'is_admin' => $jwt_info['is_admin'],
        'reason' => null
    ];
}
?>
