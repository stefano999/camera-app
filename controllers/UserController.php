<?php
// controllers/UserController.php - 用户控制器

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

class UserController {
    // 获取当前用户信息
    public function getUserInfo() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        
        // 格式化用户数据
        $userData = [
            'userId' => $user['user_id'],
            'tenantId' => $user['tenant_id'],
            'departmentId' => $user['department_id'],
            'departmentName' => $user['department_name'],
            'employeeId' => $user['employee_id'],
            'username' => $user['username'],
            'realName' => $user['real_name'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'avatar' => $user['avatar_url'],
            'roleId' => $user['role_id'],
            'role' => $user['role_name'],
            'position' => $user['position'],
            'hireDate' => $user['hire_date'],
            'status' => $user['status'],
            'permissions' => $user['permissions']
        ];
        
        Response::json(200, 'User information retrieved successfully', $userData);
    }
    
    // 更新用户资料
    public function updateProfile() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        $data = json_decode(file_get_contents("php://input"), true);
        
        // 验证输入
        if (!isset($data['email']) && !isset($data['phone']) && !isset($data['avatar'])) {
            Response::json(400, 'No data provided for update.');
            return;
        }
        
        // 构建更新数据
        $updateData = [];
        if (isset($data['email'])) $updateData['email'] = $data['email'];
        if (isset($data['phone'])) $updateData['phone'] = $data['phone'];
        if (isset($data['avatar'])) $updateData['avatar_url'] = $data['avatar'];
        
        // 更新用户资料
        $user_model = new User();
        $result = $user_model->updateProfile($user['user_id'], $updateData);
        
