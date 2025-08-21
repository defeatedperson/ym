<?php
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}
/**
 * MFA验证模块
 *
 * 功能说明：
 * 1. verify_mfa($username, $token)：根据用户名和身份验证器令牌，查询数据库用户表中的mfa设置，判断令牌是否正确。
 *    - 若未设置MFA，返回 ['valid'=>false, 'reason'=>'not_set']
 *    - 若验证失败，返回 ['valid'=>false, 'reason'=>'invalid']
 *    - 验证成功，返回 ['valid'=>true]
 */

if (!defined('DB_FILE')) {
    define('DB_FILE', __DIR__ . '/data/main.db');
}
if (!defined('ADMIN_TABLE')) {
    define('ADMIN_TABLE', 'users');
}

/**
 * 验证MFA令牌
 * @param string $username
 * @param string $token
 * @return array
 */
function get_mfa_encrypt_key() {
    $key = getenv('YKC_MFA_KEY');
    if ($key && strlen($key) >= 16) return $key;
    $keyFile = __DIR__ . '/data/mfa-key.txt';
    if (file_exists($keyFile)) {
        $key = trim(file_get_contents($keyFile));
        if ($key && strlen($key) >= 16) return $key;
    }
    $key = bin2hex(random_bytes(16));
    file_put_contents($keyFile, $key);
    return $key;
}

function decrypt_mfa_secret($encryptedSecret) {
    $key = get_mfa_encrypt_key();
    $decrypted = openssl_decrypt(base64_decode($encryptedSecret), 'AES-256-ECB', $key, OPENSSL_RAW_DATA);
    return $decrypted ? $decrypted : false;
}

function verify_mfa($username, $token) {
    if (!file_exists(DB_FILE)) return ['valid'=>false, 'reason'=>'db_missing'];
    $db = new SQLite3(DB_FILE);
    $stmt = $db->prepare("SELECT mfa_enabled, mfa_secret FROM " . ADMIN_TABLE . " WHERE username=?");
    $stmt->bindValue(1, $username, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    if (!$row) return ['valid'=>false, 'reason'=>'user_not_found'];
    if (empty($row['mfa_enabled']) || empty($row['mfa_secret'])) {
        return ['valid'=>false, 'reason'=>'not_set'];
    }
    if (!preg_match('/^\d{6}$/', $token)) return ['valid'=>false, 'reason'=>'invalid'];
    $encryptedSecret = $row['mfa_secret'];
    $base32Secret = decrypt_mfa_secret($encryptedSecret);
    if (!$base32Secret) return ['valid'=>false, 'reason'=>'decrypt_fail'];
    $window = 1;
    $valid = false;
    $time = floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        if ($token === _totp_code($base32Secret, $time + $i)) {
            $valid = true;
            break;
        }
    }
    return $valid ? ['valid'=>true] : ['valid'=>false, 'reason'=>'invalid'];
}

/**
 * 生成TOTP验证码（RFC6238，假设base32密钥）
 * @param string $secret
 * @param int $time
 * @return string
 */
function _totp_code($secret, $time) {
    $key = _base32_decode($secret);
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
 * base32解码
 * @param string $b32
 * @return string
 */
function _base32_decode($b32) {
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
?>