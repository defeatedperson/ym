<?php
/**
 * 系统初始化与检测模块
 *
 * 1. init_system($username, $password, $ip)：初始化系统，创建SQLite数据库和用户表，添加管理员账号（仅未初始化时可执行）。
 * 2. check_initialized()：检测系统是否已初始化（数据库、数据表、管理员账号是否存在）。
 */

if (!defined('DB_FILE')) {
    define('DB_FILE', __DIR__ . '/data/main.db');
}
if (!defined('ADMIN_TABLE')) {
    define('ADMIN_TABLE', 'users');
}

// 检测是否已初始化
function check_initialized() {
    if (!file_exists(DB_FILE)) return false;
    $db = new SQLite3(DB_FILE);
    // 检查数据表
    $result = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='" . ADMIN_TABLE . "'");
    if ($result !== ADMIN_TABLE) return false;
    // 检查是否有管理员
    $result = $db->querySingle("SELECT COUNT(*) FROM " . ADMIN_TABLE . " WHERE is_admin=1");
    return $result > 0;
}

// 初始化系统
function init_system($username, $password, $ip) {
    if (check_initialized()) return ['status'=>'already_initialized'];
    if (!is_string($username) || !is_string($password) || !is_string($ip)) return ['status'=>'invalid_param'];
    // 创建数据库
    if (!is_dir(dirname(DB_FILE))) {
        mkdir(dirname(DB_FILE), 0777, true);
    }
    $db = new SQLite3(DB_FILE);
    // 创建数据表
    $db->exec("CREATE TABLE IF NOT EXISTS " . ADMIN_TABLE . " (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        is_admin INTEGER NOT NULL DEFAULT 0,
        password TEXT NOT NULL,
        email TEXT,
        mfa_enabled INTEGER NOT NULL DEFAULT 0,
        mfa_secret TEXT,
        ip TEXT,
        last_login TEXT
    )");
    // 密码加密
    $hash = password_hash($password, PASSWORD_DEFAULT);
    // 添加管理员
    $stmt = $db->prepare("INSERT INTO " . ADMIN_TABLE . " (username, is_admin, password, ip) VALUES (?, 1, ?, ?)");
    $stmt->bindValue(1, $username, SQLITE3_TEXT);
    $stmt->bindValue(2, $hash, SQLITE3_TEXT);
    $stmt->bindValue(3, $ip, SQLITE3_TEXT);
    $result = $stmt->execute();
    if ($result) return ['status'=>'ok'];
    return ['status'=>'fail'];
}
?>