<?php
// controllers/ShiftController.php - 班次控制器

require_once __DIR__ . '/../models/Shift.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/TenantMiddleware.php';

class ShiftController {
    // 获取所有班次
    public function getShifts() {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        
        $shift_model = new Shift();
        $shifts = $shift_model->getShifts($user['tenant_id']);
        
        Response::json(200, 'Shifts retrieved successfully', $shifts);
    }
    
    // 获取班次详情
    public function getShiftById($id) {
        $auth = new AuthMiddleware();
        
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return;
        }
        
        $user = $auth->getUser();
        
        $shift_model = new Shift();
        $shift = $shift_model->getShiftById($id, $user['tenant_id']);
        
        if (!$shift) {
            Response::json(404, 'Shift not found');
            return;
        }
        
        Response::json(200, 'Shift retrieved successfully', $shift);
    }
    
    // 创建班次
    public function createShift() {
        error_log("createShift method called");
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
        if (!isset($data['shift_name']) || !isset($data['start_time']) || !isset($data['end_time'])) {
            Response::json(400, 'Missing required fields: shift_name, start_time, end_time');
            return;
        }
        
        // 检查班次名称唯一性
        $shift_model = new Shift();
        if ($shift_model->isShiftNameExists($data['shift_name'], $user['tenant_id'])) {
            Response::json(400, 'Shift name already exists');
            return;
        }
        
        // 添加租户ID
        $data['tenant_id'] = $user['tenant_id'];
        
        // 创建班次
        $shift_id = $shift_model->createShift($data);
        
        if ($shift_id) {
            Response::json(201, 'Shift created successfully', ['shift_id' => $shift_id]);
        } else {
            Response::json(500, 'Failed to create shift');
        }
    }
    
    // 更新班次
    public function updateShift($id) {
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
        
        // 验证班次存在性
        $shift_model = new Shift();
        $existing_shift = $shift_model->getShiftById($id, $user['tenant_id']);
        
        if (!$existing_shift) {
            Response::json(404, 'Shift not found');
            return;
        }
        
        // 检查班次名称唯一性
        if (isset($data['shift_name']) && $data['shift_name'] !== $existing_shift['shift_name']) {
            if ($shift_model->isShiftNameExists($data['shift_name'], $user['tenant_id'], $id)) {
                Response::json(400, 'Shift name already exists');
                return;
            }
        }
        
        // 添加租户ID
        $data['tenant_id'] = $user['tenant_id'];
        
        // 更新班次
        $result = $shift_model->updateShift($id, $data);
        
        if ($result) {
            Response::json(200, 'Shift updated successfully');
        } else {
            Response::json(500, 'Failed to update shift');
        }
    }
    
    // 删除班次
    public function deleteShift($id) {
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
        
        $shift_model = new Shift();
        $result = $shift_model->deleteShift($id, $user['tenant_id']);
        
        if ($result['success']) {
            Response::json(200, $result['message']);
        } else {
            Response::json(400, $result['message']);
        }
    }
}