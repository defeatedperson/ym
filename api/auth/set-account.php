<?php
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}
// 直接查询setup.php的数据库（main.db），无需引入setup.php

if (!defined('DB_FILE')) {
    define('DB_FILE', __DIR__ . '/data/main.db');
}
if (!defined('ADMIN_TABLE')) {
    define('ADMIN_TABLE', 'users');
}

/**
 * 查询账号的MFA状态和邮箱地址
 */
function get_account_mfa_and_email($username) {
    $db = new SQLite3(DB_FILE);
    $stmt = $db->prepare("SELECT mfa_enabled, email FROM " . ADMIN_TABLE . " WHERE username=?");
    $stmt->bindValue(1, $username, SQLITE3_TEXT);
    $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    $db->close();
    if ($result) {
        return [
            'status' => 'ok',
            'mfa_enabled' => (bool)$result['mfa_enabled'],
            'email' => $result['email']
        ];
    }
    return ['status' => 'not_found'];
}

/**
 * 修改账号名称
 */
function update_account_name($oldName, $newName) {
    $db = new SQLite3(DB_FILE);
    $stmt = $db->prepare("UPDATE " . ADMIN_TABLE . " SET username=? WHERE username=?");
    $stmt->bindValue(1, $newName, SQLITE3_TEXT);
    $stmt->bindValue(2, $oldName, SQLITE3_TEXT);
    $stmt->execute();
    $changed = $db->changes();
    $db->close();
    return $changed > 0 ? ['status' => 'ok'] : ['status' => 'not_found'];
}

/**
 * 修改邮箱地址
 */
function update_account_email($username, $newEmail) {
    $db = new SQLite3(DB_FILE);
    $stmt = $db->prepare("UPDATE " . ADMIN_TABLE . " SET email=? WHERE username=?");
    $stmt->bindValue(1, $newEmail, SQLITE3_TEXT);
    $stmt->bindValue(2, $username, SQLITE3_TEXT);
    $stmt->execute();
    $changed = $db->changes();
    $db->close();
    return $changed > 0 ? ['status' => 'ok'] : ['status' => 'not_found'];
}

/**
 * 修改密码（需验证旧密码）
 */
function update_account_password($username, $oldPassword, $newPassword) {
    $db = new SQLite3(DB_FILE);
    $stmt = $db->prepare("SELECT password FROM " . ADMIN_TABLE . " WHERE username=?");
    $stmt->bindValue(1, $username, SQLITE3_TEXT);
    $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$result) {
        $db->close();
        return ['status' => 'not_found'];
    }
    if (!password_verify($oldPassword, $result['password'])) {
        $db->close();
        return ['status' => 'fail'];
    }
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt2 = $db->prepare("UPDATE " . ADMIN_TABLE . " SET password=? WHERE username=?");
    $stmt2->bindValue(1, $newHash, SQLITE3_TEXT);
    $stmt2->bindValue(2, $username, SQLITE3_TEXT);
    $stmt2->execute();
    $changed = $db->changes();
    $db->close();
    return $changed > 0 ? ['status' => 'ok'] : ['status' => 'fail'];
}

/**
 * 关闭MFA
 */
function disable_account_mfa($username) {
    $db = new SQLite3(DB_FILE);
    $stmt = $db->prepare("UPDATE " . ADMIN_TABLE . " SET mfa_enabled=0, mfa_secret=NULL WHERE username=?");
    $stmt->bindValue(1, $username, SQLITE3_TEXT);
    $stmt->execute();
    $changed = $db->changes();
    $db->close();
    return $changed > 0 ? ['status' => 'ok'] : ['status' => 'not_found'];
}

/**
 * 生成MFA密钥但不启用（用于两步验证流程）
 */
function generate_mfa_secret($username) {
    // 仅生成密钥，不写入数据库
    $rawSecret = _base32_encode(random_bytes(20));
    return ['status' => 'ok', 'mfa_secret' => $rawSecret, 'username' => $username];
}

/**
 * 验证MFA验证码并启用
 */
