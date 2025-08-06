<?php
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}
/**
 * 账号JWT令牌管理模块
 *
 * 功能说明：
 * 1. generate_jwt_token($username)：生成JWT令牌（有效期7天），包含用户ID、用户名、权限信息，自动管理密钥。
 * 2. ban_jwt_token($jwt)：将JWT令牌加入强制登出列表（/data/ban-token.json），自动清理过期记录。
 * 3. validate_jwt_token($jwt)：校验JWT令牌有效性（签名、过期、强制登出、用户信息一致性），返回完整用户信息。
 *
 * 安全特性：
 * - JWT载荷包含用户ID、用户名、管理员标识，防止用户名冲突导致的权限混乱
 * - 验证时通过用户ID查询数据库，确保JWT信息与实际用户信息一致
 * - 密钥每30天自动轮换，缺失时自动生成，安全加密存储
 *
 * 文件结构：
 * /data/jwt.json: {"key": "base64密钥", "last_update": 1720000000}
 * /data/ban-token.json: {"token1": 1720000000, "token2": 1720000100}
 */

define('JWT_KEY_FILE', __DIR__ . '/data/jwt.json');
define('JWT_BAN_FILE', __DIR__ . '/data/ban-token.json');
define('JWT_KEY_ROTATE_DAYS', 30);
define('JWT_EXPIRE', 7 * 24 * 3600); // 7天

// 添加数据库相关定义
if (!defined('DB_FILE')) {
    define('DB_FILE', __DIR__ . '/data/main.db');
}

// 生成安全随机密钥
function _generate_jwt_secret() {
    return base64_encode(random_bytes(32));
}

// 加载或自动生成密钥，自动轮换
function _get_jwt_secret() {
    if (!file_exists(JWT_KEY_FILE)) {
        if (!is_dir(dirname(JWT_KEY_FILE))) {
            mkdir(dirname(JWT_KEY_FILE), 0777, true);
        }
        $key = _generate_jwt_secret();
        $data = ["key"=>$key, "last_update"=>time()];
        file_put_contents(JWT_KEY_FILE, json_encode($data));
        return $key;
    }
    $data = json_decode(file_get_contents(JWT_KEY_FILE), true);
    if (!is_array($data) || !isset($data['key']) || !isset($data['last_update'])) {
        $key = _generate_jwt_secret();
        $data = ["key"=>$key, "last_update"=>time()];
        file_put_contents(JWT_KEY_FILE, json_encode($data));
        return $key;
    }
    // 检查是否需要轮换
    $now = time();
    if ($now - $data['last_update'] > JWT_KEY_ROTATE_DAYS * 86400) {
        $key = _generate_jwt_secret();
        $data = ["key"=>$key, "last_update"=>$now];
        file_put_contents(JWT_KEY_FILE, json_encode($data));
        return $key;
    }
    return $data['key'];
}

