<?php
// controllers/SystemController.php

require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../models/AttendanceRule.php';

class SystemController {
    // 检查位置
    public function checkLocation() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        $data = json_decode(file_get_contents("php://input"), true);
        
        $latitude = $data['latitude'] ?? null;
        $longitude = $data['longitude'] ?? null;
        $wifi = $data['wifi'] ?? null;
        
        // 获取用户适用的考勤规则
        $rule_model = new AttendanceRule();
        $rule = $rule_model->getApplicableRule($user['user_id'], $user['tenant_id']);
        
        $location_valid = true;
        $wifi_valid = true;
        $message = '';
        
        // 验证地理位置
        if ($rule && $rule['location_required'] && $latitude && $longitude) {
            $rule_lat = floatval($rule['geo_latitude']);
            $rule_lng = floatval($rule['geo_longitude']);
            $radius = intval($rule['geo_radius']);
            
            // 如果规则中设置了地理位置
            if ($rule_lat && $rule_lng) {
                // 计算距离（使用简化的平面距离计算，仅作演示）
                $distance = $this->calculateDistance($latitude, $longitude, $rule_lat, $rule_lng);
                $location_valid = $distance <= $radius;
                
                if (!$location_valid) {
                    $message = "当前位置不在办公范围内，距离办公地点" . round($distance) . "米";
                }
            }
        }
        
        // 验证WiFi
        if ($rule && $rule['wifi_required'] && $wifi) {
            $required_wifi = $rule['wifi_ssid'];
            if ($required_wifi) {
                $wifi_valid = $wifi === $required_wifi;
                
                if (!$wifi_valid) {
                    $message = $message ? $message . "，且WiFi不匹配" : "WiFi不匹配";
                }
            }
        }
        
        // 最终验证结果
        $is_valid = $location_valid && $wifi_valid;
        $status_message = $is_valid ? '位置验证通过' : $message;
        
        Response::json(200, $is_valid ? 'Location check passed' : 'Location check failed', [
            'isValid' => $is_valid,
            'message' => $status_message,
            'locationValid' => $location_valid,
            'wifiValid' => $wifi_valid
        ]);
    }
    
    // 获取WiFi信息
    public function getWifiInfo() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        
        // 获取用户适用的考勤规则
        $rule_model = new AttendanceRule();
        $rule = $rule_model->getApplicableRule($user['user_id'], $user['tenant_id']);
        
        $wifi_required = false;
        $wifi_ssid = null;
        
        // 如果有规则且要求WiFi验证
        if ($rule && isset($rule['wifi_required']) && $rule['wifi_required']) {
            $wifi_required = true;
            $wifi_ssid = $rule['wifi_ssid'] ?? null;
        }
        
        Response::json(200, 'WiFi information retrieved', [
            'wifiRequired' => $wifi_required,
            'wifiSSID' => $wifi_ssid,
            // 以下是给前端提供的模拟数据，帮助其进行界面展示
            'canDetectWifi' => false, // 网页通常无法直接检测WiFi，设为false
            'currentWifi' => null
        ]);
    }
    
    // 计算两点之间的距离（米）
    private function calculateDistance($lat1, $lng1, $lat2, $lng2) {
        // 地球半径（米）
        $earthRadius = 6371000;
        
        // 将经纬度转换为弧度
        $lat1 = deg2rad(floatval($lat1));
        $lng1 = deg2rad(floatval($lng1));
        $lat2 = deg2rad(floatval($lat2));
        $lng2 = deg2rad(floatval($lng2));
        
        // 使用Haversine公式
        $latDelta = $lat2 - $lat1;
        $lngDelta = $lng2 - $lng1;
        
        $a = sin($latDelta/2) * sin($latDelta/2) +
             cos($lat1) * cos($lat2) * sin($lngDelta/2) * sin($lngDelta/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        
        return $earthRadius * $c;
    }
}