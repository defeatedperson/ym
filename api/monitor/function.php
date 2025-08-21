<?php
// 监控数据管理 - 简化版

require_once __DIR__ . '/../auth/ip-cail.php';
require_once __DIR__ . '/../auth/ip-ban.php';
require_once __DIR__ . '/../auth/jwt.php';
require_once __DIR__ . '/../auth/safe-input.php';
require_once __DIR__ . '/../auth/admin_auth.php'; // 引入共享的认证函数


// 监控数据库路径
const MONITOR_DB_PATH = __DIR__ . '/data/monitor.db';

// 初始化数据库
function init_db() {
    $data_dir = dirname(MONITOR_DB_PATH);
    if (!is_dir($data_dir)) {
        mkdir($data_dir, 0755, true);
    }

    $pdo = new PDO('sqlite:' . MONITOR_DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA synchronous=NORMAL');
    
    // 创建表 - 简化结构
    $pdo->exec("CREATE TABLE IF NOT EXISTS monitor_data (
        id INTEGER NOT NULL,
        cpu_usage REAL DEFAULT 0,
        memory_usage REAL DEFAULT 0,
        upload_bandwidth REAL DEFAULT 0,
        download_bandwidth REAL DEFAULT 0,
        disk_usage REAL DEFAULT 0,
        timestamp INTEGER NOT NULL,
        PRIMARY KEY (id, timestamp)
    )");
    
    // 创建索引
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_id_time ON monitor_data(id, timestamp DESC)");
    
    return $pdo;
}

// 写入监控数据
function write_monitor_data($node_id, $cpu_usage, $memory_usage, $upload_bandwidth, $download_bandwidth, $disk_usage, $timestamp = null) {
    if ($timestamp === null) {
        $timestamp = time();
    }
    
    // 简单验证
    if (!is_numeric($node_id) || $node_id <= 0) {
        return ['status' => 'error', 'message' => '节点ID无效'];
    }
    
    try {
        $pdo = init_db();
        
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO monitor_data 
            (id, cpu_usage, memory_usage, upload_bandwidth, download_bandwidth, disk_usage, timestamp) 
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute([
            intval($node_id), 
            floatval($cpu_usage), 
            floatval($memory_usage), 
            floatval($upload_bandwidth), 
            floatval($download_bandwidth), 
            floatval($disk_usage), 
            intval($timestamp)
        ]);
        
        // 清理7天前数据
        $seven_days_ago = time() - (7 * 24 * 3600);
        $pdo->prepare("DELETE FROM monitor_data WHERE timestamp < ?")->execute([$seven_days_ago]);
        
        return ['status' => 'ok'];
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => '写入失败'];
    }
}

// 获取最新数据（实时监控用）
function get_latest_data($node_id) {
    if (!is_numeric($node_id) || $node_id <= 0) {
        return ['status' => 'error', 'message' => '节点ID无效'];
    }
    
    try {
        $pdo = init_db();
        $stmt = $pdo->prepare("SELECT * FROM monitor_data WHERE id = ? ORDER BY timestamp DESC LIMIT 1");
        $stmt->execute([intval($node_id)]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($data) {
            return ['status' => 'ok', 'data' => $data];
        } else {
            return ['status' => 'not_found', 'message' => '暂无数据'];
        }
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => '查询失败'];
    }
}

// 函数已移除，统一使用 get_day_data 函数，参数 days_before=0 表示今日数据

// 函数已移除，统一使用 get_day_data 函数，参数 days_before=1 表示昨日数据

