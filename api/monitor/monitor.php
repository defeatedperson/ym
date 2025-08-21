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
    
    // 检查是否是获取最新数据的请求
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'latest') {
        $node_id = isset($_GET['node_id']) ? intval($_GET['node_id']) : 0;
        
        if ($node_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => '节点ID无效']);
            exit;
        }
        
        $result = get_latest_data($node_id);
        echo json_encode(['status' => 'ok', 'data' => $result]);
        exit;
    }
    
    // 获取参数（支持GET和POST）
    $node_id = '';
    $days = 0;
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $node_id = $_GET['node_id'] ?? '';
        $days = $_GET['days'] ?? 0;
    } else {
        // POST请求
        $node_id = $_POST['node_id'] ?? '';
        $days = $_POST['days'] ?? 0;
    }
    
    // 验证节点ID
    if (empty($node_id) || !is_numeric($node_id)) {
        echo json_encode(['status' => 'error', 'message' => '节点ID无效']);
        exit;
    }
    
    // 验证days参数
    if (!is_numeric($days) || $days < 0 || $days > 7) {
        echo json_encode(['status' => 'error', 'message' => '天数参数无效，必须为0-7之间的整数']);
        exit;
    }
    
    // 获取指定天数的数据（0表示今日，1表示昨日，2-7表示N天前）
    $result = get_day_data($node_id, intval($days));
    
    echo json_encode(['status' => 'ok', 'data' => $result]);
    
} catch (Exception $e) {
    error_log("Monitor API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => '服务器错误']);
}
?>
