<?php
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}
/**
 * IP校准函数模块
 *
 * 功能说明：
 * 1. get_real_ip()：自动校准用户真实IP，适配常见反向代理场景（如 X-Forwarded-For、X-Real-IP）。
 *    - 建议所有涉及IP的模块统一调用本函数获取用户IP。
 */

/**
 * 获取用户真实IP（适配反向代理）
 * @return string
 */
function get_real_ip() {
    $ip = '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // 可能有多个IP，取第一个非unknown
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        foreach ($ips as $v) {
            $v = trim($v);
            if ($v && strtolower($v) !== 'unknown') {
                $ip = $v;
                break;
            }
        }
    }
    if (!$ip && !empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = $_SERVER['HTTP_X_REAL_IP'];
    }
    if (!$ip && !empty($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    // 校验IP格式
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }
    return '0.0.0.0';
}
?>