// 获取指定天数前的数据（5分钟聚合，取CPU最大值）
function get_day_data($node_id, $days_before = 0) {
    if (!is_numeric($node_id) || $node_id <= 0) {
        return ['status' => 'error', 'message' => '节点ID无效'];
    }
    
    // 验证天数参数
    $days_before = intval($days_before);
    if ($days_before < 0 || $days_before > 7) {
        $days_before = 0; // 默认为今天
    }
    
    try {
        $pdo = init_db();
        
        // 设置时区为中国标准时间，确保时间计算正确
        date_default_timezone_set('Asia/Shanghai');
        
        // 计算目标日期的开始和结束时间戳
        if ($days_before == 0) {
            // 今天 - 明确指定从当地时间0点开始
            $day_start = mktime(0, 0, 0, date('n'), date('j'), date('Y'));
            $day_end = mktime(23, 59, 59, date('n'), date('j'), date('Y'));
        } else {
            // N天前
            $target_date = time() - ($days_before * 24 * 60 * 60);
            $day_start = mktime(0, 0, 0, date('n', $target_date), date('j', $target_date), date('Y', $target_date));
            $day_end = mktime(23, 59, 59, date('n', $target_date), date('j', $target_date), date('Y', $target_date));
        }
        
        // 查询指定日期的所有数据
        $stmt = $pdo->prepare("
            SELECT * FROM monitor_data 
            WHERE id = ? AND timestamp >= ? AND timestamp <= ?
            ORDER BY timestamp ASC
        ");
        $stmt->execute([intval($node_id), $day_start, $day_end]);
        $raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 进行5分钟聚合处理
        $aggregated_data = aggregate_data_by_5_minutes($raw_data);
        
        return ['status' => 'ok', 'data' => $aggregated_data, 'count' => count($aggregated_data)];
        
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => '查询失败: ' . $e->getMessage()];
    }
}

// 5分钟数据聚合函数（取CPU最大值作为聚合标准）
function aggregate_data_by_5_minutes($raw_data) {
    if (empty($raw_data)) {
        return [];
    }
    
    $aggregated = [];
    $interval = 5 * 60; // 5分钟间隔（秒）
    
    // 按5分钟时间段分组
    $groups = [];
    foreach ($raw_data as $item) {
        $timestamp = intval($item['timestamp']);
        // 计算所属的5分钟时间段起始点
        $group_start = floor($timestamp / $interval) * $interval;
        
        if (!isset($groups[$group_start])) {
            $groups[$group_start] = [];
        }
        $groups[$group_start][] = $item;
    }
    
    // 对每个5分钟时间段进行聚合
    foreach ($groups as $group_start => $group_data) {
        // 找到CPU使用率最大的记录
        $max_cpu_record = null;
        $max_cpu_value = -1;
        
        foreach ($group_data as $record) {
            $cpu_value = floatval($record['cpu_usage']);
            // 如果CPU值更大，或者CPU值相等但时间戳更晚（取后面的最大值）
            if ($cpu_value > $max_cpu_value || 
                ($cpu_value == $max_cpu_value && intval($record['timestamp']) > intval($max_cpu_record['timestamp']))) {
                $max_cpu_value = $cpu_value;
                $max_cpu_record = $record;
            }
        }
        
        if ($max_cpu_record) {
            // 使用CPU最大值对应的记录作为该时间段的代表数据
            $aggregated[] = [
                'id' => $max_cpu_record['id'],
                'cpu_usage' => $max_cpu_record['cpu_usage'],
                'memory_usage' => $max_cpu_record['memory_usage'],
                'upload_bandwidth' => $max_cpu_record['upload_bandwidth'],
                'download_bandwidth' => $max_cpu_record['download_bandwidth'],
                'disk_usage' => $max_cpu_record['disk_usage'],
                'timestamp' => $max_cpu_record['timestamp']
            ];
        }
    }
    
    // 按时间戳排序
    usort($aggregated, function($a, $b) {
        return intval($a['timestamp']) - intval($b['timestamp']);
    });
    
    return $aggregated;
}

// 获取近7天数据（原始数据，交给前端处理）  
function get_week_data($node_id) {
    if (!is_numeric($node_id) || $node_id <= 0) {
        return ['status' => 'error', 'message' => '节点ID无效'];
    }
    
    try {
        $pdo = init_db();
        
        $week_start = strtotime('-6 days', strtotime('today'));
        $week_end = strtotime('tomorrow') - 1;
        
        // 简化查询，直接返回所有数据
        $stmt = $pdo->prepare("
            SELECT * FROM monitor_data 
            WHERE id = ? AND timestamp >= ? AND timestamp <= ?
            ORDER BY timestamp ASC
        ");
        $stmt->execute([intval($node_id), $week_start, $week_end]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return ['status' => 'ok', 'data' => $data, 'count' => count($data)];
        
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => '查询失败: ' . $e->getMessage()];
    }
}
?>
