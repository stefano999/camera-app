<?php
// controllers/AuthController.php - 认证控制器

require_once __DIR__ . '/../utils/JwtHelper.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Tenant.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../config/database.php';

class AuthController {
    // 用户登录
    public function login() {
        // 获取POST数据
        $data = json_decode(file_get_contents("php://input"), true);
        
        // 验证输入
        if (!isset($data['username']) || !isset($data['password']) || !isset($data['tenant'])) {
            Response::json(400, 'Please provide username, password and tenant code.');
            return;
        }
        
        $username = $data['username'];
        $password = $data['password'];
        $tenant_code = $data['tenant'];
        
        $user_model = new User();
        $tenant_model = new Tenant();
        
        // 系统管理员登录处理
        if ($tenant_code === 'sysadmin') {
            // 记录登录尝试日志
            $this->logAction(null, null, 'login_attempt', "系统管理员登录尝试: 用户名 $username");
            
            $admin = $user_model->getSysAdmin($username);
            
            // 记录查询结果
            $this->logAction(null, null, 'login_debug', "管理员查询结果: " . json_encode($admin));
            
            if (!$admin) {
                Response::json(401, 'Invalid credentials for system admin.');
                return;
            }
            
            // 验证密码
            $password_verify_result = password_verify($password, $admin['password']);
            
            // 记录密码验证结果
            $this->logAction(null, null, 'login_password_verify', 
                "密码验证结果: " . ($password_verify_result ? '成功' : '失败'));
            
            if (!$password_verify_result) {
                Response::json(401, 'Invalid credentials for system admin.');
                return;
            }
            
            // 生成令牌
            $token = JwtHelper::generateToken($admin['user_id'], $admin['tenant_id']);
            
            // 记录成功登录
            $this->logAction($admin['tenant_id'], $admin['user_id'], 'login', '系统管理员登录成功');
            
            Response::json(200, 'Login successful', ['token' => $token]);
            return;
        }
        
        // 租户用户登录处理
        $tenant = $tenant_model->getTenantByCode($tenant_code);
        
        if (!$tenant || $tenant['status'] !== 'active') {
            Response::json(401, 'Invalid tenant code or tenant is inactive.');
            return;
        }
        
        $user = $user_model->getUserByUsername($username, $tenant['tenant_id']);
        
        if (!$user || $user['status'] !== 'active') {
            Response::json(401, 'Invalid credentials or user is inactive.');
            return;
        }
        
        // 验证密码
        if (!password_verify($password, $user['password'])) {
            Response::json(401, 'Invalid credentials.');
            return;
        }
        
        // 生成令牌
        $token = JwtHelper::generateToken($user['user_id'], $user['tenant_id']);
        
        // 记录成功登录
        $this->logAction($user['tenant_id'], $user['user_id'], 'login', '租户用户登录成功');
        
        Response::json(200, 'Login successful', ['token' => $token]);
    }
    
    // 用户登出
    public function logout() {
        require_once __DIR__ . '/../middleware/AuthMiddleware.php';
        
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        
        // 更新最后登录时间
        $user_model = new User();
        $user_model->updateLastLogin($user['user_id']);
        
        // 记录登出日志
        $this->logAction($user['tenant_id'], $user['user_id'], 'logout', 'User logged out');
        
        Response::json(200, 'Logout successful');
    }
    
    // 获取租户列表
    public function getTenants() {
        require_once __DIR__ . '/../models/Tenant.php';
        
        $tenant_model = new Tenant();
        $tenants = $tenant_model->getActiveTenants();
        
        Response::json(200, 'Tenants retrieved successfully', $tenants);
    }
    
    // 创建新租户
    public function createTenant() {
        // 验证身份和权限（只有系统管理员可以创建租户）
        require_once __DIR__ . '/../middleware/AuthMiddleware.php';
        
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated() || !$auth->hasRole('system_admin')) {
            Response::json(403, 'Forbidden');
            return;
        }
        
        // 获取POST数据
        $data = json_decode(file_get_contents("php://input"), true);
        
        // 验证必填字段
        if (!isset($data['tenant_name']) || !isset($data['tenant_code'])) {
            Response::json(400, 'Missing required fields');
            return;
        }
        
        // 验证租户代码唯一性
        $tenant_model = new Tenant();
        if ($tenant_model->getTenantByCode($data['tenant_code'])) {
            Response::json(400, 'Tenant code already exists');
            return;
        }
        
        // 创建租户
        $tenantData = [
            'tenant_name' => $data['tenant_name'],
            'tenant_code' => $data['tenant_code'],
            'logo_url' => $data['logo_url'] ?? null,
            'contact_name' => $data['contact_name'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'address' => $data['address'] ?? null,
            'max_employees' => $data['max_employees'] ?? 50,
            'status' => 'active'
        ];
        
        $tenant_id = $tenant_model->createTenant($tenantData);
        
        if ($tenant_id) {
            // 记录租户创建日志
            $user = $auth->getUser();
            $this->logAction($user['tenant_id'], $user['user_id'], 'tenant_create', 
                             "创建租户: {$data['tenant_name']} ({$data['tenant_code']})");
            
            Response::json(201, 'Tenant created successfully', ['tenant_id' => $tenant_id]);
        } else {
            Response::json(500, 'Failed to create tenant');
        }
    }
    
    // 更新租户信息
    public function updateTenant($tenant_id) {
        // 验证身份和权限
        require_once __DIR__ . '/../middleware/AuthMiddleware.php';
        
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated() || !$auth->hasRole('system_admin')) {
            Response::json(403, 'Forbidden');
            return;
        }
        
