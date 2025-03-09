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
$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base_path = '/aapi';  // 根路径
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
            } elseif ($method === 'active-tenants' && $_SERVER['REQUEST_METHOD'] === 'GET') {
                // 获取活跃租户列表（用于登录页面选择框）
                // 此路由不需要身份验证，任何人都可以访问
                $auth_controller->getActiveTenants();
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
            case 'shifts':
    error_log("Shifts controller called.");
    require_once './controllers/ShiftController.php';
    require_once './middleware/AuthMiddleware.php';
    
    $shift_controller = new ShiftController();
    
    // 验证认证
    $auth = new AuthMiddleware();
    if (!$auth->isAuthenticated()) {
        Response::json(401, 'Unauthorized');
        break;
    }
    
    if ($method === 'index' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $shift_controller->getShifts();
    } elseif (empty($method) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        // 兼容前端直接调用/shifts的情况
        $shift_controller->getShifts();
    } elseif (is_numeric($method) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $shift_controller->getShiftById($method);
    } elseif ($method === 'index' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden');
            break;
        }
        $shift_controller->createShift();
    } elseif (is_numeric($method) && $_SERVER['REQUEST_METHOD'] === 'PUT') {
        if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden');
            break;
        }
        $shift_controller->updateShift($method);
    } elseif (is_numeric($method) && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
        if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden');
            break;
        }
        $shift_controller->deleteShift($method);
    } else {
        Response::json(404, 'Endpoint not found');
    }
    break;

// 排班规则相关路由
case 'schedule-rules':
    error_log("Schedule Rules controller called.");
    require_once './controllers/ScheduleRuleController.php';
    require_once './middleware/AuthMiddleware.php';
    
    $rule_controller = new ScheduleRuleController();
    
    // 验证认证
    $auth = new AuthMiddleware();
    if (!$auth->isAuthenticated()) {
        Response::json(401, 'Unauthorized');
        break;
    }
    
    if ($method === 'index' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $rule_controller->getRules();
    } elseif (empty($method) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        // 兼容前端直接调用/schedule-rules的情况
        $rule_controller->getRules();
    } elseif ($method === 'applicable' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $rule_controller->getApplicableRules();
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
    } elseif (preg_match('/^(\d+)\/status$/', $method, $matches) && $_SERVER['REQUEST_METHOD'] === 'PUT') {
        if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden');
            break;
        }
        $rule_controller->toggleRuleStatus($matches[1]);
    } else {
        Response::json(404, 'Endpoint not found');
    }
    break;

// 排班模板相关路由
case 'schedule-templates':
    error_log("Schedule Templates controller called.");
    require_once './controllers/ScheduleTemplateController.php';
    require_once './middleware/AuthMiddleware.php';
    
    $template_controller = new ScheduleTemplateController();
    
    // 验证认证
    $auth = new AuthMiddleware();
    if (!$auth->isAuthenticated()) {
        Response::json(401, 'Unauthorized');
        break;
    }
    
    if ($method === 'index' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $template_controller->getTemplates();
    } elseif (empty($method) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        // 兼容前端直接调用/schedule-templates的情况
        $template_controller->getTemplates();
    } elseif (is_numeric($method) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $template_controller->getTemplateById($method);
    } elseif ($method === 'index' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$auth->hasRole(['department_admin', 'tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden');
            break;
        }
        $template_controller->createTemplate();
    } elseif (is_numeric($method) && $_SERVER['REQUEST_METHOD'] === 'PUT') {
        if (!$auth->hasRole(['department_admin', 'tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden');
            break;
        }
        $template_controller->updateTemplate($method);
    } elseif (is_numeric($method) && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
        if (!$auth->hasRole(['department_admin', 'tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden');
            break;
        }
        $template_controller->deleteTemplate($method);
    } else {
        Response::json(404, 'Endpoint not found');
    }
    break;

// 特殊日期相关路由
case 'special-dates':
    error_log("Special Dates controller called.");
    require_once './controllers/SpecialDateController.php';
    require_once './middleware/AuthMiddleware.php';
    
    $date_controller = new SpecialDateController();
    
    // 验证认证
    $auth = new AuthMiddleware();
    if (!$auth->isAuthenticated()) {
        Response::json(401, 'Unauthorized');
        break;
    }
    
    if ($method === 'index' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $date_controller->getSpecialDates();
    } elseif (empty($method) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        // 兼容前端直接调用/special-dates的情况
        $date_controller->getSpecialDates();
    } elseif ($method === 'index' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden');
            break;
        }
        $date_controller->createSpecialDate();
    } elseif ($method === 'batch' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden');
            break;
        }
        $date_controller->batchCreateSpecialDates();
    } elseif (is_numeric($method) && $_SERVER['REQUEST_METHOD'] === 'PUT') {
        if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden');
            break;
        }
        $date_controller->updateSpecialDate($method);
    } elseif (is_numeric($method) && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
        if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden');
            break;
        }
        $date_controller->deleteSpecialDate($method);
    } else {
        Response::json(404, 'Endpoint not found');
    }
    break;