// 生成JWT（无需外部库，兼容性好）
function _jwt_encode($payload, $key) {
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
 * 生成账号JWT令牌
 * @param string $username
 * @return string JWT令牌
 */
function generate_jwt_token($username) {
    // 首先获取用户的完整信息，包括ID和权限
    if (!file_exists(DB_FILE)) {
        throw new Exception('数据库文件不存在');
    }
    
    $db = new SQLite3(DB_FILE);
    $stmt = $db->prepare("SELECT id, username, is_admin FROM users WHERE username = ?");
    $stmt->bindValue(1, $username, SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    $db->close();
    
    if (!$user) {
        throw new Exception('用户不存在');
    }
    
    $key = _get_jwt_secret();
    $now = time();
    $payload = [
        'sub' => $username,           // 保持兼容性
        'uid' => (int)$user['id'],    // 用户ID - 主要标识符
        'adm' => (int)$user['is_admin'], // 管理员标识
        'iat' => $now,
        'exp' => $now + JWT_EXPIRE
    ];
    return _jwt_encode($payload, $key);
}

/**
 * 将JWT令牌加入强制登出列表
 * @param string $jwt
 * @return void
 */
function ban_jwt_token($jwt) {
    if (!file_exists(JWT_BAN_FILE)) {
        if (!is_dir(dirname(JWT_BAN_FILE))) {
            mkdir(dirname(JWT_BAN_FILE), 0777, true);
        }
        file_put_contents(JWT_BAN_FILE, json_encode([]));
    }
    $data = json_decode(file_get_contents(JWT_BAN_FILE), true);
    if (!is_array($data)) $data = [];
    // 清理过期
    $now = time();
    foreach ($data as $tk => $exp) {
        if ($exp < $now) unset($data[$tk]);
    }
    // 解析exp
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return;
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    $exp = isset($payload['exp']) ? intval($payload['exp']) : ($now + 3600);
    $data[$jwt] = $exp;
    file_put_contents(JWT_BAN_FILE, json_encode($data));
}

/**
 * 校验JWT令牌是否有效（签名、过期、强制登出、用户信息一致性）
 * 安全特性：
 * - 验证JWT必须包含用户ID、用户名、管理员标识
 * - 通过用户ID查询数据库验证信息一致性，防止伪造
 * - 不兼容旧格式JWT，确保安全性
 * @param string $jwt
 * @return array ['valid'=>bool, 'reason'=>string|null, 'username'=>string|null, 'user_id'=>int|null, 'is_admin'=>bool]
 */
function validate_jwt_token($jwt) {
    $key = _get_jwt_secret();
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return ['valid'=>false, 'reason'=>'format', 'username'=>null, 'user_id'=>null, 'is_admin'=>false];
    $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    $signature = base64_decode(strtr($parts[2], '-_', '+/'));
    $signing_input = $parts[0] . '.' . $parts[1];
    $expected = hash_hmac('sha256', $signing_input, base64_decode($key), true);
    if ($signature !== $expected) return ['valid'=>false, 'reason'=>'signature', 'username'=>null, 'user_id'=>null, 'is_admin'=>false];
    if (!isset($payload['exp']) || time() > $payload['exp']) return ['valid'=>false, 'reason'=>'expired', 'username'=>null, 'user_id'=>null, 'is_admin'=>false];
    
    // 检查强制登出
    if (file_exists(JWT_BAN_FILE)) {
        $ban = json_decode(file_get_contents(JWT_BAN_FILE), true);
        if (isset($ban[$jwt])) return ['valid'=>false, 'reason'=>'banned', 'username'=>null, 'user_id'=>null, 'is_admin'=>false];
    }
    
    // 验证JWT载荷必须包含完整的用户信息
    if (!isset($payload['sub']) || !isset($payload['uid']) || !isset($payload['adm'])) {
        return ['valid'=>false, 'reason'=>'invalid_payload', 'username'=>null, 'user_id'=>null, 'is_admin'=>false];
    }
    
    $username = $payload['sub'];
    $user_id = (int)$payload['uid'];
    $is_admin = (bool)$payload['adm'];
    
    // 验证用户信息的一致性（防止JWT伪造）
    if (file_exists(DB_FILE)) {
        $db = new SQLite3(DB_FILE);
        $stmt = $db->prepare("SELECT username, is_admin FROM users WHERE id = ?");
        $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $user = $result->fetchArray(SQLITE3_ASSOC);
        $db->close();
        
        // 验证JWT中的信息与数据库中的信息是否一致
        if (!$user || $user['username'] !== $username || (bool)$user['is_admin'] !== $is_admin) {
            return ['valid'=>false, 'reason'=>'user_mismatch', 'username'=>null, 'user_id'=>null, 'is_admin'=>false];
        }
    } else {
        return ['valid'=>false, 'reason'=>'database_error', 'username'=>null, 'user_id'=>null, 'is_admin'=>false];
    }
    
    return [
        'valid' => true, 
        'reason' => null, 
        'username' => $username, 
        'user_id' => $user_id,
        'is_admin' => $is_admin
    ];
}
?>
