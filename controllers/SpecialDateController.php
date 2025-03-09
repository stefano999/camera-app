<?php
// controllers/SpecialDateController.php - 特殊日期控制器

require_once __DIR__ . '/../models/SpecialDate.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/TenantMiddleware.php';

class SpecialDateController {
    // 获取特殊日期列表
    public function getSpecialDates() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        
        // 获取查询参数
        $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
        $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
        
        $date_model = new SpecialDate();
        $dates = $date_model->getSpecialDates($user['tenant_id'], $start_date, $end_date);
        
        Response::json(200, 'Special dates retrieved successfully', $dates);
    }
    
    // 创建特殊日期
    public function createSpecialDate() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden: Only administrators can create special dates');
            return;
        }
        
        $user = $auth->getUser();
        $data = json_decode(file_get_contents("php://input"), true);
        
        // 验证必填字段
        if (!isset($data['date_value']) || !isset($data['date_type']) || !isset($data['date_name'])) {
            Response::json(400, 'Missing required fields: date_value, date_type, date_name');
            return;
        }
        
        // 验证日期类型
        if (!in_array($data['date_type'], ['holiday', 'special_workday'])) {
            Response::json(400, 'Invalid date_type. Must be one of: holiday, special_workday');
            return;
        }
        
        // 验证日期格式
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['date_value'])) {
            Response::json(400, 'Invalid date format. Must be YYYY-MM-DD');
            return;
        }
        
        // 添加租户ID
        $data['tenant_id'] = $user['tenant_id'];
        
        // 创建特殊日期
        $date_model = new SpecialDate();
        $date_id = $date_model->createSpecialDate($data);
        
        if ($date_id) {
            Response::json(201, 'Special date created successfully', ['date_id' => $date_id]);
        } else {
            Response::json(500, 'Failed to create special date');
        }
    }
    
    // 批量创建特殊日期
    public function batchCreateSpecialDates() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden: Only administrators can create special dates');
            return;
        }
        
        $user = $auth->getUser();
        $data = json_decode(file_get_contents("php://input"), true);
        
        // 验证输入
        if (!isset($data['dates']) || !is_array($data['dates']) || empty($data['dates'])) {
            Response::json(400, 'Invalid request data. Dates array is required.');
            return;
        }
        
        // 验证每个日期
        foreach ($data['dates'] as $date) {
            if (!isset($date['date_value']) || !isset($date['date_type']) || !isset($date['date_name'])) {
                Response::json(400, 'Missing required fields in dates: date_value, date_type, date_name');
                return;
            }
            
            // 验证日期类型
            if (!in_array($date['date_type'], ['holiday', 'special_workday'])) {
                Response::json(400, 'Invalid date_type. Must be one of: holiday, special_workday');
                return;
            }
            
            // 验证日期格式
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date['date_value'])) {
                Response::json(400, 'Invalid date format. Must be YYYY-MM-DD');
                return;
            }
        }
        
        // 批量创建特殊日期
        $date_model = new SpecialDate();
        $result = $date_model->batchCreateSpecialDates($data['dates'], $user['tenant_id']);
        
        if ($result['success']) {
            Response::json(200, 'Special dates created successfully', [
                'success_count' => $result['success_count'],
                'fail_count' => $result['fail_count']
            ]);
        } else {
            Response::json(500, $result['message']);
        }
    }
    
    // 更新特殊日期
    public function updateSpecialDate($id) {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden: Only administrators can update special dates');
            return;
        }
        
        $user = $auth->getUser();
        $data = json_decode(file_get_contents("php://input"), true);
        
        // 验证日期是否存在
        $date_model = new SpecialDate();
        $existing_date = $date_model->getSpecialDateByValue($data['date_value'], $user['tenant_id']);
        
        if ($existing_date && $existing_date['date_id'] != $id) {
            Response::json(400, 'Another special date already exists for this date');
            return;
        }
        
        // 验证必填字段
        if (!isset($data['date_value']) || !isset($data['date_type']) || !isset($data['date_name'])) {
            Response::json(400, 'Missing required fields: date_value, date_type, date_name');
            return;
        }
        
        // 验证日期类型
        if (!in_array($data['date_type'], ['holiday', 'special_workday'])) {
            Response::json(400, 'Invalid date_type. Must be one of: holiday, special_workday');
            return;
        }
        
        // 验证日期格式
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['date_value'])) {
            Response::json(400, 'Invalid date format. Must be YYYY-MM-DD');
            return;
        }
        
        // 添加租户ID
        $data['tenant_id'] = $user['tenant_id'];
        
        // 更新特殊日期
        $result = $date_model->updateSpecialDate($id, $data);
        
        if ($result) {
            Response::json(200, 'Special date updated successfully');
        } else {
            Response::json(500, 'Failed to update special date');
        }
    }
    
    // 删除特殊日期
    public function deleteSpecialDate($id) {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        if (!$auth->hasRole(['tenant_admin', 'system_admin'])) {
            Response::json(403, 'Forbidden: Only administrators can delete special dates');
            return;
        }
        
        $user = $auth->getUser();
        
        $date_model = new SpecialDate();
        $result = $date_model->deleteSpecialDate($id, $user['tenant_id']);
        
        if ($result) {
            Response::json(200, 'Special date deleted successfully');
        } else {
            Response::json(500, 'Failed to delete special date');
        }
    }
}