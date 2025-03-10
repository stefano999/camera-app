<?php
// controllers/AttendanceController.php - 打卡记录控制器

require_once __DIR__ . '/../models/Attendance.php';
require_once __DIR__ . '/../models/AttendanceRule.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/TenantMiddleware.php';

class AttendanceController {
    // 签到
    public function checkIn() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        $data = json_decode(file_get_contents("php://input"), true);
        
        $attendance_model = new Attendance();
        $result = $attendance_model->checkIn($user['user_id'], $user['tenant_id'], $data);
        
        if ($result['success']) {
            Response::json(200, $result['message'], $result['data']);
        } else {
            Response::json(400, $result['message'], $result['data'] ?? null);
        }
    }
    
    // 签退
    public function checkOut() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        $data = json_decode(file_get_contents("php://input"), true);
        
        $attendance_model = new Attendance();
        $result = $attendance_model->checkOut($user['user_id'], $user['tenant_id'], $data);
        
        if ($result['success']) {
            Response::json(200, $result['message'], $result['data']);
        } else {
            Response::json(400, $result['message'], $result['data'] ?? null);
        }
    }
    
    // 获取今日打卡状态
    public function getTodayStatus() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        
        $attendance_model = new Attendance();
        $result = $attendance_model->getTodayAttendance($user['user_id'], $user['tenant_id']);
        
        // 获取用户适用的考勤规则
        $rule_model = new AttendanceRule();
        $rule = $rule_model->getApplicableRule($user['user_id'], $user['tenant_id']);
        
        $response = [
            'todayAttendance' => $result,
            'applicableRule' => $rule
        ];
        
        Response::json(200, 'Today attendance status retrieved successfully', $response);
    }
    
    // 获取个人打卡记录
    public function getMyRecords() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        
        // 获取查询参数
        $year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
        $month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
        
        // 构建日期范围
        $start_date = sprintf('%04d-%02d-01', $year, $month);
        $end_date = date('Y-m-t', strtotime($start_date));
        
        $attendance_model = new Attendance();
        $records = $attendance_model->getAttendanceRecords($user['user_id'], $user['tenant_id'], $start_date, $end_date);
        
        Response::json(200, 'Attendance records retrieved successfully', [
            'year' => $year,
            'month' => $month,
            'records' => $records
        ]);
    }
    
    // 获取部门打卡记录
    public function getDepartmentRecords() {
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
        
        // 验证部门ID
        if ($department_id === null) {
            Response::json(400, 'Department ID is required');
            return;
        }
        
        // 验证权限 (部门管理员只能查看自己管理的部门)
        if ($user['permissions'] === 'department_admin' && $user['department_id'] != $department_id) {
            Response::json(403, 'You can only access your own department');
            return;
        }
        
        // 构建日期范围
        $start_date = sprintf('%04d-%02d-01', $year, $month);
        $end_date = date('Y-m-t', strtotime($start_date));
        
        $attendance_model = new Attendance();
        $records = $attendance_model->getDepartmentAttendanceRecords($department_id, $user['tenant_id'], $start_date, $end_date);
        
        Response::json(200, 'Department attendance records retrieved successfully', [
            'departmentId' => $department_id,
            'year' => $year,
            'month' => $month,
            'records' => $records
        ]);
    }
    
    // 申请补卡
    public function applyCorrection() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        $data = json_decode(file_get_contents("php://input"), true);
        
        $attendance_model = new Attendance();
        $result = $attendance_model->applyCorrection($user['user_id'], $user['tenant_id'], $data);
        
        if ($result['success']) {
            Response::json(200, $result['message'], $result['data'] ?? null);
        } else {
            Response::json(400, $result['message']);
        }
    }
    
    // 获取我的申请记录
    public function getMyRequests() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        
        $attendance_model = new Attendance();
        $requests = $attendance_model->getUserPendingRequests($user['user_id'], $user['tenant_id']);
        
        Response::json(200, 'Requests retrieved successfully', $requests);
    }
    
    // 获取待审批的申请
    public function getPendingApprovals() {
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
        
        $attendance_model = new Attendance();
        $requests = $attendance_model->getPendingApprovals($user['user_id'], $user['tenant_id']);
        
        Response::json(200, 'Pending approvals retrieved successfully', $requests);
    }
    
    // 审核补卡申请
    public function reviewCorrection($correction_id) {
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
        $data = json_decode(file_get_contents("php://input"), true);
        
        // 添加详细的调试日志
        error_log("Correction ID in method: " . $correction_id);
        error_log("Request Data: " . json_encode($data));
        
        // 验证输入
        if (!isset($data['status']) || !in_array($data['status'], ['approved', 'rejected'])) {
            Response::json(400, 'Invalid status. Status must be either approved or rejected');
            return;
        }
        
        $attendance_model = new Attendance();
        $result = $attendance_model->reviewCorrection(
            $correction_id, 
            $user['user_id'], 
            $data['status'], 
            $data['comment'] ?? null
        );
        
        if ($result['success']) {
            Response::json(200, $result['message']);
        } else {
            Response::json(400, $result['message']);
        }
    }
    
    // 申请加班
    public function applyOvertime() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        $data = json_decode(file_get_contents("php://input"), true);
        
        $attendance_model = new Attendance();
        $result = $attendance_model->applyOvertime($user['user_id'], $user['tenant_id'], $data);
        
        if ($result['success']) {
            Response::json(200, $result['message'], $result['data'] ?? null);
        } else {
            Response::json(400, $result['message']);
        }
    }
    
    // 审核加班申请
    public function reviewOvertime($request_id) {
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
        $data = json_decode(file_get_contents("php://input"), true);
        
        // 验证输入
        if (!isset($data['status']) || !in_array($data['status'], ['approved', 'rejected'])) {
            Response::json(400, 'Invalid status. Status must be either approved or rejected');
            return;
        }
        
        $attendance_model = new Attendance();
        $result = $attendance_model->reviewOvertime(
            $request_id, 
            $user['user_id'], 
            $data['status'], 
            $data['comment'] ?? null
        );
        
        if ($result['success']) {
            Response::json(200, $result['message']);
        } else {
            Response::json(400, $result['message']);
        }
    }
    
    // 获取用户月度考勤统计
    public function getMonthlyStats() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        
        // 获取查询参数
        $year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
        $month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
        
        $attendance_model = new Attendance();
        $stats = $attendance_model->getUserMonthlyStats($user['user_id'], $user['tenant_id'], $year, $month);
        
        Response::json(200, 'Monthly statistics retrieved successfully', $stats);
    }
    
    // 获取团队月度考勤统计
    public function getTeamMonthlyStats() {
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
        
        // 验证权限 (部门管理员只能查看自己管理的部门)
        if ($user['permissions'] === 'department_admin' && $user['department_id'] != $department_id) {
            Response::json(403, 'You can only access your own department');
            return;
        }
        
        // 此处应实现获取团队月度考勤统计的逻辑
        // 由于此功能需要更复杂的数据处理，这里仅返回示例数据
        $response = [
            'departmentId' => $department_id,
            'year' => $year,
            'month' => $month,
            'summary' => [
                'total_employees' => 10,
                'attendance_rate' => 96.5,
                'avg_working_hours' => 8.2,
                'late_count' => 5,
                'early_leave_count' => 3,
                'absent_count' => 2
            ]
        ];
        
        Response::json(200, 'Team monthly statistics retrieved successfully', $response);
    }
}