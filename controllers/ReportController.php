<?php
// controllers/ReportController.php - 报表管理控制器

require_once __DIR__ . '/../models/Attendance.php';
require_once __DIR__ . '/../models/Department.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Tenant.php';
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
        
        // 获取员工列表 - 替换模拟数据
        $employees = [];
        
        if ($department_id) {
            // 如果指定了部门，获取该部门的员工
            $department_model = new Department();
            $employees = $department_model->getDepartmentEmployees($department_id, $user['tenant_id'], $include_subdepts);
        } else {
            // 获取租户下的所有员工
            $database = new Database();
            $conn = $database->getConnection();
            
            $query = "SELECT u.user_id, u.real_name, u.employee_id, d.department_name 
                    FROM users u 
                    LEFT JOIN departments d ON u.department_id = d.department_id 
                    WHERE u.tenant_id = ? AND u.status = 'active'";
            
            $stmt = $conn->prepare($query);
            $stmt->execute([$user['tenant_id']]);
            $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
        try {
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
            error_log("User authenticated: " . json_encode($user));
            
            // 获取查询参数
            $year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
            $month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
            error_log("Request params - year: $year, month: $month");
            
            // 构建日期范围
            $start_date = sprintf('%04d-%02d-01', $year, $month);
            $end_date = date('Y-m-t', strtotime($start_date));
            
            // 获取所有部门
            $department_model = new Department();
            $departments = $department_model->getDepartments($user['tenant_id']);
            error_log("Departments found: " . count($departments));
            
            if (empty($departments)) {
                Response::json(200, 'No departments found', [
                    'year' => $year,
                    'month' => $month,
                    'total_departments' => 0,
                    'total_employees' => 0,
                    'department_stats' => []
                ]);
                return;
            }
            
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
                $attendance_rate = $employee_count > 0 ? ($attendance_days / max(1, ($attendance_days + $absent_days + $late_days + $early_leave_days))) * 100 : 0;
                
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
        } catch (Exception $e) {
            error_log("Error in getDepartmentComparison: " . $e->getMessage());
            Response::json(500, 'Server error: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取员工月度出勤表数据
     * 符合意大利和西班牙的法律要求
     */
    public function getEmployeeTimesheetData() {
        try {
            $auth = new AuthMiddleware();
            
            if (!$auth->isAuthenticated()) {
                Response::json(401, 'Unauthorized');
                return;
            }
            
            // 获取并验证参数
            $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;
            $year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
            $month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
            $locale = isset($_GET['locale']) && in_array($_GET['locale'], ['it', 'es']) ? $_GET['locale'] : 'it';
            
            // 验证用户ID
            if (!$user_id) {
                Response::json(400, 'User ID is required');
                return;
            }
            
            // 验证访问权限
            $current_user = $auth->getUser();
            $user_model = new User();
            $employee = $user_model->getUserById($user_id);
            
            // 验证用户存在且属于同一租户
            if (!$employee || $employee['tenant_id'] != $current_user['tenant_id']) {
                Response::json(404, 'Employee not found');
                return;
            }
            
            // 检查权限 (自己的记录或有管理权限)
            if ($user_id != $current_user['user_id'] && 
                !$auth->hasRole(['department_admin', 'tenant_admin', 'system_admin']) &&
                ($current_user['permissions'] === 'department_admin' && $employee['department_id'] != $current_user['department_id'])) {
                Response::json(403, 'Forbidden');
                return;
            }
            
            // 构建日期范围
            $start_date = sprintf('%04d-%02d-01', $year, $month);
            $end_date = date('Y-m-t', strtotime($start_date));
            $days_in_month = date('t', strtotime($start_date));
            
            // 获取考勤数据
            $attendance_model = new Attendance();
            $records = $attendance_model->getAttendanceRecords($user_id, $employee['tenant_id'], $start_date, $end_date);
            
            // 获取员工所属部门
            $department_model = new Department();
            $department = null;
            if ($employee['department_id']) {
                $department = $department_model->getDepartmentById($employee['department_id'], $employee['tenant_id']);
            }
            
            // 获取公司信息
            $tenant_model = new Tenant();
            $company = $tenant_model->getTenantById($employee['tenant_id']);
            
            // 计算工作日统计
            $total_days = count($records);
            $total_working_hours = 0;
            $total_overtime_hours = 0;
            
            // 按日期索引记录
            $indexed_records = [];
            foreach ($records as $record) {
                $date = $record['work_date'];
                $indexed_records[$date] = $record;
                $total_working_hours += floatval($record['working_hours'] ?? 0);
            }
            
            // 获取加班记录
            $overtime_query = "SELECT * FROM overtime_requests 
                            WHERE user_id = ? AND tenant_id = ? 
                            AND overtime_date BETWEEN ? AND ?
                            AND status = 'approved'";
            
            $database = new Database();
            $conn = $database->getConnection();
            $stmt = $conn->prepare($overtime_query);
            $stmt->execute([$user_id, $employee['tenant_id'], $start_date, $end_date]);
            $overtimes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 计算加班时间
            foreach ($overtimes as $overtime) {
                $total_overtime_hours += floatval($overtime['total_hours']);
            }
            
            // 准备输出数据
            $timesheet_data = [
                'company' => [
                    'name' => $company['tenant_name'] ?? '',
                    'address' => $company['address'] ?? '',
                    'contact' => $company['contact_phone'] ?? ''
                ],
                'employee' => [
                    'id' => $employee['employee_id'] ?? $employee['user_id'],
                    'name' => $employee['real_name'],
                    'position' => $employee['position'] ?? '',
                    'department' => $department ? $department['department_name'] : '',
                    'hire_date' => $employee['hire_date'] ?? ''
                ],
                'period' => [
                    'year' => $year,
                    'month' => $month,
                    'month_name' => $this->getMonthName($month, $locale),
                    'days_in_month' => $days_in_month
                ],
                'summary' => [
                    'total_working_days' => $total_days,
                    'total_working_hours' => round($total_working_hours, 2),
                    'total_overtime_hours' => round($total_overtime_hours, 2),
                    'grand_total_hours' => round($total_working_hours + $total_overtime_hours, 2)
                ],
                'daily_records' => [],
                'signature_fields' => [
                    'employee' => [
                        'label' => $locale == 'it' ? 'Firma del dipendente' : 'Firma del empleado',
                        'name' => $employee['real_name'],
                        'date' => date('Y-m-d')
                    ],
                    'employer' => [
                        'label' => $locale == 'it' ? 'Firma del datore di lavoro' : 'Firma del empleador',
                        'name' => $company['contact_name'] ?? '',
                        'date' => date('Y-m-d')
                    ]
                ],
                'legal_text' => $this->getLegalText($locale)
            ];
            
            // 填充每日记录
            for ($day = 1; $day <= $days_in_month; $day++) {
                $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                $weekday = date('N', strtotime($date)); // 1 (周一) 到 7 (周日)
                $is_weekend = ($weekday == 6 || $weekday == 7); // 周六和周日
                
                $daily_record = [
                    'date' => $date,
                    'weekday' => $this->getWeekdayName($weekday, $locale),
                    'is_weekend' => $is_weekend,
                    'check_in' => null,
                    'check_out' => null,
                    'working_hours' => 0,
                    'overtime_hours' => 0,
                    'status' => 'absent', // 默认缺勤
                    'notes' => ''
                ];
                
                // 如果有考勤记录，填充数据
                if (isset($indexed_records[$date])) {
                    $record = $indexed_records[$date];
                    $daily_record['check_in'] = $record['check_in_time'];
                    $daily_record['check_out'] = $record['check_out_time'];
                    $daily_record['working_hours'] = round(floatval($record['working_hours'] ?? 0), 2);
                    $daily_record['status'] = $record['status'];
                    $daily_record['notes'] = $record['notes'] ?? '';
                }
                
                // 加入加班记录
                foreach ($overtimes as $overtime) {
                    if ($overtime['overtime_date'] == $date) {
                        $daily_record['overtime_hours'] += round(floatval($overtime['total_hours']), 2);
                        if (empty($daily_record['notes'])) {
                            $daily_record['notes'] = 'Overtime: ' . $overtime['reason'];
                        } else {
                            $daily_record['notes'] .= '; Overtime: ' . $overtime['reason'];
                        }
                    }
                }
                
                $timesheet_data['daily_records'][] = $daily_record;
            }
            
            Response::json(200, 'Employee timesheet data retrieved successfully', $timesheet_data);
        } catch (Exception $e) {
            error_log("Error in getEmployeeTimesheetData: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            Response::json(500, 'Server error: ' . $e->getMessage());
        }
    }

    /**
     * 获取月份名称
     */
    private function getMonthName($month, $locale) {
        $months = [
            'it' => [
                1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile',
                5 => 'Maggio', 6 => 'Giugno', 7 => 'Luglio', 8 => 'Agosto',
                9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre'
            ],
            'es' => [
                1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
                5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
                9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
            ]
        ];
        
        return $months[$locale][$month] ?? '';
    }

    /**
     * 获取星期几名称
     */
    private function getWeekdayName($weekday, $locale) {
        $weekdays = [
            'it' => [
                1 => 'Lunedì', 2 => 'Martedì', 3 => 'Mercoledì', 4 => 'Giovedì',
                5 => 'Venerdì', 6 => 'Sabato', 7 => 'Domenica'
            ],
            'es' => [
                1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves',
                5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'
            ]
        ];
        
        return $weekdays[$locale][$weekday] ?? '';
    }

    /**
     * 获取法律文本
     */
    private function getLegalText($locale) {
        $texts = [
            'it' => [
                'title' => 'FOGLIO PRESENZE MENSILE',
                'declaration' => 'Il sottoscritto dichiara che le informazioni riportate in questo documento sono veritiere e accurate.',
                'privacy' => 'I dati personali saranno trattati in conformità con il Regolamento Generale sulla Protezione dei Dati (GDPR).',
                'legal_note' => 'Questo documento è conforme alle normative del lavoro italiane vigenti.'
            ],
            'es' => [
                'title' => 'HOJA DE CONTROL DE TIEMPO MENSUAL',
                'declaration' => 'El abajo firmante declara que la información contenida en este documento es verdadera y precisa.',
                'privacy' => 'Los datos personales serán tratados de acuerdo con el Reglamento General de Protección de Datos (RGPD).',
                'legal_note' => 'Este documento cumple con la normativa laboral española vigente.'
            ]
        ];
        
        return $texts[$locale] ?? $texts['it'];
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