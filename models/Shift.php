<?php
// models/Shift.php - 班次模型

require_once __DIR__ . '/../config/database.php';

class Shift {
    private $conn;
    private $table_name = "shifts";
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    /**
     * 获取租户的所有班次
     * @param int $tenant_id 租户ID
     * @return array 班次列表
     */
    public function getShifts($tenant_id) {
        $query = "SELECT * FROM " . $this->table_name . " 
                  WHERE tenant_id = ? 
                  ORDER BY start_time ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$tenant_id]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * 获取班次详情
     * @param int $shift_id 班次ID
     * @param int $tenant_id 租户ID
     * @return array|false 班次详情或false
     */
    public function getShiftById($shift_id, $tenant_id) {
        $query = "SELECT * FROM " . $this->table_name . " 
                  WHERE shift_id = ? AND tenant_id = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$shift_id, $tenant_id]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * 创建班次
     * @param array $shift_data 班次数据
     * @return int|false 成功返回班次ID，失败返回false
     */
    public function createShift($shift_data) {
        $query = "INSERT INTO " . $this->table_name . " 
                  (tenant_id, shift_name, start_time, end_time, color_code, is_overnight, notes)
                  VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($query);
        $result = $stmt->execute([
            $shift_data['tenant_id'],
            $shift_data['shift_name'],
            $shift_data['start_time'],
            $shift_data['end_time'],
            $shift_data['color_code'] ?? '#3498db',
            $shift_data['is_overnight'] ?? 0,
            $shift_data['notes'] ?? null
        ]);
        
        if ($result) {
            return $this->conn->lastInsertId();
        }
        
        return false;
    }
    
    /**
     * 更新班次
     * @param int $shift_id 班次ID
     * @param array $shift_data 班次数据
     * @return bool 成功返回true，失败返回false
     */
    public function updateShift($shift_id, $shift_data) {
        $query = "UPDATE " . $this->table_name . " 
                  SET shift_name = ?, start_time = ?, end_time = ?, 
                      color_code = ?, is_overnight = ?, notes = ? 
                  WHERE shift_id = ? AND tenant_id = ?";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            $shift_data['shift_name'],
            $shift_data['start_time'],
            $shift_data['end_time'],
            $shift_data['color_code'] ?? '#3498db',
            $shift_data['is_overnight'] ?? 0,
            $shift_data['notes'] ?? null,
            $shift_id,
            $shift_data['tenant_id']
        ]);
    }
    
    /**
     * 删除班次
     * @param int $shift_id 班次ID
     * @param int $tenant_id 租户ID
     * @return array 操作结果
     */
    public function deleteShift($shift_id, $tenant_id) {
        // 检查班次是否被使用
        $check_query = "SELECT COUNT(*) as count FROM schedules WHERE shift_id = ?";
        $check_stmt = $this->conn->prepare($check_query);
        $check_stmt->execute([$shift_id]);
        $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] > 0) {
            return [
                'success' => false,
                'message' => 'Cannot delete shift that is used in schedules'
            ];
        }
        
        // 检查班次是否被模板使用
        $check_template_query = "SELECT COUNT(*) as count FROM schedule_template_details WHERE shift_id = ?";
        $check_template_stmt = $this->conn->prepare($check_template_query);
        $check_template_stmt->execute([$shift_id]);
        $template_result = $check_template_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($template_result['count'] > 0) {
            return [
                'success' => false,
                'message' => 'Cannot delete shift that is used in schedule templates'
            ];
        }
        
        // 执行删除
        $query = "DELETE FROM " . $this->table_name . " 
                  WHERE shift_id = ? AND tenant_id = ?";
        
        $stmt = $this->conn->prepare($query);
        $result = $stmt->execute([$shift_id, $tenant_id]);
        
        if ($result) {
            return [
                'success' => true,
                'message' => 'Shift deleted successfully'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Failed to delete shift'
            ];
        }
    }
    
    /**
     * 检查班次名称是否存在
     * @param string $shift_name 班次名称
     * @param int $tenant_id 租户ID
     * @param int|null $exclude_id 排除的班次ID
     * @return bool 存在返回true，不存在返回false
     */
    public function isShiftNameExists($shift_name, $tenant_id, $exclude_id = null) {
        $query = "SELECT COUNT(*) as count FROM " . $this->table_name . " 
                  WHERE shift_name = ? AND tenant_id = ?";
        $params = [$shift_name, $tenant_id];
        
        if ($exclude_id) {
            $query .= " AND shift_id != ?";
            $params[] = $exclude_id;
        }
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['count'] > 0;
    }
}