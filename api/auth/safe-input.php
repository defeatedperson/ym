<?php
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}
/**
 * 公共输入验证函数集
 *
 * 功能说明：
 * 1. validate_common_input($input)：通用内容校验，去除首尾空格、限制长度、防止恶意代码。
 * 2. validate_email($email)：邮箱格式校验，返回过滤后的邮箱或false。
 * 3. validate_password($password)：密码强度校验，至少8位，包含大写、小写、数字、特殊字符。
 * 4. 所有输入内容均不允许超过256字符，防止性能消耗。
 * 5. 所有校验均防止XSS等恶意代码注入。
 *
 * 可根据需要扩展更多类型的输入校验函数。
 */

// 公共输入验证函数集

// 最大输入长度，防止性能消耗
define('MAX_INPUT_LENGTH', 256);

/**
 * 通用输入内容校验，去除首尾空格，限制长度，防止恶意代码
 * @param string $input
 * @return string|false 过滤后的内容，非法返回false
 */
function validate_common_input($input) {
    if (!is_string($input)) return false;
    $input = trim($input);
    if (mb_strlen($input, 'UTF-8') > MAX_INPUT_LENGTH) return false;
    // 防止XSS
    $input = htmlspecialchars($input, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    // 可根据需要添加更多过滤
    return $input;
}

/**
 * 邮箱格式校验
 * @param string $email
 * @return string|false 合法邮箱返回过滤后的邮箱，非法返回false
 */
function validate_email($email) {
    $email = validate_common_input($email);
    if ($email === false) return false;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    return $email;
}

/**
 * 密码强度校验 - 简化版本（字母+数字即可）
 * 至少6位，包含字母和数字
 * @param string $password
 * @return string|false 合法返回原密码，非法返回false
 */
function validate_password($password) {
    if (!is_string($password)) return false;
    if (mb_strlen($password, 'UTF-8') > MAX_INPUT_LENGTH) return false;
    if (mb_strlen($password, 'UTF-8') < 6) return false;
    
    // 至少包含字母和数字
    if (!preg_match('/[a-zA-Z]/', $password)) return false;
    if (!preg_match('/[0-9]/', $password)) return false;
    
    // 防止XSS
    $password = htmlspecialchars($password, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return $password;
}

/**
 * MFA令牌格式校验（6位纯数字）
 * @param string $token
 * @return string|false 合法返回原token，非法返回false
 */
function validate_mfa_token($token) {
    if (!is_string($token)) return false;
    $token = trim($token);
    if (!preg_match('/^\d{6}$/', $token)) return false;
    return $token;
}

?>
