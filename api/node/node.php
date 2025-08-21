<?php
// 引入功能函数
require_once __DIR__ . '/function.php';
require_once __DIR__ . '/../auth/security-headers.php';

// 管理员鉴权
admin_auth_check();

// 获取请求方法
$method = $_SERVER['REQUEST_METHOD'];

// 根据请求方法处理请求
switch ($method) {
    case 'GET':
        // 获取所有节点信息
        if (isset($_GET['ids_only']) && $_GET['ids_only'] === 'true') {
            // 只获取节点ID和名称
            $result = get_node_ids_and_names();
        } else {
            // 获取所有节点信息
            $result = get_all_nodes();
        }
        header('Content-Type: application/json');
        echo json_encode($result);
        break;
        
    case 'POST':
        // 检查是否只获取节点ID和名称
        if (isset($_POST['ids_only']) && $_POST['ids_only'] === 'true') {
            // 只获取节点ID和名称
            $result = get_node_ids_and_names();
            header('Content-Type: application/json');
            echo json_encode($result);
            break;
        }
        
        // 根据action参数确定操作类型
        $action = isset($_POST['action']) ? $_POST['action'] : '';
        
        switch ($action) {
            case 'add':
                // 新增节点
                if (!isset($_POST['name']) || !isset($_POST['ip']) || !isset($_POST['secret'])) {
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'error', 'message' => '缺少必要参数']);
                    break;
                }
                
                $result = add_node($_POST['name'], $_POST['ip'], $_POST['secret']);
                header('Content-Type: application/json');
                echo json_encode($result);
                break;
                
            case 'update':
                // 更新节点
                if (!isset($_POST['id']) || !isset($_POST['name']) || !isset($_POST['ip']) || !isset($_POST['secret'])) {
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'error', 'message' => '缺少必要参数']);
                    break;
                }
                
                $result = update_node($_POST['id'], $_POST['name'], $_POST['ip'], $_POST['secret']);
                header('Content-Type: application/json');
                echo json_encode($result);
                break;
                
            case 'delete':
                // 删除节点
                if (!isset($_POST['id'])) {
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'error', 'message' => '缺少节点ID']);
                    break;
                }
                
                $result = delete_node($_POST['id']);
                header('Content-Type: application/json');
                echo json_encode($result);
                break;
                
            default:
                // 兼容旧版本：没有action参数时，默认为新增节点
                if (!isset($_POST['name']) || !isset($_POST['ip']) || !isset($_POST['secret'])) {
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'error', 'message' => '缺少必要参数']);
                    break;
                }
                
                $result = add_node($_POST['name'], $_POST['ip'], $_POST['secret']);
                header('Content-Type: application/json');
                echo json_encode($result);
                break;
        }
        break;
        
    default:
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => '不支持的请求方法']);
        break;
}
?>