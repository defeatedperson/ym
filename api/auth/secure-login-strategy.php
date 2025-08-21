<?php
/**
 * 改进的安全登录验证策略
 * 
 * 核心改进：
 * 1. 不接收明文临时JWT
 * 2. 通过IP+时间窗口匹配活跃的临时JWT
 * 3. 防重放攻击检查
 * 4. 完整的数据包加密验证
 */

if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/temp-jwt.php';
require_once __DIR__ . '/ip-cail.php';

/**
 * CryptoJS兼容的AES解密函数
 * @param string $encrypted_data CryptoJS加密的数据
 * @param string $key 解密密钥
 * @return string|false 解密后的数据或false
 */
function decrypt_aes_data($encrypted_data, $key) {
    try {
        // Base64解码
        $data = base64_decode($encrypted_data);
        if ($data === false) {
            return false;
        }
        
        // CryptoJS格式：Salted__ + 8字节盐 + 加密数据
        if (substr($data, 0, 8) !== 'Salted__') {
            return false;
        }
        
        $salt = substr($data, 8, 8);
        $ciphertext = substr($data, 16);
        
        // 生成密钥和IV（CryptoJS兼容）
        $key_iv = evpBytesToKey($key, $salt, 32, 16);
        $derived_key = $key_iv['key'];
        $iv = $key_iv['iv'];
        
        // AES-256-CBC解密
        $decrypted = openssl_decrypt($ciphertext, 'AES-256-CBC', $derived_key, OPENSSL_RAW_DATA, $iv);
        
        return $decrypted;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * CryptoJS兼容的密钥派生函数
 */
function evpBytesToKey($password, $salt, $keyLen, $ivLen) {
    $dtot = $keyLen + $ivLen;
    $d = array();
    $d[0] = hash('md5', $password . $salt, true);
    $i = 1;
    while (strlen(implode('', $d)) < $dtot) {
        $d[$i] = hash('md5', $d[$i - 1] . $password . $salt, true);
        $i++;
    }
    $ms = implode('', $d);
    return array(
        'key' => substr($ms, 0, $keyLen),
        'iv' => substr($ms, $keyLen, $ivLen)
    );
}

/**
 * 防重放攻击检查
 * @param string $nonce 随机数
 * @param string $ip 客户端IP
 * @return bool
 */
function check_replay_attack($nonce, $ip) {
    $nonce_file = __DIR__ . '/data/used-nonces.json';
    $data = file_exists($nonce_file) ? json_decode(file_get_contents($nonce_file), true) : [];
    
    if (!isset($data['nonces'])) {
        $data['nonces'] = [];
    }
    
    $current_time = time();
    $nonce_key = $ip . ':' . $nonce;
    
    // 检查是否已使用
    if (isset($data['nonces'][$nonce_key])) {
        return false; // 重放攻击
    }
    
    // 清理过期的nonce (5分钟)
    foreach ($data['nonces'] as $key => $timestamp) {
        if ($current_time - $timestamp > 300) {
            unset($data['nonces'][$key]);
        }
    }
    
    // 记录新的nonce
    $data['nonces'][$nonce_key] = $current_time;
    file_put_contents($nonce_file, json_encode($data));
    
    return true;
}

/**
 * 安全解密登录数据包
 * @param string $encrypted_data 加密的登录数据
 * @param string $ip 客户端IP
 * @return array|false 解密后的登录数据或false
 */
function decrypt_secure_login_data($encrypted_data, $ip) {
    // 1. 获取该IP的活跃临时JWT (account场景)
    $jwt_info = get_active_temp_jwt_by_ip($ip, 'account', 300); // 5分钟窗口
    if (!$jwt_info) {
        return false;
    }
    
    // 2. 使用临时JWT解密数据
    $decrypted_json = decrypt_aes_data($encrypted_data, $jwt_info['jwt']);
    if ($decrypted_json === false) {
        return false;
    }
    
    // 3. 解析JSON数据
    $login_data = json_decode($decrypted_json, true);
    if (!$login_data || !isset($login_data['username'], $login_data['password'], $login_data['nonce'])) {
        return false;
    }
    
    // 4. 时间戳检查 (防止过期数据)
    if (isset($login_data['timestamp'])) {
        $time_diff = time() * 1000 - $login_data['timestamp']; // 转换为毫秒
        if ($time_diff > 30000) { // 30秒超时
            return false;
        }
    }
    
    // 5. 防重放攻击检查
    if (!check_replay_attack($login_data['nonce'], $ip)) {
        return false;
    }
    
    return [
        'username' => $login_data['username'],
        'password' => $login_data['password'],
        'jwt_info' => $jwt_info
    ];
}

?>
