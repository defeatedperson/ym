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
    
    // 创建表
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

// 获取今日数据（原始数据，交给前端处理）
function get_today_data($node_id) {
    if (!is_numeric($node_id) || $node_id <= 0) {
        return ['status' => 'error', 'message' => '节点ID无效'];
    }
    
    try {
        $pdo = init_db();
        
        $today_start = strtotime('today');
        $today_end = strtotime('tomorrow') - 1;
        
        // 先查询数据总量
        $count_stmt = $pdo->prepare("
            SELECT COUNT(*) as total FROM monitor_data 
            WHERE id = ? AND timestamp >= ? AND timestamp <= ?
        ");
        $count_stmt->execute([intval($node_id), $today_start, $today_end]);
        $total = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        if ($total == 0) {
            return ['status' => 'ok', 'data' => [], 'count' => 0];
        }
        
        // 策略优化：后端预聚合 + 智能采样
        if ($total > 288) {
            // 方案1：时间段聚合（推荐）- 每5分钟取最大值
            $stmt = $pdo->prepare("
                SELECT 
                    id,
                    MAX(cpu_usage) as cpu_usage,
                    AVG(memory_usage) as memory_usage,
                    AVG(upload_bandwidth) as upload_bandwidth,
                    AVG(download_bandwidth) as download_bandwidth,
                    AVG(disk_usage) as disk_usage,
                    MIN(timestamp) as timestamp
                FROM monitor_data 
                WHERE id = ? AND timestamp >= ? AND timestamp <= ?
                GROUP BY (timestamp / 300)  -- 每5分钟一组
                ORDER BY timestamp ASC
                LIMIT 288
            ");
            $stmt->execute([intval($node_id), $today_start, $today_end]);
        } else {
            // 数据量不大，直接返回所有数据
            $stmt = $pdo->prepare("
                SELECT * FROM monitor_data 
                WHERE id = ? AND timestamp >= ? AND timestamp <= ?
                ORDER BY timestamp ASC
            ");
            $stmt->execute([intval($node_id), $today_start, $today_end]);
        }
        
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return ['status' => 'ok', 'data' => $data, 'count' => count($data)];
        
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => '查询失败: ' . $e->getMessage()];
    }
}

// 获取昨日数据（原始数据，交给前端处理）
function get_yesterday_data($node_id) {
    if (!is_numeric($node_id) || $node_id <= 0) {
        return ['status' => 'error', 'message' => '节点ID无效'];
    }
    
    try {
        $pdo = init_db();
        
        $yesterday_start = strtotime('yesterday');
        $yesterday_end = strtotime('today') - 1;
        
        // 先查询数据总量
        $count_stmt = $pdo->prepare("
            SELECT COUNT(*) as total FROM monitor_data 
            WHERE id = ? AND timestamp >= ? AND timestamp <= ?
        ");
        $count_stmt->execute([intval($node_id), $yesterday_start, $yesterday_end]);
        $total = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        if ($total == 0) {
            return ['status' => 'ok', 'data' => [], 'count' => 0];
        }
        
        // 如果数据量太大，进行简单采样，最多返回288条数据
        if ($total > 288) {
            // 使用LIMIT和OFFSET进行简单采样
            $step = ceil($total / 288);
            $sampled_data = [];
            
            for ($i = 0; $i < $total; $i += $step) {
                $stmt = $pdo->prepare("
                    SELECT * FROM monitor_data 
                    WHERE id = ? AND timestamp >= ? AND timestamp <= ?
                    ORDER BY timestamp ASC
                    LIMIT 1 OFFSET ?
                ");
                $stmt->execute([intval($node_id), $yesterday_start, $yesterday_end, $i]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $sampled_data[] = $row;
                }
            }
            
            return ['status' => 'ok', 'data' => $sampled_data, 'count' => count($sampled_data)];
        } else {
            // 数据量不大，直接返回所有数据
            $stmt = $pdo->prepare("
                SELECT * FROM monitor_data 
                WHERE id = ? AND timestamp >= ? AND timestamp <= ?
                ORDER BY timestamp ASC
            ");
            $stmt->execute([intval($node_id), $yesterday_start, $yesterday_end]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return ['status' => 'ok', 'data' => $data, 'count' => count($data)];
        }
        
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => '查询失败: ' . $e->getMessage()];
    }
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
        
        // 先查询数据总量
        $count_stmt = $pdo->prepare("
            SELECT COUNT(*) as total FROM monitor_data 
            WHERE id = ? AND timestamp >= ? AND timestamp <= ?
        ");
        $count_stmt->execute([intval($node_id), $week_start, $week_end]);
        $total = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        if ($total == 0) {
            return ['status' => 'ok', 'data' => [], 'count' => 0];
        }
        
        // 如果数据量太大，进行简单采样，最多返回336条数据
        if ($total > 336) {
            // 使用LIMIT和OFFSET进行简单采样
            $step = ceil($total / 336);
            $sampled_data = [];
            
            for ($i = 0; $i < $total; $i += $step) {
                $stmt = $pdo->prepare("
                    SELECT * FROM monitor_data 
                    WHERE id = ? AND timestamp >= ? AND timestamp <= ?
                    ORDER BY timestamp ASC
                    LIMIT 1 OFFSET ?
                ");
                $stmt->execute([intval($node_id), $week_start, $week_end, $i]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $sampled_data[] = $row;
                }
            }
            
            return ['status' => 'ok', 'data' => $sampled_data, 'count' => count($sampled_data)];
        } else {
            // 数据量不大，直接返回所有数据
            $stmt = $pdo->prepare("
                SELECT * FROM monitor_data 
                WHERE id = ? AND timestamp >= ? AND timestamp <= ?
                ORDER BY timestamp ASC
            ");
            $stmt->execute([intval($node_id), $week_start, $week_end]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return ['status' => 'ok', 'data' => $data, 'count' => count($data)];
        }
        
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => '查询失败: ' . $e->getMessage()];
    }
}
?>
