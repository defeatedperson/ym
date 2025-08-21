<?php
// 只允许POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'method_not_allowed']);
    exit;
}

require_once __DIR__ . '/ip-cail.php';
require_once __DIR__ . '/ip-ban.php';
require_once __DIR__ . '/setup.php';
require_once __DIR__ . '/safe-input.php';
require_once __DIR__ . '/temp-jwt.php';
require_once __DIR__ . '/robots-ver.php';
require_once __DIR__ . '/accoun-ver.php';
require_once __DIR__ . '/mfa-ver.php';
require_once __DIR__ . '/account-jwt.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/secure-login-strategy.php';

// 设置session生命周期，确保登录完成后自动过期
session_set_cookie_params(1800); // 30分钟后自动过期
// 初始化 session，仅用于登录过程
session_start();

// 设置安全响应头
set_security_headers();

/**
 * 获取请求方真实IP并校验是否被封禁
 * @return array ['ip'=>string, 'banned'=>bool, 'until'=>int|null]
 */
function check_ip_status() {
    $ip = get_real_ip();
    $ban = is_ip_banned($ip);
    return [
        'ip' => $ip,
        'banned' => $ban['banned'],
        'until' => $ban['banned'] ? $ban['until'] : null
    ];
}

// 检查IP状态
$status = check_ip_status();
if ($status['banned']) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ip_banned',
        'ip' => $status['ip'],
        'until' => $status['until']
    ]);
    exit;
}

// 检查系统是否已初始化
if (isset($_POST['action']) && $_POST['action'] === 'check_init') {
    header('Content-Type: application/json');
    if (check_initialized()) {
        echo json_encode(['status' => 'initialized']);
    } else {
        echo json_encode(['status' => 'not_initialized']);
    }
    exit;
}
// 检查是否已登录（通过account_jwt验证）
if (isset($_COOKIE['account_jwt'])) {
    $jwt = $_COOKIE['account_jwt'];
    // 简化验证逻辑，只检查account_jwt是否有效
    $login_info = validate_jwt_token($jwt, $status['ip']);
    if (isset($login_info['valid']) && $login_info['valid']) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'already_logged_in']);
        exit;
    }
}

// 检查系统是否初始化
if (!check_initialized()) {
    // 获取前端传入参数（POST方式）
    $username = isset($_POST['username']) ? $_POST['username'] : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    // 安全输入校验
    $username = validate_common_input($username);
    $password = validate_password($password);
    $ip = $status['ip'];
    if ($username === false || $password === false) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'invalid_input']);
        exit;
    }
    // 初始化系统
    $result = init_system($username, $password, $ip);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}


// 生成滑块验证参数并返回临时JWT令牌
if (isset($_POST['action']) && $_POST['action'] === 'get_slider') {
    $ip = check_ip_status()['ip'];
    $ban = is_ip_banned($ip);
    if ($ban['banned']) {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'ip_banned',
            'ip' => $ip,
            'until' => $ban['until']
        ]);
        exit;
    }
    $jwt_result = generate_temp_jwt($ip, 'robots');
    if ($jwt_result['status'] !== 'ok') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'jwt_error']);
        exit;
    }
    $slider = generate_slider_challenge();
    // 存储目标区间信息到session，key用robots_jwt保证唯一
    $_SESSION['slider_' . $jwt_result['jwt']] = [
        'target_left' => $slider['target_left'],
        'target_width' => $slider['target_width']
    ];
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'robots_jwt' => $jwt_result['jwt'],
        'slider' => [
            'show_min' => $slider['show_min'],
            'show_max' => $slider['show_max']
        ]
    ]);
    exit;
}

