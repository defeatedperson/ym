<?php
// 站点配置管理API - 管理员专用接口

// 引入安全头设置
require_once __DIR__ . '/auth/security-headers.php';

// 引入统一的管理员权限验证函数
require_once __DIR__ . '/auth/admin_auth.php';

// 基础设置
header('Content-Type: application/json');

$data_dir = __DIR__ . '/data/';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'method_not_allowed']);
    exit;
}

// 验证管理员身份
admin_auth_check();

require_once __DIR__ . '/auth/safe-input.php';

$action = isset($_POST['action']) ? validate_common_input($_POST['action']) : '';

switch ($action) {
    case 'get_site_config':
        // 获取站点配置
        $site_file = $data_dir . 'site.json';
        if (file_exists($site_file)) {
            $content = file_get_contents($site_file);
            $config = json_decode($content, true);
            if ($config !== null) {
                echo json_encode(['status' => 'ok', 'data' => $config]);
            } else {
                echo json_encode(['status' => 'error', 'message' => '站点配置文件格式错误']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => '站点配置文件不存在']);
        }
        break;

    case 'update_site_config':
        // 更新站点配置
        $config_data = isset($_POST['config']) ? $_POST['config'] : '';
        if (!$config_data) {
            echo json_encode(['status' => 'error', 'message' => '配置数据不能为空']);
            break;
        }
        
        // 验证JSON格式
        $config = json_decode($config_data, true);
        if ($config === null) {
            echo json_encode(['status' => 'error', 'message' => 'JSON格式错误']);
            break;
        }
        
        // 保存到文件
        $site_file = $data_dir . 'site.json';
        if (file_put_contents($site_file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
            echo json_encode(['status' => 'ok', 'message' => '站点配置更新成功']);
        } else {
            echo json_encode(['status' => 'error', 'message' => '保存站点配置失败']);
        }
        break;

    case 'get_menu_config':
        // 获取菜单配置
        $menu_file = $data_dir . 'menu.json';
        if (file_exists($menu_file)) {
            $content = file_get_contents($menu_file);
            $config = json_decode($content, true);
            if ($config !== null) {
                echo json_encode(['status' => 'ok', 'data' => $config]);
            } else {
                echo json_encode(['status' => 'error', 'message' => '菜单配置文件格式错误']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => '菜单配置文件不存在']);
        }
        break;

    case 'update_menu_config':
        // 更新菜单配置
        $config_data = isset($_POST['config']) ? $_POST['config'] : '';
        if (!$config_data) {
            echo json_encode(['status' => 'error', 'message' => '配置数据不能为空']);
            break;
        }
        
        // 验证JSON格式
        $config = json_decode($config_data, true);
        if ($config === null) {
            echo json_encode(['status' => 'error', 'message' => 'JSON格式错误']);
            break;
        }
        
        // 保存到文件
        $menu_file = $data_dir . 'menu.json';
        if (file_put_contents($menu_file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
            echo json_encode(['status' => 'ok', 'message' => '菜单配置更新成功']);
        } else {
            echo json_encode(['status' => 'error', 'message' => '保存菜单配置失败']);
        }
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => '无效的操作']);
        break;
}
exit;
