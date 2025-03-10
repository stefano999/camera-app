<?php
// controllers/ScheduleRuleController.php - 排班规则控制器

require_once __DIR__ . '/../models/ScheduleRule.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/TenantMiddleware.php';

class ScheduleRuleController {
    // 获取规则列表
    public function getRules() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        
        $rule_model = new ScheduleRule();
        $rules = $rule_model->getRules($user['tenant_id']);
        
        Response::json(200, 'Rules retrieved successfully', $rules);
    }
    
    // 获取规则详情
    public function getRuleById($id) {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        
        $rule_model = new ScheduleRule();
        $rule = $rule_model->getRuleById($id, $user['tenant_id']);
        
        if (!$rule) {
            Response::json(404, 'Rule not found');
            return;
        }
        
        Response::json(200, 'Rule retrieved successfully', $rule);
    }
    
    // 创建规则
    public function createRule() {
        error_log("createRule method called");
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
        
        error_log("Received data: " . json_encode($data));
        
        // 验证必填字段
        if (!isset($data['rule_name']) || !isset($data['rule_type']) || !isset($data['target_id'])) {
            Response::json(400, 'Missing required fields: rule_name, rule_type, target_id');
            return;
        }
        
        // 验证规则类型
        if (!in_array($data['rule_type'], ['department', 'position', 'employee'])) {
            Response::json(400, 'Invalid rule_type. Must be one of: department, position, employee');
            return;
        }
        
        // 检查规则名称唯一性
        $rule_model = new ScheduleRule();
        if ($rule_model->isRuleNameExists($data['rule_name'], $user['tenant_id'])) {
            Response::json(400, 'Rule name already exists');
            return;
        }
        
        // 添加租户ID
        $data['tenant_id'] = $user['tenant_id'];
        
        // 创建规则
        $rule_id = $rule_model->createRule($data);
        
        if ($rule_id) {
            Response::json(201, 'Rule created successfully', ['rule_id' => $rule_id]);
        } else {
            Response::json(500, 'Failed to create rule');
        }
    }
    
    // 更新规则
    public function updateRule($id) {
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
        
        // 验证规则存在性
        $rule_model = new ScheduleRule();
        $existing_rule = $rule_model->getRuleById($id, $user['tenant_id']);
        
        if (!$existing_rule) {
            Response::json(404, 'Rule not found');
            return;
        }
        
        // 验证规则类型
        if (isset($data['rule_type']) && !in_array($data['rule_type'], ['department', 'position', 'employee'])) {
            Response::json(400, 'Invalid rule_type. Must be one of: department, position, employee');
            return;
        }
        
        // 检查规则名称唯一性
        if (isset($data['rule_name']) && $data['rule_name'] !== $existing_rule['rule_name']) {
            if ($rule_model->isRuleNameExists($data['rule_name'], $user['tenant_id'], $id)) {
                Response::json(400, 'Rule name already exists');
                return;
            }
        }
        
        // 添加租户ID
        $data['tenant_id'] = $user['tenant_id'];
        
        // 更新规则
        $result = $rule_model->updateRule($id, $data);
        
        if ($result) {
            Response::json(200, 'Rule updated successfully');
        } else {
            Response::json(500, 'Failed to update rule');
        }
    }
    
    // 删除规则
    public function deleteRule($id) {
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
        
        $rule_model = new ScheduleRule();
        $existing_rule = $rule_model->getRuleById($id, $user['tenant_id']);
        
        if (!$existing_rule) {
            Response::json(404, 'Rule not found');
            return;
        }
        
        $result = $rule_model->deleteRule($id, $user['tenant_id']);
        
        if ($result) {
            Response::json(200, 'Rule deleted successfully');
        } else {
            Response::json(500, 'Failed to delete rule');
        }
    }
    
    // 切换规则状态
    public function toggleRuleStatus($id) {
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
        
        if (!isset($data['is_active'])) {
            Response::json(400, 'Missing required field: is_active');
            return;
        }
        
        // 验证规则存在性
        $rule_model = new ScheduleRule();
        $existing_rule = $rule_model->getRuleById($id, $user['tenant_id']);
        
        if (!$existing_rule) {
            Response::json(404, 'Rule not found');
            return;
        }
        
        $result = $rule_model->toggleRuleStatus($id, $user['tenant_id'], $data['is_active']);
        
        if ($result) {
            Response::json(200, 'Rule status updated successfully');
        } else {
            Response::json(500, 'Failed to update rule status');
        }
    }
    
    // 获取适用规则
    public function getApplicableRules() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        
        // 获取查询参数
        $rule_type = isset($_GET['rule_type']) ? $_GET['rule_type'] : null;
        $target_id = isset($_GET['target_id']) ? intval($_GET['target_id']) : null;
        
        if (!$rule_type || !$target_id) {
            Response::json(400, 'Missing required parameters: rule_type, target_id');
            return;
        }
        
        // 验证规则类型
        if (!in_array($rule_type, ['department', 'position', 'employee'])) {
            Response::json(400, 'Invalid rule_type. Must be one of: department, position, employee');
            return;
        }
        
        $rule_model = new ScheduleRule();
        $rules = $rule_model->getApplicableRules($rule_type, $target_id, $user['tenant_id']);
        
        Response::json(200, 'Applicable rules retrieved successfully', $rules);
    }
}