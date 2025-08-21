<?php
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * 简化的安全响应头设置 - 只设置基本的HTTP安全头
 */
function set_security_headers() {
    // 防止点击劫持
    header('X-Frame-Options: DENY');
    
    // 内容类型嗅探保护
    header('X-Content-Type-Options: nosniff');
    
    // XSS保护
    header('X-XSS-Protection: 1; mode=block');
    
    // 基本的CORS设置
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        // HTTPS环境
        header('Strict-Transport-Security: max-age=31536000');
        header('Access-Control-Allow-Origin: https://' . $_SERVER['HTTP_HOST']);
    } else {
        // HTTP环境 - 开发模式
        header('Access-Control-Allow-Origin: *');
    }
    
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Credentials: true');
}
?>