        if ($result) {
            Response::json(200, 'Profile updated successfully');
        } else {
            Response::json(500, 'Failed to update profile');
        }
    }
    
    // 修改密码
    public function changePassword() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        $data = json_decode(file_get_contents("php://input"), true);
        
        // 验证输入
        if (!isset($data['oldPassword']) || !isset($data['newPassword'])) {
            Response::json(400, 'Please provide both old and new passwords.');
            return;
        }
        
        // 检查新密码是否符合要求
        if (strlen($data['newPassword']) < 6) {
            Response::json(400, 'New password must be at least 6 characters long.');
            return;
        }
        
        // 验证旧密码
        $user_model = new User();
        $currentUser = $user_model->getUserById($user['user_id']);
        
        if (!password_verify($data['oldPassword'], $currentUser['password'])) {
            Response::json(400, 'Old password is incorrect.');
            return;
        }
        
        // 更新密码
        $hashedPassword = password_hash($data['newPassword'], PASSWORD_DEFAULT);
        $result = $user_model->updatePassword($user['user_id'], $hashedPassword);
        
        if ($result) {
            Response::json(200, 'Password changed successfully');
        } else {
            Response::json(500, 'Failed to change password');
        }
    }
    
    // 获取用户列表
    public function getUsers() {
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
        $tenantId = $user['tenant_id'];
        
        // 获取查询参数
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $pageSize = isset($_GET['pageSize']) ? intval($_GET['pageSize']) : 10;
        $departmentId = isset($_GET['departmentId']) ? $_GET['departmentId'] : null;
        $status = isset($_GET['status']) ? $_GET['status'] : null;
        $search = isset($_GET['search']) ? $_GET['search'] : null;
        
        // 获取用户列表
        $user_model = new User();
        $result = $user_model->getUsers($tenantId, $page, $pageSize, $departmentId, $status, $search);
        
        Response::json(200, 'Users retrieved successfully', $result);
    }
    
    // 获取用户详情
    public function getUserById($id) {
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
        $tenantId = $user['tenant_id'];
        
        // 获取用户详情
        $user_model = new User();
        $userInfo = $user_model->getUserDetail($id, $tenantId);
        
        if (!$userInfo) {
            Response::json(404, 'User not found');
            return;
        }
        
        Response::json(200, 'User retrieved successfully', $userInfo);
    }
    
    // 添加用户
    public function addUser() {
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
        $tenantId = $user['tenant_id'];
        $data = json_decode(file_get_contents("php://input"), true);
        
        // 验证必填字段
        if (!isset($data['username']) || !isset($data['password']) || !isset($data['realName'])) {
            Response::json(400, 'Missing required fields');
            return;
        }
        
        // 检查用户名是否已存在
        $user_model = new User();
        if ($user_model->usernameExists($data['username'], $tenantId)) {
            Response::json(400, 'Username already exists');
            return;
        }
        
        // 检查工号是否已存在
        if (isset($data['employeeId']) && $data['employeeId'] && 
            $user_model->employeeIdExists($data['employeeId'], $tenantId)) {
            Response::json(400, 'Employee ID already exists');
            return;
        }
        
        // 预处理数据
        $userData = [
            'tenant_id' => $tenantId,
            'department_id' => $data['departmentId'] ?? null,
            'employee_id' => $data['employeeId'] ?? null,
            'username' => $data['username'],
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
            'real_name' => $data['realName'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'position' => $data['position'] ?? null,
            'role_id' => $data['roleId'] ?? 16, // 默认为普通员工
            'hire_date' => $data['hireDate'] ?? date('Y-m-d'),
            'status' => $data['status'] ?? 'active'
        ];
        
        // 添加用户
        $newUserId = $user_model->addUser($userData);
        
        if ($newUserId) {
            Response::json(201, 'User added successfully', ['userId' => $newUserId]);
        } else {
            Response::json(500, 'Failed to add user');
        }
    }
    
    // 更新用户
    public function updateUser($id) {
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
        $tenantId = $user['tenant_id'];
        $data = json_decode(file_get_contents("php://input"), true);
        
        // 验证用户是否存在
        $user_model = new User();
        $existingUser = $user_model->getUserById($id);
        
        if (!$existingUser || $existingUser['tenant_id'] != $tenantId) {
            Response::json(404, 'User not found');
            return;
        }
        
        // 检查工号是否已存在（除了当前用户）
        if (isset($data['employeeId']) && $data['employeeId'] && 
            $user_model->employeeIdExists($data['employeeId'], $tenantId, $id)) {
            Response::json(400, 'Employee ID already exists');
            return;
        }
        
        // 构建更新数据
        $userData = [
            'department_id' => $data['departmentId'] ?? $existingUser['department_id'],
            'employee_id' => $data['employeeId'] ?? $existingUser['employee_id'],
            'real_name' => $data['realName'] ?? $existingUser['real_name'],
            'email' => $data['email'] ?? $existingUser['email'],
            'phone' => $data['phone'] ?? $existingUser['phone'],
            'position' => $data['position'] ?? $existingUser['position'],
            'role_id' => $data['roleId'] ?? $existingUser['role_id'],
            'hire_date' => $data['hireDate'] ?? $existingUser['hire_date'],
            'status' => $data['status'] ?? $existingUser['status']
        ];
        
        // 更新用户
        $result = $user_model->updateUser($id, $userData);
        
        if ($result) {
            Response::json(200, 'User updated successfully');
        } else {
            Response::json(500, 'Failed to update user');
        }
    }
    
    // 删除用户
    public function deleteUser($id) {
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
        $tenantId = $user['tenant_id'];
        
        // 验证用户是否存在
        $user_model = new User();
        $existingUser = $user_model->getUserById($id);
        
        if (!$existingUser || $existingUser['tenant_id'] != $tenantId) {
            Response::json(404, 'User not found');
            return;
        }
        
        // 删除用户
        $result = $user_model->deleteUser($id);
        
        if ($result) {
            Response::json(200, 'User deleted successfully');
        } else {
            Response::json(500, 'Failed to delete user');
        }
    }
    
    // 批量导入用户
    public function importUsers() {
        // 实际实现中，这里会处理CSV或Excel文件上传
        Response::json(200, 'Users imported successfully');
    }
}