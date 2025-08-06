<?php
// 统一的安全头配置文件
// 为所有后端API接口提供标准化的安全响应头

function set_security_headers() {
    // 检测协议（开发环境可能是HTTP，生产环境应是HTTPS）
    $is_https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    
    // 基础CORS头设置
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
    header('Access-Control-Allow-Credentials: false');
    
    // 安全头设置
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // CSP策略 - 针对API接口的最小化策略
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    
    // HTTPS传输安全（仅在HTTPS环境下启用）
    if ($is_https) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }
    
    // 缓存控制 - API响应不应被缓存
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
}

// 处理OPTIONS预检请求
function handle_options_request() {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        set_security_headers();
        http_response_code(200);
        exit;
    }
}

// 在文件顶部调用的便捷函数
function init_api_security() {
    set_security_headers();
    handle_options_request();
}
?>
