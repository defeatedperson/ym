<?php
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}
/**
 * 账号密码验证模块
 *
 * 功能说明：
 * 1. verify_account($username, $password)：查询setup.php创建的数据库，验证账号密码是否正确，并返回是否需要MFA验证。
 *    - 返回 ['valid'=>true, 'need_mfa'=>bool] 或 ['valid'=>false, 'reason'=>string]
 */

if (!defined('DB_FILE')) {
    define('DB_FILE', __DIR__ . '/data/main.db');
}
if (!defined('ADMIN_TABLE')) {
    define('ADMIN_TABLE', 'users');
}

/**
 * 验证账号密码
 * @param string $username
 * @param string $password
 * @return array
 */
function verify_account($username, $password) {
    if (!file_exists(DB_FILE)) return ['valid'=>false, 'reason'=>'db_missing'];
    $db = new SQLite3(DB_FILE);
    try {
        $stmt = $db->prepare("SELECT password, mfa_enabled FROM " . ADMIN_TABLE . " WHERE username=?");
        $stmt->bindValue(1, $username, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        if (!$row) {
            $db->close();
            return ['valid'=>false, 'reason'=>'user_not_found'];
        }
        if (!password_verify($password, $row['password'])) {
            $db->close();
            return ['valid'=>false, 'reason'=>'password_error'];
        }
        $need_mfa = !empty($row['mfa_enabled']);
        $db->close();
        return ['valid'=>true, 'need_mfa'=>$need_mfa];
    } catch (Exception $e) {
        $db->close();
        return ['valid'=>false, 'reason'=>'db_error'];
    }
}
?>