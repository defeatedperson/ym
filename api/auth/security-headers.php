<?php
/**
 * 安全响应头设置 - 针对开发和生产环境优化
 */

// 防止点击劫持
header('X-Frame-Options: DENY');

// 内容类型嗅探保护
header('X-Content-Type-Options: nosniff');

// XSS保护
header('X-XSS-Protection: 1; mode=block');

// 宽松的内容安全策略 (适用于Vue开发环境)
header("Content-Security-Policy: default-src 'self' 'unsafe-inline' 'unsafe-eval' data: blob:; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https: http:; font-src 'self' https: http: data:; connect-src 'self' ws: wss: http: https:;");

// 引用者策略
header('Referrer-Policy: strict-origin-when-cross-origin');

// 条件性HTTPS传输安全 (仅在HTTPS环境下启用)
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    // HTTPS环境下禁止跨域访问
    $current_origin = (isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '');
    $current_host = 'https://' . $_SERVER['HTTP_HOST'];
    
    // 只允许同域名访问
    if (!empty($current_origin) && $current_origin !== $current_host) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'forbidden', 'message' => 'Cross-origin requests not allowed in HTTPS mode']);
        exit;
    }
    
    // 设置严格的CORS策略
    header('Access-Control-Allow-Origin: ' . $current_host);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    
    // HTTPS环境下的严格Cookie设置
    ini_set('session.cookie_secure', '1');
} else {
    // HTTP环境下的提醒头（非标准，用于开发提醒）
    header('X-Security-Warning: This connection is not secure. Login sessions may not persist properly.');
    // 开发环境允许跨域
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}

// 权限策略 (宽松设置)
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

// 总是设置安全的session配置
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax'); // Lax而不是Strict，提高兼容性
ini_set('session.use_strict_mode', '1');
?>
