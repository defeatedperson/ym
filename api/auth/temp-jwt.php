<?php
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}
/**
 * 临时JWT令牌管理模块
 *
 * 功能说明：
 * 1. generate_temp_jwt($ip, $scene)：根据ip和场景（account/mfa/robots）生成临时JWT令牌（有效期5分钟），密钥每天自动更换，且服务端不保存历史密钥，存储于/data/temp-jwt.json。
 * 2. validate_temp_jwt($ip, $jwt)：校验临时JWT令牌是否有效（签名、过期、场景），并统计ip验证次数。
 * 3. 每个ip首次验证起10分钟内最多允许验证20次，超过则调用ip-ban.php封禁该ip并删除记录，或超时自动清除。
 * 4. /data/temp-jwt.json结构示例：
 * {
 *   "ip_logs": {
 *     "1.2.3.4": {"count": 3, "first": 1720000000}
 *   }
 * }
 * 通过限制ip验证次数，防止暴力破解账号密码。
 */

define('TEMP_JWT_FILE', __DIR__ . '/data/temp-jwt.json');
define('TEMP_JWT_EXPIRE', 5 * 60); // 5分钟
define('TEMP_JWT_LIMIT', 20); // 10分钟内最多20次
define('TEMP_JWT_WINDOW', 10 * 60); // 10分钟

require_once __DIR__ . '/ip-ban.php';

