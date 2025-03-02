<?php
// utils/DateUtils.php - 日期处理工具

class DateUtils {
    // 格式化日期
    public static function formatDate($date, $format = 'Y-m-d') {
        if (!$date) return null;
        
        if (is_string($date)) {
            $date = strtotime($date);
        }
        
        return date($format, $date);
    }
    
    // 计算工作日
    public static function getWorkingDays($startDate, $endDate, $holidays = []) {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($start, $interval, $end);
        
        $workdays = 0;
        foreach ($period as $day) {
            // 跳过周末 (6=周六, 0=周日)
            $dayOfWeek = $day->format('w');
            if ($dayOfWeek != 0 && $dayOfWeek != 6) {
                // 跳过假期
                $dateStr = $day->format('Y-m-d');
                if (!in_array($dateStr, $holidays)) {
                    $workdays++;
                }
            }
        }
        
        return $workdays;
    }
    
    // 获取日期当前状态
    public static function getWorkDayStatus($rule, $date, $checkInTime, $checkOutTime) {
        if (!$rule || !$date) {
            return 'normal';
        }
        
        $currentDate = new DateTime($date);
        $dayOfWeek = $currentDate->format('N'); // 1=周一, 7=周日
        
        // 检查是否为工作日
        $workDays = explode(',', $rule['work_days']);
        if (!in_array($dayOfWeek, $workDays)) {
            return 'normal'; // 非工作日
        }
        
        // 解析工作时间
        $workDate = $currentDate->format('Y-m-d');
        $workStartTime = new DateTime($workDate . ' ' . $rule['work_start_time']);
        $workEndTime = new DateTime($workDate . ' ' . $rule['work_end_time']);
        
        // 没有签到和签退记录
        if (!$checkInTime && !$checkOutTime) {
            return 'absent';
        }
        
        // 检查是否迟到
        if ($checkInTime) {
            $checkInDateTime = new DateTime($checkInTime);
            $lateThreshold = $rule['late_threshold_minutes'] * 60; // 转换为秒
            
            if ($checkInDateTime > clone $workStartTime->modify("+{$lateThreshold} seconds")) {
                return 'late';
            }
        }
        
        // 检查是否早退
        if ($checkOutTime) {
            $checkOutDateTime = new DateTime($checkOutTime);
            $earlyThreshold = $rule['early_leave_threshold_minutes'] * 60; // 转换为秒
            
            if ($checkOutDateTime < clone $workEndTime->modify("-{$earlyThreshold} seconds")) {
                return 'early_leave';
            }
        }
        
        return 'normal';
    }
}