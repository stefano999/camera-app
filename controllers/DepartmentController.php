<?php
// controllers/DepartmentController.php - 部门管理控制器

require_once __DIR__ . '/../models/Department.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/TenantMiddleware.php';

class DepartmentController {
    // 获取部门列表
    public function getDepartments() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        
        $department_model = new Department();
        $departments = $department_model->getDepartments($user['tenant_id']);
        
        Response::json(200, 'Departments retrieved successfully', $departments);
    }
    
    // 获取部门树
    public function getDepartmentTree() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        
        $department_model = new Department();
        $tree = $department_model->getDepartmentTree($user['tenant_id']);
        
        Response::json(200, 'Department tree retrieved successfully', $tree);
    }
    
    // 获取部门详情
    public function getDepartmentById($id) {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        
        $department_model = new Department();
        $department = $department_model->getDepartmentById($id, $user['tenant_id']);
        
        if (!$department) {
            Response::json(404, 'Department not found');
            return;
        }
        
        Response::json(200, 'Department retrieved successfully', $department);
    }
    
    // 创建部门
    public function createDepartment() {
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
        $data = json_decode(file_get_contents("php://input"), true);
        
        // 验证必填字段
        if (!isset($data['department_name'])) {
            Response::json(400, 'Department name is required');
            return;
        }
        
        // 检查部门编码唯一性
        if (isset($data['department_code']) && $data['department_code']) {
            $department_model = new Department();
            if ($department_model->departmentCodeExists($data['department_code'], $user['tenant_id'])) {
                Response::json(400, 'Department code already exists');
                return;
            }
        }
        
        // 构建部门数据
        $departmentData = [
            'tenant_id' => $user['tenant_id'],
            'department_name' => $data['department_name'],
            'department_code' => $data['department_code'] ?? null,
            'parent_department_id' => $data['parent_department_id'] ?? null,
            'manager_id' => $data['manager_id'] ?? null
        ];
        
        $department_model = new Department();
        $department_id = $department_model->createDepartment($departmentData);
        
        if ($department_id) {
            Response::json(201, 'Department created successfully', ['department_id' => $department_id]);
        } else {
            Response::json(500, 'Failed to create department');
        }
    }
    
    // 更新部门
    public function updateDepartment($id) {
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
        $data = json_decode(file_get_contents("php://input"), true);
        
        // 验证部门存在性
        $department_model = new Department();
        $existing_department = $department_model->getDepartmentById($id, $user['tenant_id']);
        
        if (!$existing_department) {
            Response::json(404, 'Department not found');
            return;
        }
        
        // 检查部门编码唯一性
        if (isset($data['department_code']) && $data['department_code'] && 
            $data['department_code'] !== $existing_department['department_code']) {
            if ($department_model->departmentCodeExists($data['department_code'], $user['tenant_id'], $id)) {
                Response::json(400, 'Department code already exists');
                return;
            }
        }
        
        // 验证父部门不能是自己
        if (isset($data['parent_department_id']) && $data['parent_department_id'] == $id) {
            Response::json(400, 'Department cannot be its own parent');
            return;
        }
        
        // TODO: 验证父子关系循环依赖
        
        // 构建更新数据
        $updateData = [
            'tenant_id' => $user['tenant_id'],
            'department_name' => $data['department_name'] ?? $existing_department['department_name'],
            'department_code' => $data['department_code'] ?? $existing_department['department_code'],
            'parent_department_id' => $data['parent_department_id'] ?? $existing_department['parent_department_id'],
            'manager_id' => $data['manager_id'] ?? $existing_department['manager_id']
        ];
        
        $result = $department_model->updateDepartment($id, $updateData);
        
        if ($result) {
            Response::json(200, 'Department updated successfully');
        } else {
            Response::json(500, 'Failed to update department');
        }
    }
    
    // 删除部门
    public function deleteDepartment($id) {
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
        
        $department_model = new Department();
        $result = $department_model->deleteDepartment($id, $user['tenant_id']);
        
        if ($result['success']) {
            Response::json(200, $result['message']);
        } else {
            Response::json(400, $result['message']);
        }
    }
    
    // 获取部门员工
    public function getDepartmentEmployees($id) {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        
        // 验证部门存在性
        $department_model = new Department();
        $department = $department_model->getDepartmentById($id, $user['tenant_id']);
        
        if (!$department) {
            Response::json(404, 'Department not found');
            return;
        }
        
        // 部门管理员权限检查
        if ($user['permissions'] === 'department_admin' && $user['department_id'] != $id) {
            Response::json(403, 'You can only access your own department');
            return;
        }
        
        // 获取查询参数
        $include_subdepts = isset($_GET['includeSubdepts']) && $_GET['includeSubdepts'] === 'true';
        
        $employees = $department_model->getDepartmentEmployees($id, $user['tenant_id'], $include_subdepts);
        
        Response::json(200, 'Department employees retrieved successfully', [
            'department' => $department,
            'employees' => $employees
        ]);
    }
    
    // 关联部门考勤规则
    public function setDepartmentRule($id) {
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
        $data = json_decode(file_get_contents("php://input"), true);
        
        // 验证部门存在性
        $department_model = new Department();
        $department = $department_model->getDepartmentById($id, $user['tenant_id']);
        
        if (!$department) {
            Response::json(404, 'Department not found');
            return;
        }
        
        // 验证输入
        if (!isset($data['rule_id'])) {
            Response::json(400, 'Rule ID is required');
            return;
        }
        
        // 关联规则
        $result = $department_model->setDepartmentRule($id, $data['rule_id']);
        
        if ($result) {
            Response::json(200, 'Department rule set successfully');
        } else {
            Response::json(500, 'Failed to set department rule');
        }
    }
    
    // 获取部门考勤规则
    public function getDepartmentRules($id) {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        
        // 验证部门存在性
        $department_model = new Department();
        $department = $department_model->getDepartmentById($id, $user['tenant_id']);
        
        if (!$department) {
            Response::json(404, 'Department not found');
            return;
        }
        
        $rules = $department_model->getDepartmentRules($id);
        
        Response::json(200, 'Department rules retrieved successfully', $rules);
    }
    
    // 删除部门考勤规则关联
    public function deleteDepartmentRule($id, $rule_id) {
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
        
        // 验证部门存在性
        $department_model = new Department();
        $department = $department_model->getDepartmentById($id, $user['tenant_id']);
        
        if (!$department) {
            Response::json(404, 'Department not found');
            return;
        }
        
        $result = $department_model->deleteDepartmentRule($id, $rule_id);
        
        if ($result) {
            Response::json(200, 'Department rule deleted successfully');
        } else {
            Response::json(500, 'Failed to delete department rule');
        }
    }
}