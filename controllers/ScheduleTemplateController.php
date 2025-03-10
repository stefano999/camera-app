<?php
// controllers/ScheduleTemplateController.php - 排班模板控制器

require_once __DIR__ . '/../models/ScheduleTemplate.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/TenantMiddleware.php';

class ScheduleTemplateController {
    // 获取模板列表
    public function getTemplates() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        
        // 获取查询参数
        $department_id = isset($_GET['departmentId']) ? intval($_GET['departmentId']) : null;
        
        $template_model = new ScheduleTemplate();
        $templates = $template_model->getTemplates($user['tenant_id'], $department_id);
        
        Response::json(200, 'Templates retrieved successfully', $templates);
    }
    
    // 获取模板详情
    public function getTemplateById($id) {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        
        $template_model = new ScheduleTemplate();
        $template = $template_model->getTemplateWithDetails($id, $user['tenant_id']);
        
        if (!$template) {
            Response::json(404, 'Template not found');
            return;
        }
        
        Response::json(200, 'Template retrieved successfully', $template);
    }
    
    // 创建模板
    public function createTemplate() {
        error_log("createTemplate method called");
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        if (!$auth->hasRole(['department_admin', 'tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden');
            return;
        }
        
        $user = $auth->getUser();
        $data = json_decode(file_get_contents("php://input"), true);
        
        error_log("Received data: " . json_encode($data));
        
        // 验证必填字段
        if (!isset($data['template_name'])) {
            Response::json(400, 'Missing required field: template_name');
            return;
        }
        
        if (!isset($data['details']) || !is_array($data['details']) || empty($data['details'])) {
            Response::json(400, 'Missing or invalid template details');
            return;
        }
        
        // 验证模板明细
        foreach ($data['details'] as $detail) {
            if (!isset($detail['day_of_week']) || !in_array($detail['day_of_week'], range(1, 7))) {
                Response::json(400, 'Invalid day_of_week in template details. Must be a number from 1 to 7.');
                return;
            }
            
            if (!isset($detail['is_rest_day'])) {
                $detail['is_rest_day'] = 0;
            }
            
            if ($detail['is_rest_day'] == 0 && !isset($detail['shift_id'])) {
                Response::json(400, 'shift_id is required for working days in template details');
                return;
            }
        }
        
        // 检查模板名称唯一性
        $template_model = new ScheduleTemplate();
        if ($template_model->isTemplateNameExists($data['template_name'], $user['tenant_id'])) {
            Response::json(400, 'Template name already exists');
            return;
        }
        
        // 准备模板数据
        $template_data = [
            'tenant_id' => $user['tenant_id'],
            'template_name' => $data['template_name'],
            'department_id' => $data['department_id'] ?? null,
            'is_active' => $data['is_active'] ?? 1,
            'created_by' => $user['user_id']
        ];
        
        // 创建模板
        $template_id = $template_model->createTemplate($template_data, $data['details']);
        
        if ($template_id) {
            Response::json(201, 'Template created successfully', ['template_id' => $template_id]);
        } else {
            Response::json(500, 'Failed to create template');
        }
    }
    
    // 更新模板
    public function updateTemplate($id) {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        if (!$auth->hasRole(['department_admin', 'tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden');
            return;
        }
        
        $user = $auth->getUser();
        $data = json_decode(file_get_contents("php://input"), true);
        
        // 验证模板存在性
        $template_model = new ScheduleTemplate();
        $existing_template = $template_model->getTemplateWithDetails($id, $user['tenant_id']);
        
        if (!$existing_template) {
            Response::json(404, 'Template not found');
            return;
        }
        
        // 验证必填字段
        if (!isset($data['template_name'])) {
            Response::json(400, 'Missing required field: template_name');
            return;
        }
        
        // 检查模板名称唯一性
        if ($data['template_name'] !== $existing_template['template_name']) {
            if ($template_model->isTemplateNameExists($data['template_name'], $user['tenant_id'], $id)) {
                Response::json(400, 'Template name already exists');
                return;
            }
        }
        
        // 如果提供了模板明细，验证其格式
        if (isset($data['details']) && is_array($data['details'])) {
            foreach ($data['details'] as $detail) {
                if (!isset($detail['day_of_week']) || !in_array($detail['day_of_week'], range(1, 7))) {
                    Response::json(400, 'Invalid day_of_week in template details. Must be a number from 1 to 7.');
                    return;
                }
                
                if (!isset($detail['is_rest_day'])) {
                    $detail['is_rest_day'] = 0;
                }
                
                if ($detail['is_rest_day'] == 0 && !isset($detail['shift_id'])) {
                    Response::json(400, 'shift_id is required for working days in template details');
                    return;
                }
            }
        }
        
        // 准备模板数据
        $template_data = [
            'tenant_id' => $user['tenant_id'],
            'template_name' => $data['template_name'],
            'department_id' => $data['department_id'] ?? $existing_template['department_id'],
            'is_active' => $data['is_active'] ?? $existing_template['is_active']
        ];
        
        // 更新模板
        $result = $template_model->updateTemplate($id, $template_data, $data['details'] ?? null);
        
        if ($result) {
            Response::json(200, 'Template updated successfully');
        } else {
            Response::json(500, 'Failed to update template');
        }
    }
    
    // 删除模板
    public function deleteTemplate($id) {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        if (!$auth->hasRole(['department_admin', 'tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden');
            return;
        }
        
        $user = $auth->getUser();
        
        $template_model = new ScheduleTemplate();
        $existing_template = $template_model->getTemplateWithDetails($id, $user['tenant_id']);
        
        if (!$existing_template) {
            Response::json(404, 'Template not found');
            return;
        }
        
        $result = $template_model->deleteTemplate($id, $user['tenant_id']);
        
        if ($result) {
            Response::json(200, 'Template deleted successfully');
        } else {
            Response::json(500, 'Failed to delete template');
        }
    }
}