// 生成每日临时JWT密钥（不保存历史密钥，只保存当天密钥，和主jwt分开）
function _get_temp_jwt_secret() {
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
function _temp_jwt_encode($payload, $key) {
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

// 加载ip验证日志并自动清理（不再处理活跃JWT，使用session）
function _load_temp_jwt_data() {
    if (!file_exists(TEMP_JWT_FILE)) {
        if (!is_dir(dirname(TEMP_JWT_FILE))) {
            mkdir(dirname(TEMP_JWT_FILE), 0777, true);
        }
        file_put_contents(TEMP_JWT_FILE, json_encode(['ip_logs'=>[], 'login_attempts'=>[]]));
    }
    $data = json_decode(file_get_contents(TEMP_JWT_FILE), true);
    if (!is_array($data)) {
        $data = ['ip_logs'=>[], 'login_attempts'=>[]];
    }
    if (!isset($data['ip_logs'])) {
        $data['ip_logs'] = [];
    }
    if (!isset($data['login_attempts'])) {
        $data['login_attempts'] = [];
    }
    
    $now = time();
    
    // 清理超时的ip_logs记录
    foreach ($data['ip_logs'] as $ip => $log) {
        if (!isset($log['first']) || ($now - $log['first']) > TEMP_JWT_WINDOW) {
            unset($data['ip_logs'][$ip]);
        }
    }
    
    // 清理超时的login_attempts记录
    foreach ($data['login_attempts'] as $ip => $log) {
        if (!isset($log['first']) || ($now - $log['first']) > TEMP_JWT_WINDOW) {
            unset($data['login_attempts'][$ip]);
        }
    }
    
    file_put_contents(TEMP_JWT_FILE, json_encode($data));
    return $data;
}

// 保存ip验证日志
function _save_temp_jwt_data($data) {
    $result = file_put_contents(TEMP_JWT_FILE, json_encode($data));
    if ($result === false) {
        error_log("_save_temp_jwt_data: 文件写入失败！");
    }
}

/**
 * 生成临时JWT令牌
 * @param string $ip
 * @param string $scene account/mfa/robots
 * @return array ['status'=>'ok','jwt'=>string] | ['status'=>'banned'] | ['status'=>'limit']
 */
function generate_temp_jwt($ip, $scene) {
    return generate_temp_jwt_with_user($ip, $scene, null);
}

/**
 * 生成临时JWT令牌（支持用户名）
 * @param string $ip
 * @param string $scene account/mfa/robots
 * @param string|null $username
 * @return array ['status'=>'ok','jwt'=>string] | ['status'=>'banned'] | ['status'=>'limit']
 */
function generate_temp_jwt_with_user($ip, $scene, $username = null) {
    $scene = strtolower($scene);
    if (!in_array($scene, ['account','mfa','robots'])) return ['status'=>'limit'];
    $ban = is_ip_banned($ip);
    if ($ban['banned']) return ['status'=>'banned'];
    $data = _load_temp_jwt_data();
    $now = time();
    $log = isset($data['ip_logs'][$ip]) ? $data['ip_logs'][$ip] : ['count'=>0, 'first'=>$now];
    // 超时自动重置
    if (($now - $log['first']) > TEMP_JWT_WINDOW) {
        $log = ['count'=>0, 'first'=>$now];
    }
    if ($log['count'] >= TEMP_JWT_LIMIT) {
        add_ban_ip($ip);
        unset($data['ip_logs'][$ip]);
        _save_temp_jwt_data($data);
        return ['status'=>'banned'];
    }
    // 生成jwt
    $key = _get_temp_jwt_secret();
    $payload = [
        'ip' => $ip,
        'scene' => $scene,
        'iat' => $now,
        'exp' => $now + TEMP_JWT_EXPIRE
    ];
    if ($username) {
        $payload['username'] = $username;
    }
    $jwt = _temp_jwt_encode($payload, $key);
    
    // 保存到活跃JWT列表中（用于后续解密验证）
    save_active_temp_jwt($ip, $jwt, $scene, $now + TEMP_JWT_EXPIRE);
    
    // 记录验证次数
    $log['count']++;
    $data['ip_logs'][$ip] = $log;
    _save_temp_jwt_data($data);
    return ['status'=>'ok', 'jwt'=>$jwt];
}

/**
 * 校验临时JWT令牌
 * @param string $ip
 * @param string $jwt
 * @return array ['valid'=>bool, 'reason'=>string|null, 'scene'=>string|null]
 */
function validate_temp_jwt($ip, $jwt) {
    $key = _get_temp_jwt_secret();
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return ['valid'=>false, 'reason'=>'format', 'scene'=>null];
    $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    $signature = base64_decode(strtr($parts[2], '-_', '+/'));
    $signing_input = $parts[0] . '.' . $parts[1];
    $expected = hash_hmac('sha256', $signing_input, base64_decode($key), true);
    if ($signature !== $expected) return ['valid'=>false, 'reason'=>'signature', 'scene'=>null];
    if (!isset($payload['exp']) || time() > $payload['exp']) return ['valid'=>false, 'reason'=>'expired', 'scene'=>null];
    if (!isset($payload['ip']) || $payload['ip'] !== $ip) return ['valid'=>false, 'reason'=>'ip', 'scene'=>null];
    if (!isset($payload['scene'])) return ['valid'=>false, 'reason'=>'scene', 'scene'=>null];
    // 统计验证次数
    $data = _load_temp_jwt_data();
    $now = time();
    $log = isset($data['ip_logs'][$ip]) ? $data['ip_logs'][$ip] : ['count'=>0, 'first'=>$now];
    if (($now - $log['first']) > TEMP_JWT_WINDOW) {
        $log = ['count'=>0, 'first'=>$now];
    }
    $log['count']++;
    if ($log['count'] > TEMP_JWT_LIMIT) {
        add_ban_ip($ip);
        unset($data['ip_logs'][$ip]);
        _save_temp_jwt_data($data);
        return ['valid'=>false, 'reason'=>'banned', 'scene'=>null];
    }
    $data['ip_logs'][$ip] = $log;
    _save_temp_jwt_data($data);
    return [
        'valid'=>true, 
        'reason'=>null, 
        'scene'=>$payload['scene'],
        'username'=>isset($payload['username']) ? $payload['username'] : null
    ];
}

 /**
 * 清理指定ip的临时JWT统计次数
 * @param string $ip
 * @return bool
 */
function clear_temp_jwt_ip($ip) {
    $data = _load_temp_jwt_data();
    if (isset($data['ip_logs'][$ip])) {
        unset($data['ip_logs'][$ip]);
        _save_temp_jwt_data($data);
        return true;
    }
    return false;
}

/**
 * 保存活跃的临时JWT记录（使用session存储）
 * @param string $ip
 * @param string $jwt
 * @param string $scene
 * @param int $expires
 */
function save_active_temp_jwt($ip, $jwt, $scene, $expires) {
    // 初始化session中的活跃JWT存储
    if (!isset($_SESSION['active_temp_jwts'])) {
        $_SESSION['active_temp_jwts'] = [];
    }
    
    $current_time = time();
    
    // 清理过期的JWT
    $_SESSION['active_temp_jwts'] = array_filter($_SESSION['active_temp_jwts'], function($jwt_info) use ($current_time) {
        return $jwt_info['expires'] > $current_time;
    });
    
    // 清理同IP同场景的旧JWT（避免重复）
    $_SESSION['active_temp_jwts'] = array_filter($_SESSION['active_temp_jwts'], function($jwt_info) use ($ip, $scene) {
        return !($jwt_info['ip'] === $ip && $jwt_info['scene'] === $scene);
    });
    
    // 添加新的JWT记录
    $_SESSION['active_temp_jwts'][] = [
        'ip' => $ip,
        'jwt' => $jwt,
        'scene' => $scene,
        'created' => $current_time,
        'expires' => $expires
    ];
}

/**
 * 根据IP获取活跃的临时JWT (从session中查找)
 * @param string $ip 客户端IP
 * @param string $scene 场景类型 (account/mfa/robots)
 * @param int $time_window 时间窗口(秒)
 * @return array|false 返回匹配的JWT信息或false
 */
function get_active_temp_jwt_by_ip($ip, $scene = 'account', $time_window = 300) {
    if (!isset($_SESSION['active_temp_jwts'])) {
        return false;
    }
    
    $current_time = time();
    $found_jwt = null;
    
    // 查找该IP在时间窗口内的指定场景JWT
    foreach ($_SESSION['active_temp_jwts'] as $index => $jwt_info) {
        if ($jwt_info['ip'] === $ip && 
            $jwt_info['scene'] === $scene && 
            ($current_time - $jwt_info['created']) <= $time_window &&
            $jwt_info['expires'] > $current_time) {
            $found_jwt = $jwt_info;
            break;
        }
    }
    
    return $found_jwt;
}

/**
 * 增加IP的登录尝试次数（用于防暴力破解）
 * @param string $ip
 * @return array ['banned'=>bool, 'count'=>int, 'remaining'=>int]
 */
function increment_login_attempt($ip) {
    $data = _load_temp_jwt_data();
    $now = time();
    
    if (!isset($data['login_attempts'])) {
        $data['login_attempts'] = [];
    }
    
    $log = isset($data['login_attempts'][$ip]) ? $data['login_attempts'][$ip] : ['count'=>0, 'first'=>$now];
    
    // 超时自动重置
    if (($now - $log['first']) > TEMP_JWT_WINDOW) {
        $log = ['count'=>0, 'first'=>$now];
    }
    
    $log['count']++;
    $remaining = TEMP_JWT_LIMIT - $log['count'];
    
    if ($log['count'] >= TEMP_JWT_LIMIT) {
        add_ban_ip($ip);
        unset($data['login_attempts'][$ip]);
        _save_temp_jwt_data($data);
        return ['banned'=>true, 'count'=>$log['count'], 'remaining'=>0];
    }
    
    $data['login_attempts'][$ip] = $log;
    _save_temp_jwt_data($data);
    
    return ['banned'=>false, 'count'=>$log['count'], 'remaining'=>$remaining];
}

/**
 * 清理session中的临时JWT（登录成功后调用）
 */
function clear_session_temp_jwts() {
    if (isset($_SESSION['active_temp_jwts'])) {
        unset($_SESSION['active_temp_jwts']);
    }
}
?>