function verify_and_enable_mfa($username, $secret, $code) {
    // 验证验证码格式
    if (!preg_match('/^\d{6}$/', $code)) {
        return ['status' => 'fail', 'message' => '验证码格式不正确'];
    }
    
    // 验证TOTP代码
    $window = 1;
    $valid = false;
    $time = floor(time() / 30);
    
    for ($i = -$window; $i <= $window; $i++) {
        if ($code === generate_totp_code($secret, $time + $i)) {
            $valid = true;
            break;
        }
    }
    
    if (!$valid) {
        return ['status' => 'fail', 'message' => '验证码错误或已过期'];
    }
    
    // 验证成功，启用MFA
    $db = new SQLite3(DB_FILE);
    $key = get_mfa_encrypt_key();
    $encryptedSecret = base64_encode(openssl_encrypt($secret, 'AES-256-ECB', $key, OPENSSL_RAW_DATA));
    $stmt = $db->prepare("UPDATE " . ADMIN_TABLE . " SET mfa_enabled=1, mfa_secret=? WHERE username=?");
    $stmt->bindValue(1, $encryptedSecret, SQLITE3_TEXT);
    $stmt->bindValue(2, $username, SQLITE3_TEXT);
    $stmt->execute();
    $changed = $db->changes();
    $db->close();
    
    return $changed > 0
        ? ['status' => 'ok', 'message' => 'MFA启用成功']
        : ['status' => 'fail', 'message' => 'MFA启用失败'];
}

/**
 * 开启MFA并生成加密密钥
 */

function get_mfa_encrypt_key() {
    // 优先从环境变量获取
    $key = getenv('YKC_MFA_KEY');
    if ($key && strlen($key) >= 16) return $key;
    // 如果本地有密钥文件则读取
    $keyFile = __DIR__ . '/data/mfa-key.txt';
    if (file_exists($keyFile)) {
        $key = trim(file_get_contents($keyFile));
        if ($key && strlen($key) >= 16) return $key;
    }
    // 自动生成一个随机密钥并保存
    $key = bin2hex(random_bytes(16));
    file_put_contents($keyFile, $key);
    return $key;
}

function enable_account_mfa($username) {
    $db = new SQLite3(DB_FILE);
    // 生成base32格式的密钥（与验证逻辑匹配）
    $rawSecret = _base32_encode(random_bytes(20));
    $key = get_mfa_encrypt_key();
    $encryptedSecret = base64_encode(openssl_encrypt($rawSecret, 'AES-256-ECB', $key, OPENSSL_RAW_DATA));
    $stmt = $db->prepare("UPDATE " . ADMIN_TABLE . " SET mfa_enabled=1, mfa_secret=? WHERE username=?");
    $stmt->bindValue(1, $encryptedSecret, SQLITE3_TEXT);
    $stmt->bindValue(2, $username, SQLITE3_TEXT);
    $stmt->execute();
    $changed = $db->changes();
    $db->close();
    return $changed > 0
        ? ['status' => 'ok', 'mfa_secret' => $rawSecret, 'username' => $username]
        : ['status' => 'not_found'];
}

/**
 * 解密MFA密钥（用于显示给用户）
 */
function decrypt_mfa_secret($encryptedSecret) {
    $key = get_mfa_encrypt_key();
    $decrypted = openssl_decrypt(base64_decode($encryptedSecret), 'AES-256-ECB', $key, OPENSSL_RAW_DATA);
    return $decrypted ? $decrypted : false;
}

/**
 * base32编码函数
 */
function _base32_encode($data) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    
    foreach (str_split($data) as $char) {
        $bits .= str_pad(base_convert(ord($char), 10, 2), 8, '0', STR_PAD_LEFT);
    }
    
    $result = '';
    foreach (str_split($bits, 5) as $chunk) {
        $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $result .= $alphabet[base_convert($chunk, 2, 10)];
    }
    
    return $result;
}

/**
 * 生成TOTP验证码
 */
function generate_totp_code($secret, $time) {
    $key = base32_decode_custom($secret);
    $msg = pack('N*', 0) . pack('N*', $time);
    $hash = hash_hmac('sha1', $msg, $key, true);
    $offset = ord($hash[19]) & 0xf;
    $code = (
        ((ord($hash[$offset]) & 0x7f) << 24) |
        ((ord($hash[$offset + 1]) & 0xff) << 16) |
        ((ord($hash[$offset + 2]) & 0xff) << 8) |
        (ord($hash[$offset + 3]) & 0xff)
    ) % 1000000;
    return str_pad($code, 6, '0', STR_PAD_LEFT);
}

/**
 * base32解码函数
 */
function base32_decode_custom($b32) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper($b32);
    $b32 = preg_replace('/[^A-Z2-7]/', '', $b32);
    $bits = '';
    foreach (str_split($b32) as $c) {
        $bits .= str_pad(base_convert(strpos($alphabet, $c), 10, 2), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $bytes .= chr(bindec($byte));
        }
    }
    return $bytes;
}