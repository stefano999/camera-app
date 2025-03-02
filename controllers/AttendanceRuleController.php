<?php
// controllers/AttendanceRuleController.php - 考勤规则控制器

require_once __DIR__ . '/../models/AttendanceRule.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/TenantMiddleware.php';

class AttendanceRuleController {
    // 获取所有考勤规则
    public function getRules() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        
        $rule_model = new AttendanceRule();
        $rules = $rule_model->getRules($user['tenant_id']);
        
        Response::json(200, 'Rules retrieved successfully', $rules);
    }
    
    // 获取规则详情
    public function getRuleById($rule_id) {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        
        $rule_model = new AttendanceRule();
        $rule = $rule_model->getRuleById($rule_id);
        
        if (!$rule || $rule['tenant_id'] != $user['tenant_id']) {
            Response::json(404, 'Rule not found');
            return;
        }
        
        Response::json(200, 'Rule retrieved successfully', $rule);
    }
    
    // 创建考勤规则
    public function createRule() {
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
        if (!isset($data['rule_name']) || !isset($data['work_start_time']) || !isset($data['work_end_time'])) {
            Response::json(400, 'Missing required fields: rule_name, work_start_time, work_end_time');
            return;
        }
        
        // 验证规则名称唯一性
        $rule_model = new AttendanceRule();
        if (!$rule_model->isRuleNameUnique($data['rule_name'], $user['tenant_id'])) {
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
    
    // 更新考勤规则
    public function updateRule($rule_id) {
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
        
        // 验证规则存在性及所属租户
        $rule_model = new AttendanceRule();
        $rule = $rule_model->getRuleById($rule_id);
        
        if (!$rule || $rule['tenant_id'] != $user['tenant_id']) {
            Response::json(404, 'Rule not found');
            return;
        }
        
        // 验证规则名称唯一性
        if (isset($data['rule_name']) && $data['rule_name'] !== $rule['rule_name']) {
            if (!$rule_model->isRuleNameUnique($data['rule_name'], $user['tenant_id'], $rule_id)) {
                Response::json(400, 'Rule name already exists');
                return;
            }
        }
        
        // 更新规则
        $result = $rule_model->updateRule($rule_id, $data);
        
        if ($result) {
            Response::json(200, 'Rule updated successfully');
        } else {
            Response::json(500, 'Failed to update rule');
        }
    }
    
    // 删除考勤规则
    public function deleteRule($rule_id) {
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
        
        // 验证规则存在性及所属租户
        $rule_model = new AttendanceRule();
        $rule = $rule_model->getRuleById($rule_id);
        
        if (!$rule || $rule['tenant_id'] != $user['tenant_id']) {
            Response::json(404, 'Rule not found');
            return;
        }
        
        // 删除规则
        $result = $rule_model->deleteRule($rule_id, $user['tenant_id']);
        
        if ($result['success']) {
            Response::json(200, $result['message']);
        } else {
            Response::json(400, $result['message']);
        }
    }
    
    // 获取默认考勤规则
    public function getDefaultRule() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        
        $rule_model = new AttendanceRule();
        $rule = $rule_model->getDefaultRule($user['tenant_id']);
        
        if (!$rule) {
            Response::json(404, 'No default rule found');
            return;
        }
        
        Response::json(200, 'Default rule retrieved successfully', $rule);
    }
}