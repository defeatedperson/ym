<?php
// 节点安装模式API

// 引入安全头设置
require_once __DIR__ . '/auth/security-headers.php';

// 引入管理员权限验证函数
require_once __DIR__ . '/auth/admin_auth.php';

// 基础设置
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

try {
    // 验证管理员身份
    admin_auth_check();
    
    // 支持GET和POST
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['status' => 'error', 'message' => '仅支持GET和POST请求']);
        exit;
    }
    
    // 获取参数（支持GET和POST）
    $action = '';
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? '';
    } else {
        // POST请求
        $action = $_POST['action'] ?? '';
    }
    
    // 定义文件列表
    $files = ['setup.sh', 'ym.exe', 'ym'];
    
    // 定义源目录和目标目录
    $other_source = __DIR__ . '/other';
    $data_target = __DIR__ . '/data';
    
    // 路由处理
    switch ($action) {
        case 'status':
            // 查询安装模式状态（检查是否所有文件都存在）
            $enabled = true;
            foreach ($files as $file) {
                if (!file_exists("$data_target/$file")) {
                    $enabled = false;
                    break;
                }
            }
            echo json_encode([
                'status' => 'success',
                'enabled' => $enabled
            ]);
            break;
            
        case 'enable':
            // 开启安装模式（复制文件）
            $all_copied = true;
            $copy_errors = [];
            
            foreach ($files as $file) {
                $source_path = "$other_source/$file";
                $target_path = "$data_target/$file";
                
                // 检查源文件是否存在
                if (!file_exists($source_path)) {
                    $all_copied = false;
                    $copy_errors[] = "源文件不存在: $file";
                    continue;
                }
                
                // 检查目标文件是否已存在
                if (file_exists($target_path)) {
                    // 可以选择跳过或覆盖，这里选择覆盖
                    // 如果要跳过，可以取消下面的注释
                    // continue;
                }
                
                // 复制文件
                if (!copy($source_path, $target_path)) {
                    $all_copied = false;
                    $copy_errors[] = "复制失败: $file";
                }
            }
            
            if ($all_copied) {
                echo json_encode([
                    'status' => 'success',
                    'message' => '安装模式已开启'
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => '开启安装模式失败',
                    'errors' => $copy_errors
                ]);
            }
            break;
            
        case 'disable':
            // 关闭安装模式（删除文件）
            $all_deleted = true;
            $delete_errors = [];
            
            foreach ($files as $file) {
                $target_path = "$data_target/$file";
                
                // 检查文件是否存在
                if (!file_exists($target_path)) {
                    // 文件不存在，可以认为删除成功
                    continue;
                }
                
                // 删除文件
                if (!unlink($target_path)) {
                    $all_deleted = false;
                    $delete_errors[] = "删除失败: $file";
                }
            }
            
            if ($all_deleted) {
                echo json_encode([
                    'status' => 'success',
                    'message' => '安装模式已关闭'
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => '关闭安装模式失败',
                    'errors' => $delete_errors
                ]);
            }
            break;
            
        default:
            echo json_encode([
                'status' => 'error',
                'message' => '不支持的操作，请使用 status、enable 或 disable'
            ]);
            break;
    }
    
} catch (Exception $e) {
    error_log("Node Setup API Error: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => '服务器错误: ' . $e->getMessage()
    ]);
}
?>