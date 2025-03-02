<?php
// models/Attendance.php - 打卡记录模型

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/DateUtils.php';

class Attendance {
    private $conn;
    private $records_table = "attendance_records";
    private $corrections_table = "attendance_corrections";
    private $overtime_table = "overtime_requests";
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    // 获取打卡记录
    public function getAttendanceRecords($user_id, $tenant_id, $start_date, $end_date) {
        $query = "SELECT * FROM " . $this->records_table . " 
                  WHERE user_id = ? AND tenant_id = ? 
                  AND work_date BETWEEN ? AND ? 
                  ORDER BY work_date DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$user_id, $tenant_id, $start_date, $end_date]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // 获取部门打卡记录
    public function getDepartmentAttendanceRecords($department_id, $tenant_id, $start_date, $end_date) {
        $query = "SELECT a.*, u.real_name, u.employee_id 
                  FROM " . $this->records_table . " a
                  JOIN users u ON a.user_id = u.user_id
                  WHERE u.department_id = ? AND a.tenant_id = ? 
                  AND a.work_date BETWEEN ? AND ? 
                  ORDER BY a.work_date DESC, u.real_name ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$department_id, $tenant_id, $start_date, $end_date]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // 签到
    public function checkIn($user_id, $tenant_id, $data) {
        // 检查今天是否已经打过卡
        $work_date = date('Y-m-d');
        $check_query = "SELECT record_id, check_in_time FROM " . $this->records_table . " 
                        WHERE user_id = ? AND tenant_id = ? AND work_date = ?";
        
        $check_stmt = $this->conn->prepare($check_query);
        $check_stmt->execute([$user_id, $tenant_id, $work_date]);
        $existing_record = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing_record && $existing_record['check_in_time']) {
            return [
                'success' => false,
                'message' => '今天已经签到过了',
                'data' => [
                    'record_id' => $existing_record['record_id'],
                    'check_in_time' => $existing_record['check_in_time']
                ]
            ];
        }
        
        // 获取当前时间
        $now = date('Y-m-d H:i:s');
        
        // 准备打卡数据
        $check_in_data = [
            'user_id' => $user_id,
            'tenant_id' => $tenant_id,
            'work_date' => $work_date,
            'check_in_time' => $now,
            'check_in_location' => $data['location'] ?? null,
            'check_in_geo_latitude' => $data['latitude'] ?? null,
            'check_in_geo_longitude' => $data['longitude'] ?? null,
            'check_in_device' => $data['device'] ?? null,
            'check_in_wifi' => $data['wifi'] ?? null,
            'check_in_photo_url' => $data['photo'] ?? null,
            'status' => 'normal', // 初始状态设为正常
            'notes' => $data['notes'] ?? null
        ];
        
        // 判断是否需要创建新记录
        if ($existing_record) {
            // 更新现有记录
            $update_query = "UPDATE " . $this->records_table . " SET 
                            check_in_time = ?, check_in_location = ?, check_in_geo_latitude = ?,
                            check_in_geo_longitude = ?, check_in_device = ?, check_in_wifi = ?,
                            check_in_photo_url = ?, status = ?, notes = ?, updated_at = NOW()
                            WHERE record_id = ?";
            
            $stmt = $this->conn->prepare($update_query);
            $result = $stmt->execute([
                $check_in_data['check_in_time'], 
                $check_in_data['check_in_location'],
                $check_in_data['check_in_geo_latitude'], 
                $check_in_data['check_in_geo_longitude'],
                $check_in_data['check_in_device'], 
                $check_in_data['check_in_wifi'],
                $check_in_data['check_in_photo_url'], 
                $check_in_data['status'],
                $check_in_data['notes'], 
                $existing_record['record_id']
            ]);
            
            $record_id = $existing_record['record_id'];
        } else {
            // 创建新记录
            $insert_query = "INSERT INTO " . $this->records_table . " 
                           (user_id, tenant_id, work_date, check_in_time, check_in_location, 
                            check_in_geo_latitude, check_in_geo_longitude, check_in_device, 
                            check_in_wifi, check_in_photo_url, status, notes) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($insert_query);
            $result = $stmt->execute([
                $check_in_data['user_id'], 
                $check_in_data['tenant_id'],
                $check_in_data['work_date'], 
                $check_in_data['check_in_time'],
                $check_in_data['check_in_location'], 
                $check_in_data['check_in_geo_latitude'],
                $check_in_data['check_in_geo_longitude'], 
                $check_in_data['check_in_device'],
                $check_in_data['check_in_wifi'], 
                $check_in_data['check_in_photo_url'],
                $check_in_data['status'], 
                $check_in_data['notes']
            ]);
            
            $record_id = $this->conn->lastInsertId();
        }
        