// 校验滑块验证结果
if (isset($_POST['action']) && $_POST['action'] === 'verify_slider') {
    $ip = check_ip_status()['ip'];
    $ban = is_ip_banned($ip);
    if ($ban['banned']) {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'ip_banned',
            'ip' => $ip,
            'until' => $ban['until']
        ]);
        exit;
    }
    $robots_jwt = isset($_POST['robots_jwt']) ? $_POST['robots_jwt'] : '';
    
    // 使用像素位置验证（最新版本）
    $user_position = isset($_POST['user_position']) ? intval($_POST['user_position']) : null;
    $target_position = isset($_POST['target_position']) ? intval($_POST['target_position']) : null;
    
    // 从session获取目标信息
    $slider_key = 'slider_' . $robots_jwt;
    $stored_data = isset($_SESSION[$slider_key]) ? $_SESSION[$slider_key] : null;
    
    // 校验robots场景的临时JWT
    $jwt_check = validate_temp_jwt($ip, $robots_jwt);
    $is_robots_scene = ($jwt_check['valid'] && $jwt_check['scene'] === 'robots');
    
    $slider_ok = false;
    
    // 使用像素位置验证
    if ($user_position !== null && $target_position !== null) {
        $slider_ok = verify_slider_pixel($user_position, $target_position, 20);
    }
    
    // 验证后立即清除session
    unset($_SESSION[$slider_key]);
    if (!$is_robots_scene || !$slider_ok) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'slider_failed']);
        exit;
    }
    // 滑块验证通过，生成account场景的临时JWT令牌
    $account_jwt_result = generate_temp_jwt($ip, 'account');
    if ($account_jwt_result['status'] !== 'ok') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'jwt_error']);
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'slider_ok',
        'account_jwt' => $account_jwt_result['jwt']
    ]);
    exit;
}

// 新的安全账号验证接口
if (isset($_POST['action']) && $_POST['action'] === 'verify_account_secure') {
    $ip = check_ip_status()['ip'];
    $ban = is_ip_banned($ip);
    if ($ban['banned']) {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'ip_banned',
            'ip' => $ip,
            'until' => $ban['until']
        ]);
        exit;
    }
    
    // 获取加密的登录数据
    $encrypted_login_data = isset($_POST['encrypted_login_data']) ? $_POST['encrypted_login_data'] : '';
    $client_timestamp = isset($_POST['client_timestamp']) ? intval($_POST['client_timestamp']) : 0;
    
    if (empty($encrypted_login_data)) {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'account_failed',
            'reason' => 'no_data',
            'debug' => ['encrypted_data_length' => strlen($encrypted_login_data)]
        ]);
        exit;
    }
    
    // 安全解密登录数据
    $login_data = decrypt_secure_login_data($encrypted_login_data, $ip);
    
    if ($login_data === false) {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'account_failed',
            'reason' => 'decrypt_failed'
        ]);
        exit;
    }
    
    // 验证用户名格式
    $username = validate_common_input($login_data['username']);
    if ($username === false) {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'account_failed',
            'reason' => 'invalid_username'
        ]);
        exit;
    }
    
    // 账号密码验证
    $verify = verify_account($username, $login_data['password']);
    
    if (!isset($verify['valid']) || !$verify['valid']) {
        // 增加登录失败尝试次数
        $attempt_result = increment_login_attempt($ip);
        
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'account_failed',
            'reason' => $verify['reason'] ?? 'auth_failed',
            'attempts_remaining' => $attempt_result['remaining']
        ]);
        exit;
    }
    
    // 判断是否开启MFA
    if (isset($verify['need_mfa']) && $verify['need_mfa']) {
        // 生成mfa场景的临时token，包含用户名信息
        $mfa_jwt_result = generate_temp_jwt_with_user($ip, 'mfa', $username);
        if ($mfa_jwt_result['status'] !== 'ok') {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'jwt_error']);
            exit;
        }
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'need_mfa',
            'mfa_jwt' => $mfa_jwt_result['jwt']
        ]);
        exit;
    } else {
        // 未开启MFA，直接登录成功，生成账号JWT和访问JWT
        $tokens = generate_login_tokens($username);
        header('Content-Type: application/json');
        echo json_encode($tokens);
        exit;
    }
}

