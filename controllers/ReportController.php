<?php
// controllers/ReportController.php - 报表管理控制器

require_once __DIR__ . '/../models/Attendance.php';
require_once __DIR__ . '/../models/Department.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/TenantMiddleware.php';

class ReportController {
    // 获取考勤汇总报表
    public function getAttendanceSummary() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        if (!$auth->hasRole(['department_admin', 'tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden');
            return;
        }
        
        $user = $auth->getUser();
        
        // 获取查询参数
        $department_id = isset($_GET['departmentId']) ? intval($_GET['departmentId']) : null;
        $year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
        $month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
        $include_subdepts = isset($_GET['includeSubdepts']) && $_GET['includeSubdepts'] === 'true';
        
        // 部门管理员权限校验
        if ($user['permissions'] === 'department_admin') {
            if ($department_id === null) {
                $department_id = $user['department_id'];
            } else if ($department_id != $user['department_id']) {
                Response::json(403, 'You can only access your own department');
                return;
            }
        }
        
        // 构建日期范围
        $start_date = sprintf('%04d-%02d-01', $year, $month);
        $end_date = date('Y-m-t', strtotime($start_date));
        
        // 获取部门员工
        $department_model = new Department();
        $employees = [];
        
        if ($department_id) {
            $employees = $department_model->getDepartmentEmployees($department_id, $user['tenant_id'], $include_subdepts);
        } else {
            // 如果没有指定部门，则为租户管理员或系统管理员，获取所有员工
            // 这里需要实现一个获取所有员工的方法，暂时使用模拟数据
            $employees = [
                ['user_id' => 1, 'real_name' => '张三'],
                ['user_id' => 2, 'real_name' => '李四']
            ];
        }
        
        // 获取每个员工的考勤数据
        $attendance_model = new Attendance();
        $employee_stats = [];
        
        foreach ($employees as $employee) {
            $stats = $attendance_model->getUserMonthlyStats($employee['user_id'], $user['tenant_id'], $year, $month);
            $employee_stats[] = [
                'user_id' => $employee['user_id'],
                'real_name' => $employee['real_name'],
                'employee_id' => $employee['employee_id'] ?? null,
                'department_name' => $employee['department_name'] ?? null,
                'stats' => $stats
            ];
        }
        
        // 计算总体统计
        $total_employees = count($employees);
        $total_attendance_days = 0;
        $total_absent_days = 0;
        $total_late_days = 0;
        $total_early_leave_days = 0;
        $total_working_hours = 0;
        
        foreach ($employee_stats as $stat) {
            $total_attendance_days += $stat['stats']['normal_days'];
            $total_absent_days += $stat['stats']['absent_days'];
            $total_late_days += $stat['stats']['late_days'];
            $total_early_leave_days += $stat['stats']['early_leave_days'];
            $total_working_hours += $stat['stats']['total_working_hours'];
        }
        
        // 计算平均工作时长
        $avg_working_hours = $total_employees > 0 ? $total_working_hours / $total_employees : 0;
        
        $summary = [
            'year' => $year,
            'month' => $month,
            'department_id' => $department_id,
            'total_employees' => $total_employees,
            'total_attendance_days' => $total_attendance_days,
            'total_absent_days' => $total_absent_days,
            'total_late_days' => $total_late_days,
            'total_early_leave_days' => $total_early_leave_days,
            'total_working_hours' => round($total_working_hours, 2),
            'avg_working_hours' => round($avg_working_hours, 2),
            'employee_stats' => $employee_stats
        ];
        
        Response::json(200, 'Attendance summary retrieved successfully', $summary);
    }
    