// 排班管理相关路由
case 'schedules':
    error_log("Schedules controller called.");
    require_once './controllers/ScheduleController.php';
    require_once './middleware/AuthMiddleware.php';
    
    $schedule_controller = new ScheduleController();
    
    // 验证认证
    $auth = new AuthMiddleware();
    if (!$auth->isAuthenticated()) {
        Response::json(401, 'Unauthorized');
        break;
    }
    
    if ($method === 'my' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $schedule_controller->getMySchedules();
    } elseif (preg_match('/^department\/(\d+)$/', $method, $matches) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!$auth->hasRole(['department_admin', 'tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden');
            break;
        }
        $schedule_controller->getDepartmentSchedules($matches[1]);
    } elseif ($method === 'batch' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$auth->hasRole(['department_admin', 'tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden');
            break;
        }
        $schedule_controller->batchCreateOrUpdateSchedules();
    } elseif ($method === 'generate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$auth->hasRole(['department_admin', 'tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden');
            break;
        }
        $schedule_controller->generateSchedules();
    } elseif ($method === 'statistics' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!$auth->hasRole(['department_admin', 'tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden');
            break;
        }
        $schedule_controller->getScheduleStats();
    } elseif ($method === 'daily-stats' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!$auth->hasRole(['department_admin', 'tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden');
            break;
        }
        $schedule_controller->getDailyScheduleStats();
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
            //位置WiFi管理
            case 'system':
                error_log("System controller called.");
                require_once './controllers/SystemController.php';
                require_once './middleware/AuthMiddleware.php';
                
                $system_controller = new SystemController();
                
                // 验证认证
                $auth = new AuthMiddleware();
                if (!$auth->isAuthenticated()) {
                    Response::json(401, 'Unauthorized');
                    break;
                }
                
                if ($method === 'check-location' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                    $system_controller->checkLocation();
                } elseif ($method === 'wifi-info' && $_SERVER['REQUEST_METHOD'] === 'GET') {
                    $system_controller->getWifiInfo();
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
            case 'roles':
                error_log("Roles controller called.");
                require_once './controllers/RoleController.php';
                require_once './middleware/AuthMiddleware.php';
                
                $role_controller = new RoleController();
                
                // 验证认证
                $auth = new AuthMiddleware();
                if (!$auth->isAuthenticated()) {
                    Response::json(401, 'Unauthorized');
                    break;
                }
                
                if ($method === 'index' && $_SERVER['REQUEST_METHOD'] === 'GET') {
                    $role_controller->getRoles();
                } elseif (empty($method) && $_SERVER['REQUEST_METHOD'] === 'GET') {
                    // 兼容前端直接调用/roles的情况
                    $role_controller->getRoles();
                } elseif (is_numeric($method) && $_SERVER['REQUEST_METHOD'] === 'GET') {
                    $role_controller->getRoleById($method);
                } elseif ($method === 'index' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                    if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
                        Response::json(403, 'Forbidden');
                        break;
                    }
                    $role_controller->createRole();
                } elseif (is_numeric($method) && $_SERVER['REQUEST_METHOD'] === 'PUT') {
                    if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
                        Response::json(403, 'Forbidden');
                        break;
                    }
                    $role_controller->updateRole($method);
                } elseif (is_numeric($method) && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
                    if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
                        Response::json(403, 'Forbidden');
                        break;
                    }
                    $role_controller->deleteRole($method);
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
            } elseif ($method === 'employee-monthly-timesheet-data' && $_SERVER['REQUEST_METHOD'] === 'GET') {
                $report_controller->getEmployeeTimesheetData();
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