// MFA验证处理
if (isset($_POST['action']) && $_POST['action'] === 'verify_mfa') {
    $ip = check_ip_status()['ip'];
    $ban = is_ip_banned($ip);
    if ($ban['banned']) {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'ip_banned',
            'ip' => $ip,
            'until' => $ban['until']
        ]);
        exit;
    }
    // 获取并校验输入
    $mfa_token = isset($_POST['mfa_token']) ? validate_mfa_token($_POST['mfa_token']) : false;
    $mfa_jwt = isset($_POST['mfa_jwt']) ? $_POST['mfa_jwt'] : '';
    // 校验临时token（必须为mfa场景）
    $jwt_check = validate_temp_jwt($ip, $mfa_jwt);
    $is_mfa_scene = ($jwt_check['valid'] && $jwt_check['scene'] === 'mfa');
    // 从JWT中获取用户名
    $username = $jwt_check['username'] ?? '';
    // 输入内容安全性判断
    if ($mfa_token === false || !$is_mfa_scene || !$username) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'mfa_failed']);
        exit;
    }
    // MFA令牌验证
    require_once __DIR__ . '/mfa-ver.php';
    $verify = verify_mfa($username, $mfa_token);
    if (!isset($verify['valid']) || !$verify['valid']) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'mfa_failed']);
        exit;
    }
    // MFA验证通过，登录成功，生成账号JWT和访问JWT
    $tokens = generate_login_tokens($username);
    header('Content-Type: application/json');
    echo json_encode($tokens);
    exit;
}

// 默认处理其他请求
header('Content-Type: application/json');
echo json_encode(['status' => 'invalid_action']);
exit;

/**
 * 登录成功后生成账号JWT令牌
 * @param string $username
 * @return array ['status'=>'ok', 'account_jwt'=>string]
 */
function generate_login_tokens($username) {
    // 获取当前真实IP，用于账号JWT绑定
    $ip = check_ip_status()['ip'];
    $account_jwt = generate_jwt_token($username, $ip);
    
    // 更新用户的最后登录IP和登录时间
    update_user_login_info($username, $ip);
    
    // 登录成功时设置 HttpOnly Cookie (根据HTTPS状态调整)
    $is_https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    setcookie('account_jwt', $account_jwt, [
        'expires' => time() + 7*24*3600,
        'path' => '/',
        'secure' => $is_https, // 只在HTTPS环境下设置secure
        'httponly' => true,
        'samesite' => 'Lax' // 使用Lax而不是Strict，提高兼容性
    ]);
    
    // 设置用户名cookie（允许JavaScript访问）
    setcookie('username', $username, [
        'expires' => time() + 7*24*3600,
        'path' => '/',
        'secure' => $is_https,
        'httponly' => false, // 允许JavaScript访问
        'samesite' => 'Lax'
    ]);
    
    // 清理该IP的临时JWT验证次数统计
    if (function_exists('clear_temp_jwt_ip')) {
        clear_temp_jwt_ip($ip);
    }
    
    // 清理session中的临时JWT
    if (function_exists('clear_session_temp_jwts')) {
        clear_session_temp_jwts();
    }
    
    // 关闭session会话，后续验证均依赖JWT
    session_write_close();
    
    // 彻底销毁session（仅在session存在时）
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_unset();
        session_destroy();
    }
    
    // 删除session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    return [
        'status' => 'ok',
        'account_jwt' => $account_jwt,
        'username' => $username
    ];
}

/**
 * 更新用户的最后登录IP和登录时间
 * @param string $username
 * @param string $ip
 * @return bool
 */
function update_user_login_info($username, $ip) {
    if (!defined('DB_FILE')) {
        define('DB_FILE', __DIR__ . '/data/main.db');
    }
    if (!defined('ADMIN_TABLE')) {
        define('ADMIN_TABLE', 'users');
    }
    
    try {
        $db = new SQLite3(DB_FILE);
        $current_time = date('Y-m-d H:i:s');
        
        $stmt = $db->prepare("UPDATE " . ADMIN_TABLE . " SET ip = ?, last_login = ? WHERE username = ?");
        $stmt->bindValue(1, $ip, SQLITE3_TEXT);
        $stmt->bindValue(2, $current_time, SQLITE3_TEXT);
        $stmt->bindValue(3, $username, SQLITE3_TEXT);
        
        $result = $stmt->execute();
        $db->close();
        
        return $result !== false;
    } catch (Exception $e) {
        error_log("更新用户登录信息失败: " . $e->getMessage());
        return false;
    }
}
?>