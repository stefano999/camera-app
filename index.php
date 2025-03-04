<?php
// index.php - API主入口文件

// 调试输出
error_log("Request: " . $_SERVER['REQUEST_URI']);
error_log("Request Method: " . $_SERVER['REQUEST_METHOD']);

// 允许跨域请求
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=UTF-8');

// 如果是OPTIONS请求，直接返回200状态码
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 引入必要文件
require_once './config/database.php';
require_once './utils/Response.php';

// 获取请求路径
$request_uri = $_SERVER['REQUEST_URI'];
$base_path = '/aapi';  // 新的根路径
$path = str_replace($base_path, '', $request_uri);

// 解析请求路径以确定控制器和方法
$path_parts = explode('/', ltrim($path, '/'));
$controller = isset($path_parts[0]) && !empty($path_parts[0]) ? $path_parts[0] : 'index';
$method = isset($path_parts[1]) && !empty($path_parts[1]) ? $path_parts[1] : 'index';
$param = isset($path_parts[2]) ? $path_parts[2] : null;

// 调试日志
error_log("Controller: " . $controller);
error_log("Method: " . $method);
error_log("Param: " . $param);

// 路由分发
try {
    switch ($controller) {
        case 'auth':
            error_log("Auth controller called.");
            require_once './controllers/AuthController.php';
            $auth_controller = new AuthController();
            
            if ($method === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                error_log("Executing login method");
                $auth_controller->login();
            } elseif ($method === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $auth_controller->logout();
            } elseif ($method === 'tenants' && $_SERVER['REQUEST_METHOD'] === 'GET') {
                $auth_controller->getTenants();
            } elseif ($method === 'tenants' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $auth_controller->createTenant();
            } elseif (preg_match('/^tenants\/(\d+)$/', $method, $matches) && $_SERVER['REQUEST_METHOD'] === 'GET') {
                $auth_controller->getTenantDetail($matches[1]);
            } elseif (preg_match('/^tenants\/(\d+)$/', $method, $matches) && $_SERVER['REQUEST_METHOD'] === 'PUT') {
                $auth_controller->updateTenant($matches[1]);
            } elseif (preg_match('/^tenants\/(\d+)\/status$/', $method, $matches) && $_SERVER['REQUEST_METHOD'] === 'PUT') {
                $auth_controller->updateTenantStatus($matches[1]);
            } else {
                error_log("Auth endpoint not found: $method");
                Response::json(404, 'Endpoint not found');
            }
            break;
            
        case 'user':
            error_log("User controller called.");
            require_once './controllers/UserController.php';
            require_once './middleware/AuthMiddleware.php';
            
            $user_controller = new UserController();
            
            // 验证认证
            $auth = new AuthMiddleware();
            if (!$auth->isAuthenticated()) {
                Response::json(401, 'Unauthorized');
                break;
            }
            
            if ($method === 'info' && $_SERVER['REQUEST_METHOD'] === 'GET') {
                $user_controller->getUserInfo();
            } elseif ($method === 'profile' && $_SERVER['REQUEST_METHOD'] === 'PUT') {
                $user_controller->updateProfile();
            } elseif ($method === 'change-password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $user_controller->changePassword();
            } elseif (empty($method) || $method === 'index') {
                // 兼容前端直接调用/user的情况
                if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
                    Response::json(403, 'Forbidden');
                    break;
                }
                error_log("Redirecting /user to getUsers");
                $user_controller->getUsers();
            } else {
                Response::json(404, 'Endpoint not found');
            }
            break;
            
        case 'users':
            error_log("Users controller called.");
            require_once './controllers/UserController.php';
            require_once './middleware/AuthMiddleware.php';
            
            $user_controller = new UserController();
            
            // 验证认证和授权
            $auth = new AuthMiddleware();
            if (!$auth->isAuthenticated()) {
                Response::json(401, 'Unauthorized');
                break;
            }
            
            if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
                Response::json(403, 'Forbidden');
                break;
            }
            
            if ($method === 'index' && $_SERVER['REQUEST_METHOD'] === 'GET') {
                $user_controller->getUsers();
            } elseif (empty($method) && $_SERVER['REQUEST_METHOD'] === 'GET') {
                // 兼容前端直接调用/users的情况
                $user_controller->getUsers();
            } elseif (is_numeric($method) && $_SERVER['REQUEST_METHOD'] === 'GET') {
                $user_controller->getUserById($method);
            } elseif ($method === 'index' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $user_controller->addUser();
            } elseif (is_numeric($method) && $_SERVER['REQUEST_METHOD'] === 'PUT') {
                $user_controller->updateUser($method);
            } elseif (is_numeric($method) && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
                $user_controller->deleteUser($method);
            } elseif ($method === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $user_controller->importUsers();
            } else {
                Response::json(404, 'Endpoint not found');
            }
            break;
            
        case 'attendance':
            error_log("Attendance controller called.");
            require_once './controllers/AttendanceController.php';
            require_once './middleware/AuthMiddleware.php';
            
            $attendance_controller = new AttendanceController();
            
            // 验证认证
            $auth = new AuthMiddleware();
            if (!$auth->isAuthenticated()) {
                Response::json(401, 'Unauthorized');
                break;
            }
            
            // 根据方法分发请求
            if ($method === 'check-in' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $attendance_controller->checkIn();
            } elseif ($method === 'check-out' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $attendance_controller->checkOut();
            } elseif ($method === 'today' && $_SERVER['REQUEST_METHOD'] === 'GET') {
                $attendance_controller->getTodayStatus();
            } elseif ($method === 'records' && $_SERVER['REQUEST_METHOD'] === 'GET') {
                $attendance_controller->getMyRecords();
            } elseif ($method === 'department-records' && $_SERVER['REQUEST_METHOD'] === 'GET') {
                if (!$auth->hasRole(['department_admin', 'tenant_admin', 'system_admin'])) {
                    Response::json(403, 'Forbidden');
                    break;
                }
                $attendance_controller->getDepartmentRecords();
            } elseif ($method === 'correction' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $attendance_controller->applyCorrection();
            } elseif ($method === 'overtime' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $attendance_controller->applyOvertime();
            } elseif ($method === 'requests' && $_SERVER['REQUEST_METHOD'] === 'GET') {
                $attendance_controller->getMyRequests();
            } elseif ($method === 'approvals' && $_SERVER['REQUEST_METHOD'] === 'GET') {
                if (!$auth->hasRole(['department_admin', 'tenant_admin', 'system_admin'])) {
                    Response::json(403, 'Forbidden');
                    break;
                }
                $attendance_controller->getPendingApprovals();
            } elseif ($method === 'monthly-stats' && $_SERVER['REQUEST_METHOD'] === 'GET') {
                $attendance_controller->getMonthlyStats();
            } elseif ($method === 'team-stats' && $_SERVER['REQUEST_METHOD'] === 'GET') {
                if (!$auth->hasRole(['department_admin', 'tenant_admin', 'system_admin'])) {
                    Response::json(403, 'Forbidden');
                    break;
                }
                $attendance_controller->getTeamMonthlyStats();
            } elseif (preg_match('/^correction\/(\d+)$/', $method, $matches) && $_SERVER['REQUEST_METHOD'] === 'PUT') {
                if (!$auth->hasRole(['department_admin', 'tenant_admin', 'system_admin'])) {
                    Response::json(403, 'Forbidden');
                    break;
                }
                $attendance_controller->reviewCorrection($matches[1]);
            } elseif (preg_match('/^overtime\/(\d+)$/', $method, $matches) && $_SERVER['REQUEST_METHOD'] === 'PUT') {
                if (!$auth->hasRole(['department_admin', 'tenant_admin', 'system_admin'])) {
                    Response::json(403, 'Forbidden');
                    break;
                }
                $attendance_controller->reviewOvertime($matches[1]);
            } else {
                Response::json(404, 'Endpoint not found');
            }
            break;
            
        // 添加部门管理相关路由
        case 'departments':
            error_log("Departments controller called.");
            require_once './controllers/DepartmentController.php';
            require_once './middleware/AuthMiddleware.php';
            
            $department_controller = new DepartmentController();
            
            // 验证认证
            $auth = new AuthMiddleware();
            if (!$auth->isAuthenticated()) {
                Response::json(401, 'Unauthorized');
                break;
            }
            
            if ($method === 'index' && $_SERVER['REQUEST_METHOD'] === 'GET') {
                $department_controller->getDepartments();
            } elseif (empty($method) && $_SERVER['REQUEST_METHOD'] === 'GET') {
                // 兼容前端直接调用/departments的情况
                $department_controller->getDepartments();
            } elseif ($method === 'tree' && $_SERVER['REQUEST_METHOD'] === 'GET') {
                $department_controller->getDepartmentTree();
            } elseif (is_numeric($method) && $_SERVER['REQUEST_METHOD'] === 'GET') {
                $department_controller->getDepartmentById($method);
            } elseif ($method === 'index' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
                    Response::json(403, 'Forbidden');
                    break;
                }
                $department_controller->createDepartment();
            } elseif (is_numeric($method) && $_SERVER['REQUEST_METHOD'] === 'PUT') {
                if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
                    Response::json(403, 'Forbidden');
                    break;
                }
                $department_controller->updateDepartment($method);
            } elseif (is_numeric($method) && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
                if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
                    Response::json(403, 'Forbidden');
                    break;
                }
                $department_controller->deleteDepartment($method);
            } elseif (preg_match('/^(\d+)\/employees$/', $method, $matches) && $_SERVER['REQUEST_METHOD'] === 'GET') {
                $department_controller->getDepartmentEmployees($matches[1]);
            } elseif (preg_match('/^(\d+)\/rules$/', $method, $matches) && $_SERVER['REQUEST_METHOD'] === 'GET') {
                $department_controller->getDepartmentRules($matches[1]);
            } elseif (preg_match('/^(\d+)\/rule$/', $method, $matches) && $_SERVER['REQUEST_METHOD'] === 'POST') {
                if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
                    Response::json(403, 'Forbidden');
                    break;
                }
                $department_controller->setDepartmentRule($matches[1]);
            } elseif (preg_match('/^(\d+)\/rule\/(\d+)$/', $method, $matches) && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
                if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
                    Response::json(403, 'Forbidden');
                    break;
                }
                $department_controller->deleteDepartmentRule($matches[1], $matches[2]);
            } else {
                Response::json(404, 'Endpoint not found');
            }
            break;
            
        // 添加考勤规则相关路由
        case 'rules':
            error_log("Rules controller called.");
            require_once './controllers/AttendanceRuleController.php';
            require_once './middleware/AuthMiddleware.php';
            
            $rule_controller = new AttendanceRuleController();
            
            // 验证认证
            $auth = new AuthMiddleware();
            if (!$auth->isAuthenticated()) {
                Response::json(401, 'Unauthorized');
                break;
            }
            
            if ($method === 'index' && $_SERVER['REQUEST_METHOD'] === 'GET') {
                $rule_controller->getRules();
            } elseif (empty($method) && $_SERVER['REQUEST_METHOD'] === 'GET') {
                // 兼容前端直接调用/rules的情况
                $rule_controller->getRules();
            } elseif (is_numeric($method) && $_SERVER['REQUEST_METHOD'] === 'GET') {
                $rule_controller->getRuleById($method);
            } elseif ($method === 'index' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
                    Response::json(403, 'Forbidden');
                    break;
                }
                $rule_controller->createRule();
            } elseif (is_numeric($method) && $_SERVER['REQUEST_METHOD'] === 'PUT') {
                if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
                    Response::json(403, 'Forbidden');
                    break;
                }
                $rule_controller->updateRule($method);
            } elseif (is_numeric($method) && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
                if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
                    Response::json(403, 'Forbidden');
                    break;
                }
                $rule_controller->deleteRule($method);
            } elseif ($method === 'default' && $_SERVER['REQUEST_METHOD'] === 'GET') {
                $rule_controller->getDefaultRule();
            } else {
                Response::json(404, 'Endpoint not found');
            }
            break;
            
        // 添加报表管理相关路由
        case 'reports':
            error_log("Reports controller called.");
            require_once './controllers/ReportController.php';
            require_once './middleware/AuthMiddleware.php';
            
            $report_controller = new ReportController();
            
            // 验证认证
            $auth = new AuthMiddleware();
            if (!$auth->isAuthenticated()) {
                Response::json(401, 'Unauthorized');
                break;
            }
            
            // 大多数报表需要管理员权限
            if ($method === 'attendance-summary' && $_SERVER['REQUEST_METHOD'] === 'GET') {
                if (!$auth->hasRole(['department_admin', 'tenant_admin', 'system_admin'])) {
                    Response::json(403, 'Forbidden');
                    break;
                }
                $report_controller->getAttendanceSummary();
            } elseif ($method === 'department-comparison' && $_SERVER['REQUEST_METHOD'] === 'GET') {
                if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
                    Response::json(403, 'Forbidden');
                    break;
                }
                $report_controller->getDepartmentComparison();
            } elseif ($method === 'employee-export' && $_SERVER['REQUEST_METHOD'] === 'GET') {
                $report_controller->exportEmployeeReport();
            } elseif ($method === 'department-export' && $_SERVER['REQUEST_METHOD'] === 'GET') {
                if (!$auth->hasRole(['department_admin', 'tenant_admin', 'system_admin'])) {
                    Response::json(403, 'Forbidden');
                    break;
                }
                $report_controller->exportDepartmentReport();
            } elseif ($method === 'abnormal' && $_SERVER['REQUEST_METHOD'] === 'GET') {
                if (!$auth->hasRole(['department_admin', 'tenant_admin', 'system_admin'])) {
                    Response::json(403, 'Forbidden');
                    break;
                }
                $report_controller->getAbnormalReport();
            } else {
                Response::json(404, 'Endpoint not found');
            }
            break;
            
        case 'health':
            // 健康检查端点
            Response::json(200, 'API is running', [
                'status' => 'success',
                'timestamp' => date('Y-m-d H:i:s'),
                'environment' => getenv('APP_ENV') ?: 'production'
            ]);
            break;
            
        default:
            Response::json(404, 'API endpoint not found');
            break;
    }
} catch (Exception $e) {
    error_log("Exception: " . $e->getMessage());
    Response::json(500, 'Server error: ' . $e->getMessage());
}