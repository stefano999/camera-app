<?php
// controllers/ScheduleController.php - 排班管理控制器

require_once __DIR__ . '/../models/Schedule.php';
require_once __DIR__ . '/../models/ScheduleTemplate.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/TenantMiddleware.php';

class ScheduleController {
    // 获取个人排班(员工视图)
    public function getMySchedules() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        
        // 获取查询参数
        $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
        $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
        
        $schedule_model = new Schedule();
        $schedules = $schedule_model->getUserSchedules($user['user_id'], $user['tenant_id'], $start_date, $end_date);
        
        Response::json(200, 'Schedules retrieved successfully', [
            'start_date' => $start_date,
            'end_date' => $end_date,
            'schedules' => $schedules
        ]);
    }
    
    // 获取部门排班(管理员视图)
    public function getDepartmentSchedules($department_id) {
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
        
        // 部门管理员权限检查
        if ($user['permissions'] === 'department_admin' && $user['department_id'] != $department_id) {
            Response::json(403, 'You can only access your own department');
            return;
        }
        
        // 获取查询参数
        $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
        $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
        $view_type = isset($_GET['view_type']) ? $_GET['view_type'] : 'month';
        
        $schedule_model = new Schedule();
        $schedules = $schedule_model->getDepartmentSchedules($department_id, $user['tenant_id'], $start_date, $end_date);
        
        // 根据视图类型组织数据
        $formatted_schedules = [];
        
        if ($view_type === 'day') {
            // 按日期和员工组织
            $date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
            $day_schedules = [];
            
            foreach ($schedules as $schedule) {
                if ($schedule['schedule_date'] === $date) {
                    $day_schedules[] = $schedule;
                }
            }
            
            $formatted_schedules = $day_schedules;
        } elseif ($view_type === 'week') {
            // 按周和员工组织
            $employees = [];
            $dates = [];
            
            // 收集所有员工和日期
            foreach ($schedules as $schedule) {
                $employee_id = $schedule['user_id'];
                $date = $schedule['schedule_date'];
                
                if (!isset($employees[$employee_id])) {
                    $employees[$employee_id] = [
                        'user_id' => $employee_id,
                        'real_name' => $schedule['real_name'],
                        'employee_id' => $schedule['employee_id'],
                        'schedules' => []
                    ];
                }
                
                if (!in_array($date, $dates)) {
                    $dates[] = $date;
                }
                
                $employees[$employee_id]['schedules'][$date] = $schedule;
            }
            
            sort($dates);
            
            $formatted_schedules = [
                'dates' => $dates,
                'employees' => array_values($employees)
            ];
        } else {
            // 默认按月视图组织
            $days = [];
            $employees = [];
            
            // 收集所有员工
            foreach ($schedules as $schedule) {
                $employee_id = $schedule['user_id'];
                
                if (!isset($employees[$employee_id])) {
                    $employees[$employee_id] = [
                        'user_id' => $employee_id,
                        'real_name' => $schedule['real_name'],
                        'employee_id' => $schedule['employee_id']
                    ];
                }
            }
            
            // 生成日期范围
            $current_date = new DateTime($start_date);
            $end_date_obj = new DateTime($end_date);
            
            while ($current_date <= $end_date_obj) {
                $date_str = $current_date->format('Y-m-d');
                $days[$date_str] = [
                    'date' => $date_str,
                    'day_of_week' => $current_date->format('N'), // 1-7 表示周一到周日
                    'schedules' => []
                ];
                
                $current_date->modify('+1 day');
            }
            
            // 填充排班数据
            foreach ($schedules as $schedule) {
                $date = $schedule['schedule_date'];
                $employee_id = $schedule['user_id'];
                
                if (isset($days[$date])) {
                    $days[$date]['schedules'][$employee_id] = $schedule;
                }
            }
            
            $formatted_schedules = [
                'days' => array_values($days),
                'employees' => array_values($employees)
            ];
        }
        
        Response::json(200, 'Department schedules retrieved successfully', [
            'start_date' => $start_date,
            'end_date' => $end_date,
            'view_type' => $view_type,
            'department_id' => $department_id,
            'schedules' => $formatted_schedules
        ]);
    }
    
    // 批量创建或更新排班
    public function batchCreateOrUpdateSchedules() {
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
        
        // 验证请求数据
        if (!isset($data['schedules']) || !is_array($data['schedules']) || empty($data['schedules'])) {
            Response::json(400, 'Invalid request data. Schedules array is required.');
            return;
        }
        
        // 准备排班数据
        $schedules = [];
        foreach ($data['schedules'] as $schedule) {
            // 验证必填字段
            if (!isset($schedule['user_id']) || !isset($schedule['schedule_date'])) {
                continue;
            }
            
            // 检查用户是否存在且属于当前租户
            $user_model = new User();
            $employee = $user_model->getUserById($schedule['user_id']);
            
            if (!$employee || $employee['tenant_id'] != $user['tenant_id']) {
                continue;
            }
            
            // 部门管理员只能管理自己部门的排班
            if ($user['permissions'] === 'department_admin' && $employee['department_id'] != $user['department_id']) {
                continue;
            }
            
            $schedules[] = [
                'tenant_id' => $user['tenant_id'],
                'user_id' => $schedule['user_id'],
                'shift_id' => $schedule['is_rest_day'] ? null : ($schedule['shift_id'] ?? null),
                'schedule_date' => $schedule['schedule_date'],
                'is_rest_day' => $schedule['is_rest_day'] ?? 0,
                'notes' => $schedule['notes'] ?? null,
                'created_by' => $user['user_id']
            ];
        }
        
        $schedule_model = new Schedule();
        $result = $schedule_model->batchCreateOrUpdateSchedules($schedules);
        
        if ($result['success']) {
            Response::json(200, 'Schedules updated successfully', [
                'success_count' => $result['success_count'],
                'fail_count' => $result['fail_count']
            ]);
        } else {
            Response::json(500, $result['message']);
        }
    }
    
    // 从模板生成排班
    public function generateSchedules() {
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
        
        // 验证请求数据
        if (!isset($data['department_id']) || !isset($data['template_id']) || 
            !isset($data['start_date']) || !isset($data['end_date'])) {
            Response::json(400, 'Invalid request data. department_id, template_id, start_date and end_date are required.');
            return;
        }
        
        // 部门管理员权限检查
        if ($user['permissions'] === 'department_admin' && $user['department_id'] != $data['department_id']) {
            Response::json(403, 'You can only manage schedules for your own department');
            return;
        }
        
        // 验证模板是否存在且属于当前租户
        $template_model = new ScheduleTemplate();
        $template = $template_model->getTemplateWithDetails($data['template_id'], $user['tenant_id']);
        
        if (!$template) {
            Response::json(404, 'Template not found');
            return;
        }
        
        $schedule_model = new Schedule();
        $result = $schedule_model->generateSchedulesFromTemplate(
            $data['department_id'],
            $user['tenant_id'],
            $data['template_id'],
            $data['start_date'],
            $data['end_date'],
            $data['override_existing'] ?? false,
            $user['user_id']
        );
        
        if ($result['success']) {
            Response::json(200, 'Schedules generated successfully', [
                'success_count' => $result['success_count'],
                'fail_count' => $result['fail_count']
            ]);
        } else {
            Response::json(500, $result['message']);
        }
    }
    
    // 获取排班统计
    public function getScheduleStats() {
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
        $department_id = isset($_GET['department_id']) ? intval($_GET['department_id']) : null;
        $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
        $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
        
        // 如果未指定部门，部门管理员使用自己的部门
        if (!$department_id && $user['permissions'] === 'department_admin') {
            $department_id = $user['department_id'];
        }
        
        // 部门管理员权限检查
        if ($user['permissions'] === 'department_admin' && $department_id != $user['department_id']) {
            Response::json(403, 'You can only access statistics for your own department');
            return;
        }
        
        // 验证部门是否存在
        if ($department_id) {
            $department_model = new Department();
            $department = $department_model->getDepartmentById($department_id, $user['tenant_id']);
            
            if (!$department) {
                Response::json(404, 'Department not found');
                return;
            }
        } else {
            Response::json(400, 'Department ID is required');
            return;
        }
        
        $schedule_model = new Schedule();
        $stats = $schedule_model->getScheduleStats($department_id, $user['tenant_id'], $start_date, $end_date);
        
        Response::json(200, 'Schedule statistics retrieved successfully', $stats);
    }
    
    // 获取特定日期的排班统计
    public function getDailyScheduleStats() {
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
        $department_id = isset($_GET['department_id']) ? intval($_GET['department_id']) : null;
        $date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
        
        // 如果未指定部门，部门管理员使用自己的部门
        if (!$department_id && $user['permissions'] === 'department_admin') {
            $department_id = $user['department_id'];
        }
        
        // 部门管理员权限检查
        if ($user['permissions'] === 'department_admin' && $department_id != $user['department_id']) {
            Response::json(403, 'You can only access statistics for your own department');
            return;
        }
        
        // 验证部门是否存在
        if ($department_id) {
            $department_model = new Department();
            $department = $department_model->getDepartmentById($department_id, $user['tenant_id']);
            
            if (!$department) {
                Response::json(404, 'Department not found');
                return;
            }
        } else {
            Response::json(400, 'Department ID is required');
            return;
        }
        
        $schedule_model = new Schedule();
        $stats = $schedule_model->getDailyScheduleStats($department_id, $user['tenant_id'], $date);
        
        Response::json(200, 'Daily schedule statistics retrieved successfully', $stats);
    }
}