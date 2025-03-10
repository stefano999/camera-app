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
    
    // 获取打卡记录（支持更复杂的查询）
    public function getAttendanceRecords($user_id, $tenant_id, $start_date, $end_date) {
        $query = "SELECT * FROM " . $this->records_table . " 
                  WHERE user_id = ? AND tenant_id = ? 
                  AND work_date BETWEEN ? AND ? 
                  ORDER BY work_date DESC, record_id DESC";
        
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
                  ORDER BY a.work_date DESC, a.record_id DESC, u.real_name ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$department_id, $tenant_id, $start_date, $end_date]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // 签到 - 支持多次签到
    public function checkIn($user_id, $tenant_id, $data) {
        // 使用罗马时区获取当前时间
        $now = new DateTime('now', new DateTimeZone('Europe/Rome'));
        $now_str = $now->format('Y-m-d H:i:s');
        
        // 获取当前日期
        $work_date = $now->format('Y-m-d');
        
        // 创建新的签到记录
        $insert_query = "INSERT INTO " . $this->records_table . " 
                        (user_id, tenant_id, work_date, check_in_time, 
                         check_in_location, check_in_geo_latitude, check_in_geo_longitude, 
                         check_in_device, check_in_wifi, check_in_photo_url, status, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'normal', ?)";
        
        $stmt = $this->conn->prepare($insert_query);
        $result = $stmt->execute([
            $user_id, 
            $tenant_id, 
            $work_date, 
            $now_str,
            $data['location'] ?? null,
            $data['latitude'] ?? null, 
            $data['longitude'] ?? null,
            $data['device'] ?? null, 
            $data['wifi'] ?? null,
            $data['photo'] ?? null,
            $data['notes'] ?? null
        ]);
        
        $record_id = $this->conn->lastInsertId();
        
        // 获取今天已签到的次数
        $count_query = "SELECT COUNT(*) as count FROM " . $this->records_table . " 
                        WHERE user_id = ? AND tenant_id = ? AND work_date = ? AND check_in_time IS NOT NULL";
        
        $count_stmt = $this->conn->prepare($count_query);
        $count_stmt->execute([$user_id, $tenant_id, $work_date]);
        $count_result = $count_stmt->fetch(PDO::FETCH_ASSOC);
        $check_in_count = $count_result['count'];
        
        if ($result) {
            return [
                'success' => true,
                'message' => '第' . $check_in_count . '次签到成功',
                'data' => [
                    'record_id' => $record_id,
                    'check_in_time' => $now_str,
                    'check_in_count' => $check_in_count
                ]
            ];
        } else {
            return [
                'success' => false,
                'message' => '签到失败，请重试'
            ];
        }
    }
    
    // 签退 - 支持多次签退
    public function checkOut($user_id, $tenant_id, $data) {
        // 使用罗马时区获取当前时间
        $now = new DateTime('now', new DateTimeZone('Europe/Rome'));
        $now_str = $now->format('Y-m-d H:i:s');
        
        // 获取当前日期
        $work_date = $now->format('Y-m-d');
        
        // 创建新的签退记录
        $insert_query = "INSERT INTO " . $this->records_table . " 
                        (user_id, tenant_id, work_date, check_out_time, 
                         check_out_location, check_out_geo_latitude, check_out_geo_longitude, 
                         check_out_device, check_out_wifi, check_out_photo_url, status, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'normal', ?)";
        
        $stmt = $this->conn->prepare($insert_query);
        $result = $stmt->execute([
            $user_id, 
            $tenant_id, 
            $work_date, 
            $now_str,
            $data['location'] ?? null,
            $data['latitude'] ?? null, 
            $data['longitude'] ?? null,
            $data['device'] ?? null, 
            $data['wifi'] ?? null,
            $data['photo'] ?? null,
            $data['notes'] ?? null
        ]);
        
        $record_id = $this->conn->lastInsertId();
        
        // 获取今天已签退的次数
        $count_query = "SELECT COUNT(*) as count FROM " . $this->records_table . " 
                        WHERE user_id = ? AND tenant_id = ? AND work_date = ? AND check_out_time IS NOT NULL";
        
        $count_stmt = $this->conn->prepare($count_query);
        $count_stmt->execute([$user_id, $tenant_id, $work_date]);
        $count_result = $count_stmt->fetch(PDO::FETCH_ASSOC);
        $check_out_count = $count_result['count'];
        
        // 尝试找到最近的签到记录来配对计算工作时长
        $last_check_in_query = "SELECT * FROM " . $this->records_table . " 
                                WHERE user_id = ? AND tenant_id = ? AND work_date = ? AND check_in_time IS NOT NULL 
                                AND record_id NOT IN (
                                    SELECT a.record_id FROM " . $this->records_table . " a
                                    JOIN " . $this->records_table . " b ON a.user_id = b.user_id 
                                    AND a.tenant_id = b.tenant_id AND a.work_date = b.work_date
                                    WHERE a.check_in_time IS NOT NULL AND b.check_out_time IS NOT NULL
                                    AND a.record_id != b.record_id
                                    AND a.check_in_time < b.check_out_time
                                )
                                ORDER BY check_in_time DESC LIMIT 1";
        
        $last_check_in_stmt = $this->conn->prepare($last_check_in_query);
        $last_check_in_stmt->execute([$user_id, $tenant_id, $work_date]);
        $last_check_in = $last_check_in_stmt->fetch(PDO::FETCH_ASSOC);
        
        $working_hours = null;
        
        // 如果找到了可配对的签到记录，计算工作时长
        if ($last_check_in && !empty($last_check_in['check_in_time'])) {
            $check_in_time = new DateTime($last_check_in['check_in_time'], new DateTimeZone('Europe/Rome'));
            $check_out_time = new DateTime($now_str, new DateTimeZone('Europe/Rome'));
            $interval = $check_in_time->diff($check_out_time);
            $working_hours = $interval->h + ($interval->i / 60);
            
            // 更新工作时长
            $update_hours_query = "UPDATE " . $this->records_table . " SET working_hours = ? WHERE record_id = ?";
            $update_hours_stmt = $this->conn->prepare($update_hours_query);
            $update_hours_stmt->execute([$working_hours, $record_id]);
        }
        
        if ($result) {
            return [
                'success' => true,
                'message' => '第' . $check_out_count . '次签退成功',
                'data' => [
                    'record_id' => $record_id,
                    'check_out_time' => $now_str,
                    'working_hours' => $working_hours ? round($working_hours, 2) : null,
                    'check_out_count' => $check_out_count
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
        try {
            // 使用罗马时区获取当前日期
            $work_date = (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d');
            
            error_log("Fetching attendance for user: $user_id, tenant: $tenant_id, date: $work_date");
            
            // 查询当天的所有记录
            $query = "SELECT * FROM " . $this->records_table . " 
                      WHERE user_id = ? AND tenant_id = ? AND work_date = ?
                      ORDER BY record_id DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$user_id, $tenant_id, $work_date]);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("Records found: " . count($records));
            
            if (empty($records)) {
                return [
                    'has_record' => false,
                    'checked_in' => false,
                    'checked_out' => false,
                    'records' => []
                ];
            }
            
            // 获取所有签到和签退记录
            $check_in_records = array_filter($records, function($record) {
                return !empty($record['check_in_time']);
            });
            
            $check_out_records = array_filter($records, function($record) {
                return !empty($record['check_out_time']);
            });
            
            // 获取最后一条记录
            $last_record = $records[0];
            
            // 计算总工作时长
            $total_working_hours = 0;
            foreach ($records as $record) {
                $total_working_hours += $record['working_hours'] ?? 0;
            }
            
            return [
                'has_record' => true,
                'records' => $records,
                'check_in_records' => array_values($check_in_records),
                'check_out_records' => array_values($check_out_records),
                'check_in_count' => count($check_in_records),
                'check_out_count' => count($check_out_records),
                'total_records' => count($records),
                'total_working_hours' => round($total_working_hours, 2),
                'first_check_in' => $check_in_records ? end($check_in_records)['check_in_time'] : null,
                'last_check_out' => $check_out_records ? reset($check_out_records)['check_out_time'] : null,
                'latest_record' => $last_record,
                'checked_in' => !empty($check_in_records),
                'checked_out' => !empty($check_out_records)
            ];
        } catch (Exception $e) {
            error_log("Error in getTodayAttendance: " . $e->getMessage());
            error_log("Trace: " . $e->getTraceAsString());
            
            throw new Exception("Unable to retrieve today's attendance: " . $e->getMessage());
        }
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
                  WHERE user_id = ? AND tenant_id = ? AND work_date = ?
                  ORDER BY record_id DESC LIMIT 1";
        
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
        // 添加调试日志
        error_log("Review Correction - ID: $correction_id, Approver: $approver_id, Status: $status");
        
        // 获取补卡申请
        $query = "SELECT * FROM " . $this->corrections_table . " WHERE correction_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$correction_id]);
        $correction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 添加日志验证申请是否存在
        if (!$correction) {
            error_log("Correction not found: $correction_id");
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
        // 创建新记录而不是更新现有记录，以支持多次打卡
        $check_in_time = $correction['correction_type'] == 'check_in' ? $correction['corrected_time'] : null;
        $check_out_time = $correction['correction_type'] == 'check_out' ? $correction['corrected_time'] : null;
        
        $query = "INSERT INTO " . $this->records_table . " 
                 (user_id, tenant_id, work_date, check_in_time, check_out_time, status, notes) 
                 VALUES (?, ?, ?, ?, ?, 'normal', '补卡记录')";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            $correction['user_id'],
            $correction['tenant_id'],
            $correction['work_date'],
            $check_in_time,
            $check_out_time
        ]);
        
        // 如果是签退补卡，尝试计算工作时长
        if ($correction['correction_type'] == 'check_out' && $check_out_time) {
            // 尝试找到最近未配对的签到记录
            $last_check_in_query = "SELECT * FROM " . $this->records_table . " 
                                    WHERE user_id = ? AND tenant_id = ? AND work_date = ? 
                                    AND check_in_time IS NOT NULL AND record_id != LAST_INSERT_ID()
                                    ORDER BY check_in_time DESC LIMIT 1";
            
            $last_check_in_stmt = $this->conn->prepare($last_check_in_query);
            $last_check_in_stmt->execute([
                $correction['user_id'],
                $correction['tenant_id'],
                $correction['work_date']
            ]);
            $last_check_in = $last_check_in_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($last_check_in && !empty($last_check_in['check_in_time'])) {
                $check_in_time = new DateTime($last_check_in['check_in_time']);
                $check_out_time = new DateTime($correction['corrected_time']);
                $interval = $check_in_time->diff($check_out_time);
                $working_hours = $interval->h + ($interval->i / 60);
                
                $record_id = $this->conn->lastInsertId();
                $update_query = "UPDATE " . $this->records_table . " SET working_hours = ? WHERE record_id = ?";
                $update_stmt = $this->conn->prepare($update_query);
                $update_stmt->execute([$working_hours, $record_id]);
            }
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
                  ORDER BY work_date ASC, record_id ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$user_id, $tenant_id, $start_date, $end_date]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 按日期分组统计
        $dates = [];
        $normal_days = 0;
        $late_days = 0;
        $early_leave_days = 0;
        $absent_days = 0;
        $total_working_hours = 0;
        
        foreach ($records as $record) {
            $date = $record['work_date'];
            
            if (!isset($dates[$date])) {
                $dates[$date] = [
                    'has_late' => false,
                    'has_early_leave' => false,
                    'has_absent' => false,
                    'working_hours' => 0
                ];
            }
            
            // 检查状态
            if ($record['status'] === 'late') {
                $dates[$date]['has_late'] = true;
            } else if ($record['status'] === 'early_leave') {
                $dates[$date]['has_early_leave'] = true;
            } else if ($record['status'] === 'absent') {
                $dates[$date]['has_absent'] = true;
            }
            
            // 累计工作时长
            $dates[$date]['working_hours'] += $record['working_hours'] ?? 0;
            $total_working_hours += $record['working_hours'] ?? 0;
        }
        
                    // 统计各类天数
        foreach ($dates as $date_stats) {
            if ($date_stats['has_absent']) {
                $absent_days++;
            } else if ($date_stats['has_late']) {
                $late_days++;
            } else if ($date_stats['has_early_leave']) {
                $early_leave_days++;
            } else {
                $normal_days++;
            }
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
            'total_days' => count($dates),
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