<?php
// 引入功能函数
require_once __DIR__ . '/function.php';

// 设置响应头
header('Content-Type: application/json');

// 获取POST数据
$json_data = file_get_contents('php://input');

// 解析JSON数据
$parsed_data = parse_monitor_data($json_data);

if ($parsed_data['status'] !== 'ok') {
    echo json_encode([
        'status' => 'error',
        'message' => '数据解析失败: ' . $parsed_data['message']
    ]);
    exit;
}

// 验证节点信息
$verification_result = verify_node_request($parsed_data);

if ($verification_result['status'] !== 'ok') {
    echo json_encode([
        'status' => 'error',
        'message' => '节点验证失败: ' . $verification_result['message']
    ]);
    exit;
}

// 创建缓存目录
$cache_dir = __DIR__ . '/data/cache';
if (!is_dir($cache_dir)) {
    mkdir($cache_dir, 0755, true);
}

// 生成唯一的缓存文件名
$cache_file = $cache_dir . '/node_' . $parsed_data['request_validation']['node_id'] . '_' . uniqid() . '.json';

// 将数据写入缓存文件
if (file_put_contents($cache_file, $json_data) === false) {
    echo json_encode([
        'status' => 'error',
        'message' => '无法写入缓存文件'
    ]);
    exit;
}

// 处理缓存中的数据
process_cache_data();

echo json_encode([
    'status' => 'ok',
    'message' => '数据已接收并缓存'
]);

/**
 * 处理缓存中的数据
 */
function process_cache_data() {
    $cache_dir = __DIR__ . '/data/cache';
    
    // 检查缓存目录是否存在
    if (!is_dir($cache_dir)) {
        return;
    }
    
    // 获取所有缓存文件
    $cache_files = glob($cache_dir . '/node_*.json');
    
    // 如果没有缓存文件，直接返回
    if (empty($cache_files)) {
        return;
    }
    
    // 处理每个缓存文件
    foreach ($cache_files as $file) {
        // 读取文件内容
        $json_data = file_get_contents($file);
        
        // 解析数据
        $parsed_data = parse_monitor_data($json_data);
        
        if ($parsed_data['status'] === 'ok') {
            // 准备前端数据
            $monitor_data = [
                [
                    'node_id' => $parsed_data['request_validation']['node_id'],
                    'cpu_model' => $parsed_data['node_configuration']['cpu_model'],
                    'memory_size' => $parsed_data['node_configuration']['memory_size'],
                    'disk_size' => $parsed_data['node_configuration']['disk_size'],
                    'cpu_usage' => $parsed_data['node_data']['cpu_usage'],
                    'memory_usage' => $parsed_data['node_data']['memory_usage'],
                    'disk_usage' => $parsed_data['node_data']['disk_usage'],
                    'upload_bandwidth' => $parsed_data['node_data']['upload_bandwidth'],
                    'download_bandwidth' => $parsed_data['node_data']['download_bandwidth'],
                    'monthly_traffic' => isset($parsed_data['monthly_traffic']) ? $parsed_data['monthly_traffic'] : null
                ]
            ];
            
            // 写入SQLite数据库（用于历史数据和图表显示）
            $sqlite_result = write_monitor_data(
                $parsed_data['request_validation']['node_id'],
                $parsed_data['node_data']['cpu_usage'],
                $parsed_data['node_data']['memory_usage'],
                $parsed_data['node_data']['upload_bandwidth'],
                $parsed_data['node_data']['download_bandwidth'],
                $parsed_data['node_data']['disk_usage'],
                $parsed_data['node_data']['timestamp']
            );
            
            // 更新前端JSON文件
            $frontend_result = update_frontend_data($monitor_data);
            
            // 如果数据处理成功（JSON和SQLite都成功），删除缓存文件
            if ($frontend_result['status'] === 'ok' && $sqlite_result['status'] === 'ok') {
                unlink($file);
            } elseif ($sqlite_result['status'] !== 'ok') {
                // 记录SQLite写入失败的错误
                error_log("SQLite写入失败 - 节点ID: {$parsed_data['request_validation']['node_id']}, 错误: {$sqlite_result['message']}");
            }
        }
    }
}
?>