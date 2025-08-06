<?php
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}
/**
 * IP封禁模块
 *
 * 功能说明：
 * 1. add_ban_ip($ip)：添加封禁IP，封禁时长30分钟。
 * 2. is_ip_banned($ip)：查询IP是否处于封禁状态，若封禁则返回['banned'=>true, 'until'=>时间戳]，否则['banned'=>false]。
 * 3. 每次调用时自动清理过期的封禁记录。
 * 4. 封禁信息存储于 /data/ban.json（如不存在自动创建）。
 */

define('BAN_FILE', __DIR__ . '/data/ban.json');
define('BAN_DURATION', 30 * 60); // 30分钟，单位：秒

/**
 * 读取封禁列表并自动清理过期IP
 * @return array
 */
function get_ban_list() {
    if (!file_exists(BAN_FILE)) {
        if (!is_dir(dirname(BAN_FILE))) {
            mkdir(dirname(BAN_FILE), 0777, true);
        }
        file_put_contents(BAN_FILE, json_encode([]));
    }
    $json = file_get_contents(BAN_FILE);
    $list = json_decode($json, true);
    if (!is_array($list)) $list = [];
    $now = time();
    // 清理过期
    $changed = false;
    foreach ($list as $ip => $until) {
        if ($until <= $now) {
            unset($list[$ip]);
            $changed = true;
        }
    }
    if ($changed) {
        file_put_contents(BAN_FILE, json_encode($list));
    }
    return $list;
}

/**
 * 添加封禁IP
 * @param string $ip
 * @return bool
 */
function add_ban_ip($ip) {
    $list = get_ban_list();
    $until = time() + BAN_DURATION;
    $list[$ip] = $until;
    file_put_contents(BAN_FILE, json_encode($list));
    return true;
}

/**
 * 查询IP是否被封禁
 * @param string $ip
 * @return array ['banned'=>bool, 'until'=>int|null]
 */
function is_ip_banned($ip) {
    $list = get_ban_list();
    if (isset($list[$ip]) && $list[$ip] > time()) {
        return ['banned'=>true, 'until'=>$list[$ip]];
    }
    return ['banned'=>false];
}

?>
