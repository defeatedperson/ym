<?php
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}
/**
 * 日常访问临时JWT令牌管理模块
 *
 * 功能说明：
 * 1. generate_visit_jwt($account_jwt, $ip)：传入账号JWT令牌和当前IP，验证有效后生成访问JWT令牌（有效期5分钟），包含完整用户信息（ID、用户名、权限）。
 * 2. validate_visit_jwt($visit_jwt, $ip)：校验访问JWT令牌是否有效（签名、过期、账号信息、IP绑定），返回完整用户信息。
 * 3. 访问令牌仅用于短时接口访问，防止账号JWT频繁暴露，同时减少数据库查询次数。
 * 
 * 设计优势：
 * - 统一使用访问JWT，包含用户ID、用户名、权限信息
 * - 减少数据库查询，提高性能
 * - IP绑定增强安全性
 * - 短期有效期降低安全风险
 */

define('VISIT_JWT_EXPIRE', 5 * 60); // 5分钟

require_once __DIR__ . '/account-jwt.php';

// 生成每日访问JWT密钥（不保存历史密钥，只保存当天密钥，和主jwt分开）
function _get_visit_jwt_secret() {
    $date = date('Ymd');
    static $keyCache = [];
    if (isset($keyCache[$date])) return $keyCache[$date];
    // 用日期+主机信息+文件路径混合
    $seed = $date . php_uname() . __FILE__;
    $key = base64_encode(hash('sha256', $seed, true));
    $keyCache[$date] = $key;
    return $key;
}

// 生成JWT
function _visit_jwt_encode($payload, $key) {
    $header = ['alg'=>'HS256','typ'=>'JWT'];
    $segments = [
        rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '='),
        rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=')
    ];
    $signing_input = implode('.', $segments);
    $signature = hash_hmac('sha256', $signing_input, base64_decode($key), true);
    $segments[] = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    return implode('.', $segments);
}

/**
 * 生成访问JWT令牌（包含完整用户信息，增加IP绑定）
 * @param string $account_jwt 账号JWT令牌
 * @param string $ip 当前请求IP
 * @return array ['status'=>'ok','jwt'=>string] | ['status'=>'invalid']
 */
function generate_visit_jwt($account_jwt, $ip) {
    $info = validate_jwt_token($account_jwt);
    if (!$info['valid']) return ['status'=>'invalid'];
    
    // 从账号JWT中获取完整的用户信息
    $username = $info['username'];
    $user_id = $info['user_id'];
    $is_admin = $info['is_admin'];
    
    $now = time();
    $key = _get_visit_jwt_secret();
    $payload = [
        'sub' => $username,          // 用户名
        'uid' => $user_id,           // 用户ID
        'adm' => $is_admin,          // 管理员标识
        'iat' => $now,
        'exp' => $now + VISIT_JWT_EXPIRE,
        'ip'  => $ip
    ];
    $jwt = _visit_jwt_encode($payload, $key);
    return ['status'=>'ok', 'jwt'=>$jwt];
}

/**
 * 校验访问JWT令牌（增加IP绑定校验，返回完整用户信息）
 * @param string $visit_jwt
 * @param string $ip 当前请求IP
 * @return array ['valid'=>bool, 'reason'=>string|null, 'username'=>string|null, 'user_id'=>int|null, 'is_admin'=>bool]
 */
function validate_visit_jwt($visit_jwt, $ip) {
    $key = _get_visit_jwt_secret();
    $parts = explode('.', $visit_jwt);
    if (count($parts) !== 3) return ['valid'=>false, 'reason'=>'format', 'username'=>null, 'user_id'=>null, 'is_admin'=>false];
    
    $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    $signature = base64_decode(strtr($parts[2], '-_', '+/'));
    $signing_input = $parts[0] . '.' . $parts[1];
    $expected = hash_hmac('sha256', $signing_input, base64_decode($key), true);
    
    if ($signature !== $expected) return ['valid'=>false, 'reason'=>'signature', 'username'=>null, 'user_id'=>null, 'is_admin'=>false];
    if (!isset($payload['exp']) || time() > $payload['exp']) return ['valid'=>false, 'reason'=>'expired', 'username'=>null, 'user_id'=>null, 'is_admin'=>false];
    if (!isset($payload['ip']) || $payload['ip'] !== $ip) return ['valid'=>false, 'reason'=>'ip_mismatch', 'username'=>null, 'user_id'=>null, 'is_admin'=>false];
    
    // 验证访问JWT必须包含完整的用户信息
    if (!isset($payload['sub']) || !isset($payload['uid']) || !isset($payload['adm'])) {
        return ['valid'=>false, 'reason'=>'invalid_payload', 'username'=>null, 'user_id'=>null, 'is_admin'=>false];
    }
    
    return [
        'valid' => true, 
        'reason' => null, 
        'username' => $payload['sub'],
        'user_id' => (int)$payload['uid'],
        'is_admin' => (bool)$payload['adm']
    ];
}
?>