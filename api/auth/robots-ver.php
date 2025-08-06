<?php
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}
/**
 * 滑块验证模块
 *
 * 功能说明：
 * 1. generate_slider_challenge()：生成滑块验证题目，返回前端可见的通过区域（如5-10）和实际正确答案范围（如15-20），前端只显示通过区域，提交时由后端校验。
 * 2. verify_slider($user_value, $real_min, $real_max)：验证用户提交的滑块值是否在实际正确答案范围内，限制输入范围防止自动化攻击。
 */

/**
 * 生成滑块验证题目
 * @return array ['show_min'=>int, 'show_max'=>int, 'real_min'=>int, 'real_max'=>int]
 */
function generate_slider_challenge() {
    // 设定滑块总范围
    $total_min = 0;
    $total_max = 100;
    $target_width = 20; // 固定目标区间宽度
    // 随机生成目标区间左端，确保不在初始位置(0-15%)
    $target_left = rand(20, $total_max - $target_width - 10);
    $show_min = $target_left;
    $show_max = $target_left + $target_width;
    return [
        'show_min' => $show_min,
        'show_max' => $show_max,
        'target_left' => $target_left,
        'target_width' => $target_width
    ];
}

/**
 * 验证滑块结果（像素位置版本）
 * @param int $user_position 用户滑块位置（像素）
 * @param int $target_position 目标位置（像素）
 * @param int $tolerance 容差（像素，默认20）
 * @return bool
 */
function verify_slider_pixel($user_position, $target_position, $tolerance = 20) {
    // 验证输入范围
    if (!is_numeric($user_position) || !is_numeric($target_position)) return false;
    if ($user_position < 0 || $user_position > 500 || $target_position < 0 || $target_position > 500) return false;
    
    // 计算距离差
    $distance = abs($user_position - $target_position);
    
    // 验证是否在容差范围内
    return $distance <= $tolerance;
}

/**
 * 生成像素位置滑块挑战
 * @param int $track_width 滑块轨道宽度（像素）
 * @return array ['target_x'=>int, 'tolerance'=>int]
 */
function generate_pixel_slider_challenge($track_width = 300) {
    // 确保目标位置在合理范围内（20%-80%）
    $min_target = $track_width * 0.2;
    $max_target = $track_width * 0.8;
    $target_x = rand($min_target, $max_target);
    $tolerance = 20; // 固定20像素容差
    
    return [
        'target_x' => $target_x,
        'tolerance' => $tolerance
    ];
}

/**
 * 验证滑块结果（新版本）
 * @param int $user_value 用户提交的滑块值
 * @param int $target_left 目标区间左端
 * @param int $target_width 目标区间宽度
 * @return bool
 */
function verify_slider_new($user_value, $target_left, $target_width) {
    // 限制输入范围
    if (!is_int($user_value) || $user_value < 0 || $user_value > 100) return false;
    // 前端提交时已减5%，后端自动加5%恢复真实位置
    $actual_center = $user_value + 5;
    // 允许±3%的误差
    $tolerance = 3;
    $target_min = $target_left - $tolerance;
    $target_max = $target_left + $target_width + $tolerance;
    return ($actual_center >= $target_min && $actual_center <= $target_max);
}

/**
 * 验证滑块结果
 * @param int $user_value 用户提交的滑块值
 * @param int $real_min 实际正确答案区间最小值
 * @param int $real_max 实际正确答案区间最大值
 * @return bool
 */
function verify_slider($user_value, $real_min, $real_max) {
    // 限制输入范围
    if (!is_int($user_value) || $user_value < 0 || $user_value > 100) return false;
    // 前端提交时已减5%，后端自动加5%恢复真实位置
    $actual_center = $user_value + 5;
    // 获取目标区间信息
    global $target_left, $target_width;
    // 允许±3%的误差
    $tolerance = 3;
    $target_min = $target_left - $tolerance;
    $target_max = $target_left + $target_width + $tolerance;
    return ($actual_center >= $target_min && $actual_center <= $target_max);
}
?>