    // 获取部门对比报表
    public function getDepartmentComparison() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden');
            return;
        }
        
        $user = $auth->getUser();
        
        // 获取查询参数
        $year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
        $month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
        
        // 构建日期范围
        $start_date = sprintf('%04d-%02d-01', $year, $month);
        $end_date = date('Y-m-t', strtotime($start_date));
        
        // 获取所有部门
        $department_model = new Department();
        $departments = $department_model->getDepartments($user['tenant_id']);
        
        // 获取每个部门的考勤统计
        $department_stats = [];
        
        foreach ($departments as $department) {
            // 获取部门员工
            $employees = $department_model->getDepartmentEmployees($department['department_id'], $user['tenant_id'], false);
            $employee_count = count($employees);
            
            if ($employee_count === 0) {
                continue; // 跳过没有员工的部门
            }
            
            // 统计数据
            $attendance_days = 0;
            $absent_days = 0;
            $late_days = 0;
            $early_leave_days = 0;
            $total_working_hours = 0;
            
            $attendance_model = new Attendance();
            
            foreach ($employees as $employee) {
                $stats = $attendance_model->getUserMonthlyStats($employee['user_id'], $user['tenant_id'], $year, $month);
                $attendance_days += $stats['normal_days'];
                $absent_days += $stats['absent_days'];
                $late_days += $stats['late_days'];
                $early_leave_days += $stats['early_leave_days'];
                $total_working_hours += $stats['total_working_hours'];
            }
            
            // 计算平均值
            $avg_working_hours = $employee_count > 0 ? $total_working_hours / $employee_count : 0;
            $attendance_rate = $employee_count > 0 ? ($attendance_days / ($attendance_days + $absent_days + $late_days + $early_leave_days)) * 100 : 0;
            
            $department_stats[] = [
                'department_id' => $department['department_id'],
                'department_name' => $department['department_name'],
                'employee_count' => $employee_count,
                'attendance_days' => $attendance_days,
                'absent_days' => $absent_days,
                'late_days' => $late_days,
                'early_leave_days' => $early_leave_days,
                'avg_working_hours' => round($avg_working_hours, 2),
                'attendance_rate' => round($attendance_rate, 2)
            ];
        }
        
        // 计算总体统计
        $total_employees = array_sum(array_column($department_stats, 'employee_count'));
        $total_attendance_days = array_sum(array_column($department_stats, 'attendance_days'));
        $total_absent_days = array_sum(array_column($department_stats, 'absent_days'));
        
        $comparison = [
            'year' => $year,
            'month' => $month,
            'total_departments' => count($department_stats),
            'total_employees' => $total_employees,
            'total_attendance_days' => $total_attendance_days,
            'total_absent_days' => $total_absent_days,
            'department_stats' => $department_stats
        ];
        
        Response::json(200, 'Department comparison retrieved successfully', $comparison);
    }
    
    // 导出员工考勤报表
    public function exportEmployeeReport() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        
        // 获取查询参数
        $year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
        $month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
        $format = isset($_GET['format']) ? strtolower($_GET['format']) : 'json';
        
        // 获取用户考勤数据
        $attendance_model = new Attendance();
        $stats = $attendance_model->getUserMonthlyStats($user['user_id'], $user['tenant_id'], $year, $month);
        
        // 准备导出数据
        $export_data = [
            'employee' => [
                'user_id' => $user['user_id'],
                'real_name' => $user['real_name'],
                'employee_id' => $user['employee_id'],
                'department' => $user['department_name']
            ],
            'period' => [
                'year' => $year,
                'month' => $month
            ],
            'summary' => [
                'total_days' => $stats['total_days'],
                'normal_days' => $stats['normal_days'],
                'late_days' => $stats['late_days'],
                'early_leave_days' => $stats['early_leave_days'],
                'absent_days' => $stats['absent_days'],
                'total_working_hours' => $stats['total_working_hours'],
                'total_overtime' => $stats['total_overtime']
            ],
            'records' => $stats['records']
        ];
        
        // 根据格式输出
        if ($format === 'json') {
            Response::json(200, 'Employee report generated successfully', $export_data);
        } else {
            // 其他格式导出将在实际项目中实现
            Response::json(400, 'Unsupported export format');
        }
    }
    
    // 导出部门考勤报表
    public function exportDepartmentReport() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        if (!$auth->hasRole(['department_admin', 'tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden');
            return;
        }
        
        $user = $auth->getUser();
        
        // 获取查询参数
        $department_id = isset($_GET['departmentId']) ? intval($_GET['departmentId']) : $user['department_id'];
        $year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
        $month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
        $format = isset($_GET['format']) ? strtolower($_GET['format']) : 'json';
        
        // 部门管理员权限校验
        if ($user['permissions'] === 'department_admin' && $department_id != $user['department_id']) {
            Response::json(403, 'You can only access your own department');
            return;
        }
        
        // 验证部门存在性
        $department_model = new Department();
        $department = $department_model->getDepartmentById($department_id, $user['tenant_id']);
        
        if (!$department) {
            Response::json(404, 'Department not found');
            return;
        }
        
        // 获取部门员工
        $employees = $department_model->getDepartmentEmployees($department_id, $user['tenant_id'], false);
        
        // 获取每个员工的考勤数据
        $attendance_model = new Attendance();
        $employee_stats = [];
        
        foreach ($employees as $employee) {
            $stats = $attendance_model->getUserMonthlyStats($employee['user_id'], $user['tenant_id'], $year, $month);
            $employee_stats[] = [
                'user_id' => $employee['user_id'],
                'real_name' => $employee['real_name'],
                'employee_id' => $employee['employee_id'],
                'stats' => $stats
            ];
        }
        
        // 准备导出数据
        $export_data = [
            'department' => [
                'department_id' => $department['department_id'],
                'department_name' => $department['department_name'],
                'employee_count' => count($employees)
            ],
            'period' => [
                'year' => $year,
                'month' => $month
            ],
            'employee_stats' => $employee_stats
        ];
        
        // 根据格式输出
        if ($format === 'json') {
            Response::json(200, 'Department report generated successfully', $export_data);
        } else {
            // 其他格式导出将在实际项目中实现
            Response::json(400, 'Unsupported export format');
        }
    }
    
    // 获取考勤异常报表
    public function getAbnormalReport() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        if (!$auth->hasRole(['department_admin', 'tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden');
            return;
        }
        
        $user = $auth->getUser();
        
        // 获取查询参数
        $department_id = isset($_GET['departmentId']) ? intval($_GET['departmentId']) : null;
        $start_date = isset($_GET['startDate']) ? $_GET['startDate'] : date('Y-m-01');
        $end_date = isset($_GET['endDate']) ? $_GET['endDate'] : date('Y-m-t');
        $type = isset($_GET['type']) ? $_GET['type'] : 'all'; // all, late, early_leave, absent
        
        // 部门管理员权限校验
        if ($user['permissions'] === 'department_admin') {
            if ($department_id === null) {
                $department_id = $user['department_id'];
            } else if ($department_id != $user['department_id']) {
                Response::json(403, 'You can only access your own department');
                return;
            }
        }
        
        // 构建查询条件
        $conditions = [];
        $params = [];
        
        $conditions[] = "ar.tenant_id = ?";
        $params[] = $user['tenant_id'];
        
        $conditions[] = "ar.work_date BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
        
        if ($type !== 'all') {
            $conditions[] = "ar.status = ?";
            $params[] = $type;
        } else {
            $conditions[] = "ar.status IN ('late', 'early_leave', 'absent')";
        }
        
        if ($department_id) {
            $conditions[] = "u.department_id = ?";
            $params[] = $department_id;
        }
        
        $where_clause = implode(" AND ", $conditions);
        
        // 执行查询
        $database = new Database();
        $conn = $database->getConnection();
        
        $query = "SELECT ar.*, u.real_name, u.employee_id, d.department_name 
                 FROM attendance_records ar
                 JOIN users u ON ar.user_id = u.user_id
                 JOIN departments d ON u.department_id = d.department_id
                 WHERE $where_clause
                 ORDER BY ar.work_date DESC, u.real_name ASC";
        
        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 统计异常数量
        $late_count = 0;
        $early_leave_count = 0;
        $absent_count = 0;
        
        foreach ($records as $record) {
            switch ($record['status']) {
                case 'late':
                    $late_count++;
                    break;
                case 'early_leave':
                    $early_leave_count++;
                    break;
                case 'absent':
                    $absent_count++;
                    break;
            }
        }
        
        $report = [
            'period' => [
                'start_date' => $start_date,
                'end_date' => $end_date
            ],
            'department_id' => $department_id,
            'type' => $type,
            'summary' => [
                'total_abnormal' => count($records),
                'late_count' => $late_count,
                'early_leave_count' => $early_leave_count,
                'absent_count' => $absent_count
            ],
            'records' => $records
        ];
        
        Response::json(200, 'Abnormal attendance report generated successfully', $report);
    }
}