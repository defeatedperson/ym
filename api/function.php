<?php

// 引入节点验证功能
require_once __DIR__ . '/node/function.php';
// 引入监控数据功能
require_once __DIR__ . '/monitor/function.php';

/**
 * 解析被控节点提交的JSON数据
 * 
 * @param string $json_data JSON格式的监控数据
 * @return array 解析结果，包含提取的数据
 */
function parse_monitor_data($json_data) {
    // 解码JSON数据
    $data = json_decode($json_data, true);
    
    // 检查JSON是否有效
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'status' => 'error',
            'message' => '无效的JSON数据: ' . json_last_error_msg()
        ];
    }
    
    // 检查必需的顶级键
    $required_keys = ['node_id', 'node_secret', 'node_config', 'node_load', 'timestamp'];
    foreach ($required_keys as $key) {
        if (!isset($data[$key])) {
            return [
                'status' => 'error',
                'message' => "缺少必需的字段: {$key}"
            ];
        }
    }
    
    // 1. 请求校验部分（node_id，node_secret）
    $request_validation = [
        'node_id' => $data['node_id'],
        'node_secret' => $data['node_secret']
    ];
    
    // 2. 节点配置部分（cpu_model，memory_size，disk_size）
    $node_config = $data['node_config'];
    $required_config_keys = ['cpu_model', 'memory_size', 'disk_size'];
    foreach ($required_config_keys as $key) {
        if (!isset($node_config[$key])) {
            return [
                'status' => 'error',
                'message' => "节点配置缺少必需的字段: {$key}"
            ];
        }
    }
    
    $node_configuration = [
        'cpu_model' => $node_config['cpu_model'],
        'memory_size' => $node_config['memory_size'],
        'disk_size' => $node_config['disk_size']
    ];
    
    // 3. 节点数据部分（node_load当中的数据和timestamp）
    $node_load = $data['node_load'];
    $required_load_keys = [
        'cpu_usage', 'memory_usage', 'upload_bandwidth', 
        'download_bandwidth', 'disk_usage'
    ];
    
    foreach ($required_load_keys as $key) {
        if (!isset($node_load[$key])) {
            return [
                'status' => 'error',
                'message' => "节点负载数据缺少必需的字段: {$key}"
            ];
        }
    }
    
    $node_data = [
        'cpu_usage' => floatval($node_load['cpu_usage']),
        'memory_usage' => floatval($node_load['memory_usage']),
        'upload_bandwidth' => floatval($node_load['upload_bandwidth']),
        'download_bandwidth' => floatval($node_load['download_bandwidth']),
        'disk_usage' => floatval($node_load['disk_usage']),
        'timestamp' => $data['timestamp']
    ];
    
    // 验证时间戳是否为有效数字
    if (!is_numeric($data['timestamp'])) {
        return [
            'status' => 'error',
            'message' => '时间戳无效'
        ];
    }
    
    // 返回解析后的数据
    return [
        'status' => 'ok',
        'message' => '数据解析成功',
        'request_validation' => $request_validation,
        'node_configuration' => $node_configuration,
        'node_data' => $node_data
    ];
}

/**
 * 验证节点请求是否合法
 * 
 * @param array $parsed_data 解析后的数据，包含node_id和node_secret
 * @return array 验证结果
 */
function verify_node_request($parsed_data) {
    // 检查必需的验证字段
    if (!isset($parsed_data['request_validation']['node_id']) || 
        !isset($parsed_data['request_validation']['node_secret'])) {
        return [
            'status' => 'error',
            'message' => '缺少节点验证必需的字段'
        ];
    }
    
    // 获取节点ID和密钥
    $node_id = $parsed_data['request_validation']['node_id'];
    $node_secret = $parsed_data['request_validation']['node_secret'];
    
    // 调用节点验证函数
    if (verify_node($node_id, $node_secret)) {
        return [
            'status' => 'ok',
            'message' => '节点验证成功'
        ];
    } else {
        return [
            'status' => 'error',
            'message' => '节点验证失败，无效的节点ID或密钥'
        ];
    }
}

/**
 * 保存节点监控数据
 * 
 * @param array $parsed_data 解析后的监控数据
 * @return array 保存结果
 */
function save_monitor_data($parsed_data) {
    // 检查必需的字段
    if (!isset($parsed_data['request_validation']['node_id']) || 
        !isset($parsed_data['node_data'])) {
        return [
            'status' => 'error',
            'message' => '缺少保存监控数据必需的字段'
        ];
    }
    
    // 获取节点ID
    $node_id = $parsed_data['request_validation']['node_id'];
    
    // 获取节点数据
    $node_data = $parsed_data['node_data'];
    
    // 提取各项监控数据
    $cpu_usage = $node_data['cpu_usage'];
    $memory_usage = $node_data['memory_usage'];
    $upload_bandwidth = $node_data['upload_bandwidth'];
    $download_bandwidth = $node_data['download_bandwidth'];
    $disk_usage = $node_data['disk_usage'];
    $timestamp = $node_data['timestamp'];
    
    // 调用监控数据写入函数
    $result = write_monitor_data(
        $node_id,
        $cpu_usage,
        $memory_usage,
        $upload_bandwidth,
        $download_bandwidth,
        $disk_usage,
        $timestamp
    );
    
    return $result;
}