        // 获取PUT数据
        $data = json_decode(file_get_contents("php://input"), true);
        
        // 验证租户存在性
        $tenant_model = new Tenant();
        $existingTenant = $tenant_model->getTenantById($tenant_id);
        
        if (!$existingTenant) {
            Response::json(404, 'Tenant not found');
            return;
        }
        
        // 检查租户代码唯一性（如果有更改）
        if (isset($data['tenant_code']) && $data['tenant_code'] !== $existingTenant['tenant_code']) {
            if ($tenant_model->getTenantByCode($data['tenant_code'])) {
                Response::json(400, 'Tenant code already exists');
                return;
            }
        }
        
        // 构建更新数据
        $updateData = [
            'tenant_name' => $data['tenant_name'] ?? $existingTenant['tenant_name'],
            'tenant_code' => $data['tenant_code'] ?? $existingTenant['tenant_code'],
            'logo_url' => $data['logo_url'] ?? $existingTenant['logo_url'],
            'contact_name' => $data['contact_name'] ?? $existingTenant['contact_name'],
            'contact_email' => $data['contact_email'] ?? $existingTenant['contact_email'],
            'contact_phone' => $data['contact_phone'] ?? $existingTenant['contact_phone'],
            'address' => $data['address'] ?? $existingTenant['address'],
            'max_employees' => $data['max_employees'] ?? $existingTenant['max_employees']
        ];
        
        // 更新租户
        $result = $tenant_model->updateTenant($tenant_id, $updateData);
        
        if ($result) {
            // 记录租户更新日志
            $user = $auth->getUser();
            $this->logAction($user['tenant_id'], $user['user_id'], 'tenant_update', 
                             "更新租户信息: ID {$tenant_id}");
            
            Response::json(200, 'Tenant updated successfully');
        } else {
            Response::json(500, 'Failed to update tenant');
        }
    }
    
    // 更新租户状态（停用/启用）
    public function updateTenantStatus($tenant_id) {
        // 验证身份和权限
        require_once __DIR__ . '/../middleware/AuthMiddleware.php';
        
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated() || !$auth->hasRole('system_admin')) {
            Response::json(403, 'Forbidden');
            return;
        }
        
        // 获取PUT数据
        $data = json_decode(file_get_contents("php://input"), true);
        
        // 验证状态参数
        if (!isset($data['status']) || !in_array($data['status'], ['active', 'inactive', 'suspended'])) {
            Response::json(400, 'Invalid status value');
            return;
        }
        
        // 验证租户存在性
        $tenant_model = new Tenant();
        $existingTenant = $tenant_model->getTenantById($tenant_id);
        
        if (!$existingTenant) {
            Response::json(404, 'Tenant not found');
            return;
        }
        
        // 更新租户状态
        $result = $tenant_model->updateTenantStatus($tenant_id, $data['status']);
        
        if ($result) {
            // 记录租户状态更新日志
            $user = $auth->getUser();
            $this->logAction($user['tenant_id'], $user['user_id'], 'tenant_status_update', 
                             "更新租户状态: ID {$tenant_id}, 状态: {$data['status']}");
            
            Response::json(200, 'Tenant status updated successfully');
        } else {
            Response::json(500, 'Failed to update tenant status');
        }
    }
    
    // 获取单个租户详情
    public function getTenantDetail($tenant_id) {
        // 验证身份和权限
        require_once __DIR__ . '/../middleware/AuthMiddleware.php';
        
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated() || !$auth->hasRole('system_admin')) {
            Response::json(403, 'Forbidden');
            return;
        }
        
        // 获取租户信息
        $tenant_model = new Tenant();
        $tenant = $tenant_model->getTenantById($tenant_id);
        
        if (!$tenant) {
            Response::json(404, 'Tenant not found');
            return;
        }
        
        Response::json(200, 'Tenant retrieved successfully', $tenant);
    }
    
    // 记录系统日志
    private function logAction($tenant_id, $user_id, $action, $description) {
        try {
            $database = new Database();
            $conn = $database->getConnection();
            
            $sql = "INSERT INTO system_logs (tenant_id, user_id, action, description, ip_address, user_agent) 
                    VALUES (?, ?, ?, ?, ?, ?)";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                $tenant_id,
                $user_id,
                $action,
                $description,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
        } catch (Exception $e) {
            // 如果日志记录失败，可以选择记录到文件或忽略
            error_log('日志记录失败: ' . $e->getMessage());
        }
    }
   /**
 * 获取活跃租户列表（用于登录页面选择框）
 * 此方法不需要身份验证，任何人都可以访问
 */
public function getActiveTenants() {
    try {
        require_once __DIR__ . '/../models/Tenant.php';
        
        $tenant_model = new Tenant();
        $tenants = $tenant_model->getActiveTenants();
        
        // 确保结果是数组
        if (!is_array($tenants)) {
            $tenants = [];
        }
        
        // 只返回必要的字段，减少数据传输
        $simplified_tenants = array_map(function($tenant) {
            return [
                'tenant_id' => $tenant['tenant_id'],
                'tenant_name' => $tenant['tenant_name'],
                'tenant_code' => $tenant['tenant_code'],
                'logo_url' => isset($tenant['logo_url']) ? $tenant['logo_url'] : null
            ];
        }, $tenants);
        
        Response::json(200, 'Active tenants retrieved successfully', $simplified_tenants);
    } catch (Exception $e) {
        error_log('Error in getActiveTenants: ' . $e->getMessage());
        Response::json(500, 'Server error retrieving tenants');
    }
}
}