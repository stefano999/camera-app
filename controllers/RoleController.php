<?php
// controllers/RoleController.php - 角色管理控制器

require_once __DIR__ . '/../models/Role.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/TenantMiddleware.php';

class RoleController {
    // 获取所有角色
    public function getRoles() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        
        $role_model = new Role();
        $roles = $role_model->getRoles($user['tenant_id']);
        
        Response::json(200, 'Roles retrieved successfully', $roles);
    }
    
    // 获取角色详情
    public function getRoleById($id) {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        
        $role_model = new Role();
        $role = $role_model->getRoleById($id, $user['tenant_id']);
        
        if (!$role) {
            Response::json(404, 'Role not found');
            return;
        }
        
        Response::json(200, 'Role retrieved successfully', $role);
    }
    
    // 创建角色
    public function createRole() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        // 只有租户管理员和系统管理员可以创建角色
        if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden');
            return;
        }
        
        $user = $auth->getUser();
        $data = json_decode(file_get_contents("php://input"), true);
        
        // 验证必填字段
        if (!isset($data['role_name']) || !isset($data['permissions'])) {
            Response::json(400, 'Missing required fields: role_name, permissions');
            return;
        }
        
        // 检查角色名称唯一性
        $role_model = new Role();
        if (!$role_model->isRoleNameUnique($data['role_name'], $user['tenant_id'])) {
            Response::json(400, 'Role name already exists');
            return;
        }
        
        // 构建角色数据
        $roleData = [
            'tenant_id' => $user['tenant_id'],
            'role_name' => $data['role_name'],
            'role_description' => $data['role_description'] ?? null,
            'permissions' => $data['permissions']
        ];
        
        // 创建角色
        $role_id = $role_model->createRole($roleData);
        
        if ($role_id) {
            Response::json(201, 'Role created successfully', ['role_id' => $role_id]);
        } else {
            Response::json(500, 'Failed to create role');
        }
    }
    
    // 更新角色
    public function updateRole($id) {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        // 只有租户管理员和系统管理员可以更新角色
        if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden');
            return;
        }
        
        $user = $auth->getUser();
        $data = json_decode(file_get_contents("php://input"), true);
        
        // 验证角色存在性
        $role_model = new Role();
        $existing_role = $role_model->getRoleById($id, $user['tenant_id']);
        
        if (!$existing_role) {
            Response::json(404, 'Role not found');
            return;
        }
        
        // 检查角色名称唯一性
        if (isset($data['role_name']) && $data['role_name'] !== $existing_role['role_name']) {
            if (!$role_model->isRoleNameUnique($data['role_name'], $user['tenant_id'], $id)) {
                Response::json(400, 'Role name already exists');
                return;
            }
        }
        
        // 构建更新数据
        $updateData = [
            'role_name' => $data['role_name'] ?? $existing_role['role_name'],
            'role_description' => $data['role_description'] ?? $existing_role['role_description'],
            'permissions' => $data['permissions'] ?? $existing_role['permissions']
        ];
        
        // 更新角色
        $result = $role_model->updateRole($id, $updateData);
        
        if ($result) {
            Response::json(200, 'Role updated successfully');
        } else {
            Response::json(500, 'Failed to update role');
        }
    }
    
    // 删除角色
    public function deleteRole($id) {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        // 只有租户管理员和系统管理员可以删除角色
        if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden');
            return;
        }
        
        $user = $auth->getUser();
        
        // 验证角色存在性
        $role_model = new Role();
        $existing_role = $role_model->getRoleById($id, $user['tenant_id']);
        
        if (!$existing_role) {
            Response::json(404, 'Role not found');
            return;
        }
        
        // 检查角色是否在使用中
        if ($role_model->isRoleInUse($id)) {
            Response::json(400, 'Cannot delete role that is assigned to users');
            return;
        }
        
        // 删除角色
        $result = $role_model->deleteRole($id, $user['tenant_id']);
        
        if ($result) {
            Response::json(200, 'Role deleted successfully');
        } else {
            Response::json(500, 'Failed to delete role');
        }
    }
}