        if ($result) {
            return [
                'success' => true,
                'message' => '签到成功',
                'data' => [
                    'record_id' => $record_id,
                    'check_in_time' => $now
                ]
            ];
        } else {
            return [
                'success' => false,
                'message' => '签到失败，请重试'
            ];
        }
    }
    
    // 签退
    public function checkOut($user_id, $tenant_id, $data) {
        // 检查今天是否已经打过卡
        $work_date = date('Y-m-d');
        $check_query = "SELECT record_id, check_in_time, check_out_time FROM " . $this->records_table . " 
                        WHERE user_id = ? AND tenant_id = ? AND work_date = ?";
        
        $check_stmt = $this->conn->prepare($check_query);
        $check_stmt->execute([$user_id, $tenant_id, $work_date]);
        $existing_record = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existing_record) {
            return [
                'success' => false,
                'message' => '今天还没有签到记录，无法签退'
            ];
        }
        
        if ($existing_record['check_out_time']) {
            return [
                'success' => false,
                'message' => '今天已经签退过了',
                'data' => [
                    'check_out_time' => $existing_record['check_out_time']
                ]
            ];
        }
        
        // 获取当前时间
        $now = date('Y-m-d H:i:s');
        
        // 计算工作时长（小时）
        $check_in_time = new DateTime($existing_record['check_in_time']);
        $check_out_time = new DateTime($now);
        $interval = $check_in_time->diff($check_out_time);
        $working_hours = $interval->h + ($interval->i / 60);
        
        // 更新记录
        $update_query = "UPDATE " . $this->records_table . " SET 
                        check_out_time = ?, check_out_location = ?, check_out_geo_latitude = ?,
                        check_out_geo_longitude = ?, check_out_device = ?, check_out_wifi = ?,
                        check_out_photo_url = ?, working_hours = ?, updated_at = NOW()
                        WHERE record_id = ?";
        
        $stmt = $this->conn->prepare($update_query);
        $result = $stmt->execute([
            $now, 
            $data['location'] ?? null,
            $data['latitude'] ?? null, 
            $data['longitude'] ?? null,
            $data['device'] ?? null, 
            $data['wifi'] ?? null,
            $data['photo'] ?? null, 
            $working_hours,
            $existing_record['record_id']
        ]);
        
        if ($result) {
            return [
                'success' => true,
                'message' => '签退成功',
                'data' => [
                    'record_id' => $existing_record['record_id'],
                    'check_out_time' => $now,
                    'working_hours' => round($working_hours, 2)
                ]
            ];
        } else {
            return [
                'success' => false,
                'message' => '签退失败，请重试'
            ];
        }
    }
    
    // 获取今日打卡状态
    public function getTodayAttendance($user_id, $tenant_id) {
        $work_date = date('Y-m-d');
        $query = "SELECT * FROM " . $this->records_table . " 
                  WHERE user_id = ? AND tenant_id = ? AND work_date = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$user_id, $tenant_id, $work_date]);
        
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$record) {
            return [
                'has_record' => false,
                'checked_in' => false,
                'checked_out' => false
            ];
        }
        
        return [
            'has_record' => true,
            'record' => $record,
            'checked_in' => !empty($record['check_in_time']),
            'checked_out' => !empty($record['check_out_time'])
        ];
    }
    
    // 申请补卡
    public function applyCorrection($user_id, $tenant_id, $data) {
        // 验证输入
        if (!isset($data['work_date']) || !isset($data['correction_type']) || !isset($data['corrected_time']) || !isset($data['reason'])) {
            return [
                'success' => false,
                'message' => '缺少必要参数'
            ];
        }
        
        // 检查日期格式
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['work_date'])) {
            return [
                'success' => false,
                'message' => '日期格式不正确，应为YYYY-MM-DD'
            ];
        }
        
        // 获取原始打卡记录
        $query = "SELECT * FROM " . $this->records_table . " 
                  WHERE user_id = ? AND tenant_id = ? AND work_date = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$user_id, $tenant_id, $data['work_date']]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 确定原始打卡时间
        $original_time = null;
        if ($record) {
            if ($data['correction_type'] == 'check_in') {
                $original_time = $record['check_in_time'];
            } elseif ($data['correction_type'] == 'check_out') {
                $original_time = $record['check_out_time'];
            }
        }
        
        // 插入补卡申请
        $query = "INSERT INTO " . $this->corrections_table . " 
                  (user_id, tenant_id, work_date, correction_type, original_time, 
                   corrected_time, reason, attachment_url) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($query);
        $result = $stmt->execute([
            $user_id,
            $tenant_id,
            $data['work_date'],
            $data['correction_type'],
            $original_time,
            $data['corrected_time'],
            $data['reason'],
            $data['attachment_url'] ?? null
        ]);
        
        if ($result) {
            $correction_id = $this->conn->lastInsertId();
            return [
                'success' => true,
                'message' => '补卡申请已提交，等待审核',
                'data' => [
                    'correction_id' => $correction_id
                ]
            ];
        } else {
            return [
                'success' => false,
                'message' => '补卡申请提交失败，请重试'
            ];
        }
    }
    
    // 审核补卡申请
    public function reviewCorrection($correction_id, $approver_id, $status, $comment = null) {
        // 获取补卡申请
        $query = "SELECT * FROM " . $this->corrections_table . " WHERE correction_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$correction_id]);
        $correction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$correction) {
            return [
                'success' => false,
                'message' => '补卡申请不存在'
            ];
        }
        
        // 更新申请状态
        $query = "UPDATE " . $this->corrections_table . " 
                  SET status = ?, approver_id = ?, approved_at = NOW(), comment = ? 
                  WHERE correction_id = ?";
        
        $stmt = $this->conn->prepare($query);
        $result = $stmt->execute([
            $status,
            $approver_id,
            $comment,
            $correction_id
        ]);
        
        // 如果审批通过，更新打卡记录
        if ($result && $status == 'approved') {
            $this->updateAttendanceWithCorrection($correction);
        }
        
        if ($result) {
            return [
                'success' => true,
                'message' => '补卡申请已' . ($status == 'approved' ? '通过' : '拒绝')
            ];
        } else {
            return [
                'success' => false,
                'message' => '审核操作失败，请重试'
            ];
        }
    }
    
    // 根据补卡申请更新打卡记录
    private function updateAttendanceWithCorrection($correction) {
        // 检查是否已有打卡记录
        $query = "SELECT * FROM " . $this->records_table . " 
                  WHERE user_id = ? AND tenant_id = ? AND work_date = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            $correction['user_id'],
            $correction['tenant_id'],
            $correction['work_date']
        ]);
        
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($record) {
            // 更新现有记录
            $field = $correction['correction_type'] == 'check_in' ? 'check_in_time' : 'check_out_time';
            $query = "UPDATE " . $this->records_table . " SET $field = ?, updated_at = NOW() WHERE record_id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                $correction['corrected_time'],
                $record['record_id']
            ]);
            
            // 如果有check_in和check_out，计算工作时长
            if ($record['check_in_time'] && ($field == 'check_out_time' || $record['check_out_time'])) {
                $check_in_time = new DateTime($record['check_in_time']);
                $check_out_time = new DateTime($field == 'check_out_time' ? $correction['corrected_time'] : $record['check_out_time']);
                $interval = $check_in_time->diff($check_out_time);
                $working_hours = $interval->h + ($interval->i / 60);
                
                $query = "UPDATE " . $this->records_table . " SET working_hours = ? WHERE record_id = ?";
                $stmt = $this->conn->prepare($query);
                $stmt->execute([
                    $working_hours,
                    $record['record_id']
                ]);
            }
        } else {
            // 创建新记录
            $check_in_time = $correction['correction_type'] == 'check_in' ? $correction['corrected_time'] : null;
            $check_out_time = $correction['correction_type'] == 'check_out' ? $correction['corrected_time'] : null;
            
            $query = "INSERT INTO " . $this->records_table . " 
                     (user_id, tenant_id, work_date, check_in_time, check_out_time, status) 
                     VALUES (?, ?, ?, ?, ?, 'normal')";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                $correction['user_id'],
                $correction['tenant_id'],
                $correction['work_date'],
                $check_in_time,
                $check_out_time
            ]);
        }
    }
    
    // 申请加班
    public function applyOvertime($user_id, $tenant_id, $data) {
        // 验证输入
        if (!isset($data['overtime_date']) || !isset($data['start_time']) || !isset($data['end_time']) || !isset($data['reason'])) {
            return [
                'success' => false,
                'message' => '缺少必要参数'
            ];
        }
        
        // 计算加班时长
        $start = new DateTime($data['overtime_date'] . ' ' . $data['start_time']);
        $end = new DateTime($data['overtime_date'] . ' ' . $data['end_time']);
        
        // 如果结束时间小于开始时间，假设是跨天，加一天
        if ($end < $start) {
            $end->modify('+1 day');
        }
        
        $interval = $start->diff($end);
        $total_hours = $interval->h + ($interval->i / 60);
        
        // 插入加班申请
        $query = "INSERT INTO " . $this->overtime_table . " 
                  (user_id, tenant_id, overtime_date, start_time, end_time, total_hours, reason) 
                  VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($query);
        $result = $stmt->execute([
            $user_id,
            $tenant_id,
            $data['overtime_date'],
            $data['start_time'],
            $data['end_time'],
            $total_hours,
            $data['reason']
        ]);
        
        if ($result) {
            $overtime_id = $this->conn->lastInsertId();
            return [
                'success' => true,
                'message' => '加班申请已提交，等待审核',
                'data' => [
                    'request_id' => $overtime_id,
                    'total_hours' => round($total_hours, 2)
                ]
            ];
        } else {
            return [
                'success' => false,
                'message' => '加班申请提交失败，请重试'
            ];
        }
    }
    
    // 审核加班申请
    public function reviewOvertime($request_id, $approver_id, $status, $comment = null) {
        // 更新申请状态
        $query = "UPDATE " . $this->overtime_table . " 
                  SET status = ?, approver_id = ?, approved_at = NOW(), comment = ? 
                  WHERE request_id = ?";
        
        $stmt = $this->conn->prepare($query);
        $result = $stmt->execute([
            $status,
            $approver_id,
            $comment,
            $request_id
        ]);
        
        if ($result) {
            return [
                'success' => true,
                'message' => '加班申请已' . ($status == 'approved' ? '通过' : '拒绝')
            ];
        } else {
            return [
                'success' => false,
                'message' => '审核操作失败，请重试'
            ];
        }
    }
    
    // 获取用户的待审批申请
    public function getUserPendingRequests($user_id, $tenant_id) {
        // 获取补卡申请
        $correction_query = "SELECT correction_id as id, 'correction' as type, work_date, created_at, status 
                            FROM " . $this->corrections_table . " 
                            WHERE user_id = ? AND tenant_id = ? 
                            ORDER BY created_at DESC";
        
        $correction_stmt = $this->conn->prepare($correction_query);
        $correction_stmt->execute([$user_id, $tenant_id]);
        $corrections = $correction_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 获取加班申请
        $overtime_query = "SELECT request_id as id, 'overtime' as type, overtime_date as work_date, created_at, status 
                          FROM " . $this->overtime_table . " 
                          WHERE user_id = ? AND tenant_id = ? 
                          ORDER BY created_at DESC";
        
        $overtime_stmt = $this->conn->prepare($overtime_query);
        $overtime_stmt->execute([$user_id, $tenant_id]);
        $overtimes = $overtime_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 合并结果
        $result = array_merge($corrections, $overtimes);
        
        // 按创建时间排序
        usort($result, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
        return $result;
    }
    
    // 获取待审批的申请
    public function getPendingApprovals($approver_id, $tenant_id) {
        // 获取用户管理的部门
        $dept_query = "SELECT department_id FROM departments WHERE manager_id = ? AND tenant_id = ?";
        $dept_stmt = $this->conn->prepare($dept_query);
        $dept_stmt->execute([$approver_id, $tenant_id]);
        $departments = $dept_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($departments)) {
            return [];
        }
        
        // 转换部门ID为IN查询的格式
        $dept_ids = implode(',', array_map('intval', $departments));
        
        // 获取补卡申请
        $correction_query = "SELECT c.*, u.real_name, u.employee_id, d.department_name 
                            FROM " . $this->corrections_table . " c
                            JOIN users u ON c.user_id = u.user_id
                            JOIN departments d ON u.department_id = d.department_id
                            WHERE c.status = 'pending' AND c.tenant_id = ? 
                            AND u.department_id IN ($dept_ids)
                            ORDER BY c.created_at ASC";
        
        $correction_stmt = $this->conn->prepare($correction_query);
        $correction_stmt->execute([$tenant_id]);
        $corrections = $correction_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 获取加班申请
        $overtime_query = "SELECT o.*, u.real_name, u.employee_id, d.department_name 
                          FROM " . $this->overtime_table . " o
                          JOIN users u ON o.user_id = u.user_id
                          JOIN departments d ON u.department_id = d.department_id
                          WHERE o.status = 'pending' AND o.tenant_id = ? 
                          AND u.department_id IN ($dept_ids)
                          ORDER BY o.created_at ASC";
        
        $overtime_stmt = $this->conn->prepare($overtime_query);
        $overtime_stmt->execute([$tenant_id]);
        $overtimes = $overtime_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'corrections' => $corrections,
            'overtimes' => $overtimes
        ];
    }
    
    // 获取用户月度考勤统计
    public function getUserMonthlyStats($user_id, $tenant_id, $year, $month) {
        // 构建日期范围
        $start_date = sprintf('%04d-%02d-01', $year, $month);
        $end_date = date('Y-m-t', strtotime($start_date));
        
        // 获取该月的所有记录
        $query = "SELECT * FROM " . $this->records_table . " 
                  WHERE user_id = ? AND tenant_id = ? 
                  AND work_date BETWEEN ? AND ? 
                  ORDER BY work_date ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$user_id, $tenant_id, $start_date, $end_date]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 统计数据
        $total_days = count($records);
        $normal_days = 0;
        $late_days = 0;
        $early_leave_days = 0;
        $absent_days = 0;
        $total_working_hours = 0;
        
        foreach ($records as $record) {
            switch ($record['status']) {
                case 'normal':
                    $normal_days++;
                    break;
                case 'late':
                    $late_days++;
                    break;
                case 'early_leave':
                    $early_leave_days++;
                    break;
                case 'absent':
                    $absent_days++;
                    break;
            }
            
            $total_working_hours += $record['working_hours'] ?? 0;
        }
        
        // 获取加班记录
        $overtime_query = "SELECT SUM(total_hours) as total_overtime
                           FROM " . $this->overtime_table . "
                           WHERE user_id = ? AND tenant_id = ? 
                           AND status = 'approved'
                           AND overtime_date BETWEEN ? AND ?";
        
        $overtime_stmt = $this->conn->prepare($overtime_query);
        $overtime_stmt->execute([$user_id, $tenant_id, $start_date, $end_date]);
        $overtime_result = $overtime_stmt->fetch(PDO::FETCH_ASSOC);
        $total_overtime = $overtime_result['total_overtime'] ?? 0;
        
        return [
            'year' => $year,
            'month' => $month,
            'total_days' => $total_days,
            'normal_days' => $normal_days,
            'late_days' => $late_days,
            'early_leave_days' => $early_leave_days,
            'absent_days' => $absent_days,
            'total_working_hours' => round($total_working_hours, 2),
            'total_overtime' => round($total_overtime, 2),
            'records' => $records
        ];
    }
}