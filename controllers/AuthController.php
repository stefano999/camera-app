<?php
// controllers/AuthController.php - 认证控制器

require_once __DIR__ . '/../utils/JwtHelper.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Tenant.php';
require_once __DIR__ . '/../utils/Response.php';

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
            $admin = $user_model->getSysAdmin($username);
            
            if (!$admin) {
                Response::json(401, 'Invalid credentials for system admin.');
                return;
            }
            
            // 验证密码
            if (!password_verify($password, $admin['password'])) {
                Response::json(401, 'Invalid credentials for system admin.');
                return;
            }
            
            // 生成令牌
            $token = JwtHelper::generateToken($admin['user_id'], $admin['tenant_id']);
            
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
    
    // 记录系统日志
    private function logAction($tenant_id, $user_id, $action, $description) {
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
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);
    }
}