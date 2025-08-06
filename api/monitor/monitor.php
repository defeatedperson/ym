<?php
// 监控数据API - 简化版

require_once __DIR__ . '/function.php';
require_once __DIR__ . '/../auth/security-headers.php';

// 基础设置
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

try {
    // 验证身份
    admin_auth_check();
    
    // 支持GET和POST
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['status' => 'error', 'message' => '仅支持GET和POST请求']);
        exit;
    }
    
    // 获取参数（支持GET和POST）
    $action = '';
    $node_id = '';
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? '';
        $node_id = $_GET['node_id'] ?? '';
    } else {
        // POST请求
        $action = $_POST['action'] ?? '';
        $node_id = $_POST['node_id'] ?? '';
    }
    
    // 验证节点ID
    if (empty($node_id) || !is_numeric($node_id)) {
        echo json_encode(['status' => 'error', 'message' => '节点ID无效']);
        exit;
    }
    
    // 路由处理
    switch ($action) {
        case 'latest':
            $result = get_latest_data($node_id);
            break;
            
        case 'today':
            $result = get_today_data($node_id);
            break;
            
        case 'yesterday':
            $result = get_yesterday_data($node_id);
            break;
            
        case 'week':
            $result = get_week_data($node_id);
            break;
            
        default:
            $result = ['status' => 'error', 'message' => '不支持的操作'];
            break;
    }
    
    echo json_encode($result);
    
} catch (Exception $e) {
    error_log("Monitor API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => '服务器错误']);
}
?>
