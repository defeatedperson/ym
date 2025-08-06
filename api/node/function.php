<?php
// 节点管理API - 管理员专用接口

require_once __DIR__ . '/../auth/ip-cail.php';
require_once __DIR__ . '/../auth/ip-ban.php';
require_once __DIR__ . '/../auth/jwt.php';
require_once __DIR__ . '/../auth/safe-input.php';
require_once __DIR__ . '/../auth/admin_auth.php'; // 引入共享的认证函数


// 节点数据库文件路径
const NODE_DB_PATH = __DIR__ . '/data/nodes.db';

// 初始化数据库
function init_database() {
    // 创建数据目录（如果不存在）
    $data_dir = dirname(NODE_DB_PATH);
    if (!is_dir($data_dir)) {
        mkdir($data_dir, 0755, true);
    }

    // 创建数据库连接
    $pdo = new PDO('sqlite:' . NODE_DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 创建节点表
    $sql = "CREATE TABLE IF NOT EXISTS nodes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        ip TEXT NOT NULL,
        secret TEXT NOT NULL
    )";
    
    $pdo->exec($sql);
    
    // 为secret字段添加索引以提升verify_node性能
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_secret ON nodes(secret)");
    
    return $pdo;
}

// 添加节点
function add_node($name, $ip, $secret) {
    // 安全验证输入
    $name = validate_common_input($name);
    $ip = validate_common_input($ip);
    $secret = validate_common_input($secret);
    
    if ($name === false || $ip === false || $secret === false) {
        return ['status' => 'error', 'message' => '输入参数无效'];
    }
    
    try {
        $pdo = init_database();
        $stmt = $pdo->prepare('INSERT INTO nodes (name, ip, secret) VALUES (?, ?, ?)');
        $stmt->execute([$name, $ip, $secret]);
        
        return ['status' => 'ok', 'message' => '节点添加成功', 'id' => $pdo->lastInsertId()];
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => '数据库操作失败: ' . $e->getMessage()];
    }
}

// 删除节点
function delete_node($id) {
    // 验证ID为整数
    if (!is_numeric($id) || $id <= 0) {
        return ['status' => 'error', 'message' => '无效的节点ID'];
    }
    
    try {
        $pdo = init_database();
        $stmt = $pdo->prepare('DELETE FROM nodes WHERE id = ?');
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            return ['status' => 'ok', 'message' => '节点删除成功'];
        } else {
            return ['status' => 'error', 'message' => '未找到指定的节点'];
        }
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => '数据库操作失败: ' . $e->getMessage()];
    }
}

// 修改节点
function update_node($id, $name, $ip, $secret) {
    // 验证ID为整数
    if (!is_numeric($id) || $id <= 0) {
        return ['status' => 'error', 'message' => '无效的节点ID'];
    }
    
    // 安全验证输入
    $name = validate_common_input($name);
    $ip = validate_common_input($ip);
    $secret = validate_common_input($secret);
    
    if ($name === false || $ip === false || $secret === false) {
        return ['status' => 'error', 'message' => '输入参数无效'];
    }
    
    try {
        $pdo = init_database();
        $stmt = $pdo->prepare('UPDATE nodes SET name = ?, ip = ?, secret = ? WHERE id = ?');
        $stmt->execute([$name, $ip, $secret, $id]);
        
        if ($stmt->rowCount() > 0) {
            return ['status' => 'ok', 'message' => '节点更新成功'];
        } else {
            return ['status' => 'error', 'message' => '未找到指定的节点'];
        }
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => '数据库操作失败: ' . $e->getMessage()];
    }
}

// 查询所有节点（包含base64_decode编码后的密钥）
function get_all_nodes() {
    try {
        $pdo = init_database();
        $stmt = $pdo->query('SELECT id, name, ip, secret FROM nodes ORDER BY id');
        $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 对密钥进行编码处理
        foreach ($nodes as &$node) {
            $node['secret'] = base64_encode($node['secret']);
        }
        
        return ['status' => 'ok', 'data' => $nodes];
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => '数据库操作失败: ' . $e->getMessage()];
    }
}
// 验证节点ID和密钥
function verify_node($id, $encoded_secret) {
    // 验证ID为整数
    if (!is_numeric($id) || $id <= 0) {
        return false;
    }
    
    // 解码密钥
    $secret = base64_decode($encoded_secret);
    if ($secret === false) {
        return false;
    }
    
    try {
        $pdo = init_database();
        $stmt = $pdo->prepare('SELECT 1 FROM nodes WHERE id = ? AND secret = ? LIMIT 1');
        $stmt->execute([$id, $secret]);
        $result = $stmt->fetchColumn();
        
        return $result !== false;
    } catch (Exception) {
        // 发生错误时返回false
        return false;
    }
}

// 获取所有节点ID和名称
function get_node_ids_and_names() {
    try {
        $pdo = init_database();
        $stmt = $pdo->query('SELECT id, name FROM nodes ORDER BY id');
        $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return ['status' => 'ok', 'data' => $nodes];
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => '数据库操作失败: ' . $e->getMessage()];
    }
}

// 根据节点ID获取节点名称
function get_node_name_by_id($id) {
    // 验证ID为整数
    if (!is_numeric($id) || $id <= 0) {
        return ['status' => 'error', 'message' => '无效的节点ID'];
    }
    
    try {
        $pdo = init_database(); // 使用已有的数据库初始化函数
        $stmt = $pdo->prepare('SELECT name FROM nodes WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return ['status' => 'ok', 'data' => $result['name']];
        } else {
            return ['status' => 'error', 'message' => '未找到指定的节点'];
        }
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => '数据库操作失败: ' . $e->getMessage()];
    }
}
?>