/**
 * 清理离线超过24小时的节点数据
 * 
 * @param string $json_file JSON文件路径
 * @return array 清理结果
 */
function cleanup_offline_nodes($json_file) {
    // 检查文件是否存在
    if (!file_exists($json_file)) {
        return [
            'status' => 'ok',
            'message' => '文件不存在，无需清理',
            'cleaned_count' => 0
        ];
    }
    
    // 读取现有数据
    $json_content = file_get_contents($json_file);
    $frontend_data = json_decode($json_content, true);
    
    // 如果JSON解析失败，返回错误
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'status' => 'error',
            'message' => 'JSON文件格式错误',
            'cleaned_count' => 0
        ];
    }
    
    $current_time = time();
    $offline_threshold = 24 * 60 * 60; // 24小时（秒）
    $cleaned_count = 0;
    $updated_data = [];
    
    // 检查每个节点的最后更新时间
    foreach ($frontend_data as $node_id => $node_data) {
        // 检查是否存在update_time字段
        if (isset($node_data['update_time'])) {
            $last_update = $node_data['update_time'];
            $offline_duration = $current_time - $last_update;
            
            // 如果离线时间小于24小时，保留该节点
            if ($offline_duration < $offline_threshold) {
                $updated_data[$node_id] = $node_data;
            } else {
                $cleaned_count++;
            }
        } else {
            // 如果没有update_time字段，也移除（可能是旧数据）
            $cleaned_count++;
        }
    }
    
    // 如果有节点被清理，更新文件
    if ($cleaned_count > 0) {
        $json_content = json_encode($updated_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        if (file_put_contents($json_file, $json_content) !== false) {
            return [
                'status' => 'ok',
                'message' => "成功清理了 {$cleaned_count} 个离线节点",
                'cleaned_count' => $cleaned_count
            ];
        } else {
            return [
                'status' => 'error',
                'message' => '无法写入清理后的数据',
                'cleaned_count' => 0
            ];
        }
    }
    
    return [
        'status' => 'ok',
        'message' => '无需清理离线节点',
        'cleaned_count' => 0
    ];
}

/**
 * 更新或创建前台实时数据JSON文件
 * 
 * @param array $monitor_data 监控数据数组
 * @return array 操作结果
 */
function update_frontend_data($monitor_data) {
    // 确保数据目录存在
    $data_dir = __DIR__ . '/data';
    if (!is_dir($data_dir)) {
        mkdir($data_dir, 0755, true);
    }
    
    // JSON文件路径
    $json_file = $data_dir . '/home.json';
    
    // 在更新数据前先清理离线节点
    $cleanup_result = cleanup_offline_nodes($json_file);
    
    // 初始化数据数组
    $frontend_data = [];
    
    // 如果文件已存在，读取现有数据
    if (file_exists($json_file)) {
        $json_content = file_get_contents($json_file);
        $frontend_data = json_decode($json_content, true);
        
        // 如果JSON解析失败，重置为空数组
        if (json_last_error() !== JSON_ERROR_NONE) {
            $frontend_data = [];
        }
    }
    
    // 处理传入的监控数据
    foreach ($monitor_data as $node_data) {
        $node_id = $node_data['node_id'];
        
        // 通过节点ID获取节点名称
        $node_info = get_node_name_by_id($node_id);
        
        // 如果节点存在，更新数据
        if ($node_info['status'] === 'ok') {
            $frontend_data[$node_id] = [
                'name' => $node_info['data'],
                'cpu_model' => $node_data['cpu_model'],
                'memory_size' => $node_data['memory_size'],
                'disk_size' => $node_data['disk_size'],
                'cpu_usage' => $node_data['cpu_usage'],
                'memory_usage' => $node_data['memory_usage'],
                'disk_usage' => $node_data['disk_usage'],
                'upload_bandwidth' => $node_data['upload_bandwidth'],
                'download_bandwidth' => $node_data['download_bandwidth'],
                'update_time' => time() // 使用当前时间戳
            ];
        }
    }
    
    // 将数据写入JSON文件
    $json_content = json_encode($frontend_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    if (file_put_contents($json_file, $json_content) !== false) {
        $result = [
            'status' => 'ok',
            'message' => '前台实时数据文件更新成功',
            'file_path' => $json_file
        ];
        
        // 如果有清理动作，添加清理信息
        if ($cleanup_result['cleaned_count'] > 0) {
            $result['cleanup_info'] = $cleanup_result['message'];
            $result['cleaned_nodes'] = $cleanup_result['cleaned_count'];
        }
        
        return $result;
    } else {
        return [
            'status' => 'error',
            'message' => '无法写入前台实时数据文件'
        ];
    }
}

?>