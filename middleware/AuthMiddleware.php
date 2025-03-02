<?php
// middleware/AuthMiddleware.php - 认证中间件

require_once __DIR__ . '/../utils/JwtHelper.php';
require_once __DIR__ . '/../models/User.php';

class AuthMiddleware {
    private $user = null;
    
    public function __construct() {
        $this->authenticate();
    }
    
    // 检查是否已认证
    public function isAuthenticated() {
        return $this->user !== null;
    }
    
    // 获取当前用户
    public function getUser() {
        return $this->user;
    }
    
    // 检查用户是否有指定角色
    public function hasRole($roles) {
        if (!$this->user) {
            return false;
        }
        
        // 系统管理员具有所有权限
        if ($this->user['permissions'] === 'all') {
            return true;
        }
        
        // 检查用户是否具有指定角色
        $roles = is_array($roles) ? $roles : [$roles];
        
        foreach ($roles as $role) {
            if ($role === 'any') {
                return true;
            }
            
            if ($this->user['permissions'] === 'tenant_admin' && 
               ($role === 'tenant_admin' || $role === 'department_admin' || $role === 'employee')) {
                return true;
            }
            
            if ($this->user['permissions'] === 'department_admin' && 
               ($role === 'department_admin' || $role === 'employee')) {
                return true;
            }
            
            if ($this->user['permissions'] === 'employee' && $role === 'employee') {
                return true;
            }
        }
        
        return false;
    }
    
    // 认证处理
    private function authenticate() {
        // 获取Authorization头
        $headers = getallheaders();
        $auth_header = isset($headers['Authorization']) ? $headers['Authorization'] : '';
        
        if (!$auth_header || !preg_match('/Bearer\s(\S+)/', $auth_header, $matches)) {
            return;
        }
        
        $token = $matches[1];
        $payload = JwtHelper::validateToken($token);
        
        if (!$payload) {
            return;
        }
        
        // 获取用户信息
        $user_model = new User();
        $user = $user_model->getUserById($payload['userId']);
        
        if (!$user || $user['status'] !== 'active') {
            return;
        }
        
        // 设置当前用户
        $this->user = $user;
    }
}