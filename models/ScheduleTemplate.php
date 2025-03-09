<?php
// models/ScheduleTemplate.php - 排班模板模型

require_once __DIR__ . '/../config/database.php';

class ScheduleTemplate {
    private $conn;
    private $table_name = "schedule_templates";
    private $detail_table = "schedule_template_details";
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    /**
     * 获取租户的所有排班模板
     * @param int $tenant_id 租户ID
     * @param int|null $department_id 部门ID(可选)
     * @return array 模板列表
     */
    public function getTemplates($tenant_id, $department_id = null) {
        $query = "SELECT t.*, d.department_name, u.real_name as creator_name 
                  FROM " . $this->table_name . " t
                  LEFT JOIN departments d ON t.department_id = d.department_id
                  LEFT JOIN users u ON t.created_by = u.user_id
                  WHERE t.tenant_id = ?";
        $params = [$tenant_id];
        
        if ($department_id) {
            $query .= " AND (t.department_id = ? OR t.department_id IS NULL)";
            $params[] = $department_id;
        }
        
        $query .= " ORDER BY t.is_active DESC, t.template_name ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * 获取模板详情及其明细
     * @param int $template_id 模板ID
     * @param int $tenant_id 租户ID
     * @return array|false 模板详情或false
     */
    public function getTemplateWithDetails($template_id, $tenant_id) {
        // 获取模板基本信息
        $query = "SELECT t.*, d.department_name, u.real_name as creator_name 
                  FROM " . $this->table_name . " t
                  LEFT JOIN departments d ON t.department_id = d.department_id
                  LEFT JOIN users u ON t.created_by = u.user_id
                  WHERE t.template_id = ? AND t.tenant_id = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$template_id, $tenant_id]);
        
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$template) {
            return false;
        }
        
        // 获取模板明细
        $detail_query = "SELECT td.*, s.shift_name, s.start_time, s.end_time, s.color_code 
                        FROM " . $this->detail_table . " td
                        LEFT JOIN shifts s ON td.shift_id = s.shift_id
                        WHERE td.template_id = ?
                        ORDER BY td.day_of_week";
        
        $detail_stmt = $this->conn->prepare($detail_query);
        $detail_stmt->execute([$template_id]);
        
        $template['details'] = $detail_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $template;
    }
    
    /**
     * 创建排班模板
     * @param array $template_data 模板数据
     * @param array $details 模板明细数据
     * @return int|false 成功返回模板ID，失败返回false
     */
    public function createTemplate($template_data, $details) {
        $this->conn->beginTransaction();
        
        try {
            // 创建模板主表记录
            $query = "INSERT INTO " . $this->table_name . " 
                     (tenant_id, template_name, department_id, is_active, created_by)
                     VALUES (?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($query);
            $result = $stmt->execute([
                $template_data['tenant_id'],
                $template_data['template_name'],
                $template_data['department_id'] ?? null,
                $template_data['is_active'] ?? 1,
                $template_data['created_by']
            ]);
            
            if (!$result) {
                throw new Exception("Failed to create template");
            }
            
            $template_id = $this->conn->lastInsertId();
            
            // 创建模板明细记录
            if (!empty($details)) {
                $detail_query = "INSERT INTO " . $this->detail_table . " 
                               (template_id, day_of_week, shift_id, is_rest_day)
                               VALUES (?, ?, ?, ?)";
                
                $detail_stmt = $this->conn->prepare($detail_query);
                
                foreach ($details as $detail) {
                    $detail_result = $detail_stmt->execute([
                        $template_id,
                        $detail['day_of_week'],
                        $detail['shift_id'] ?? null,
                        $detail['is_rest_day'] ?? 0
                    ]);
                    
                    if (!$detail_result) {
                        throw new Exception("Failed to create template detail");
                    }
                }
            }
            
            $this->conn->commit();
            return $template_id;
            
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("Error in createTemplate: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 更新排班模板
     * @param int $template_id 模板ID
     * @param array $template_data 模板数据
     * @param array|null $details 模板明细数据(可选)
     * @return bool 成功返回true，失败返回false
     */
    public function updateTemplate($template_id, $template_data, $details = null) {
        $this->conn->beginTransaction();
        
        try {
            // 更新模板主表记录
            $query = "UPDATE " . $this->table_name . " 
                     SET template_name = ?, department_id = ?, is_active = ? 
                     WHERE template_id = ? AND tenant_id = ?";
            
            $stmt = $this->conn->prepare($query);
            $result = $stmt->execute([
                $template_data['template_name'],
                $template_data['department_id'] ?? null,
                $template_data['is_active'] ?? 1,
                $template_id,
                $template_data['tenant_id']
            ]);
            
            if (!$result) {
                throw new Exception("Failed to update template");
            }
            
            // 如果提供了明细数据，则更新明细
            if ($details !== null) {
                // 删除现有明细
                $delete_query = "DELETE FROM " . $this->detail_table . " WHERE template_id = ?";
                $delete_stmt = $this->conn->prepare($delete_query);
                $delete_result = $delete_stmt->execute([$template_id]);
                
                if (!$delete_result) {
                    throw new Exception("Failed to delete existing template details");
                }
                
                // 插入新明细
                if (!empty($details)) {
                    $detail_query = "INSERT INTO " . $this->detail_table . " 
                                   (template_id, day_of_week, shift_id, is_rest_day)
                                   VALUES (?, ?, ?, ?)";
                    
                    $detail_stmt = $this->conn->prepare($detail_query);
                    
                    foreach ($details as $detail) {
                        $detail_result = $detail_stmt->execute([
                            $template_id,
                            $detail['day_of_week'],
                            $detail['shift_id'] ?? null,
                            $detail['is_rest_day'] ?? 0
                        ]);
                        
                        if (!$detail_result) {
                            throw new Exception("Failed to create template detail");
                        }
                    }
                }
            }
            
            $this->conn->commit();
            return true;
            
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("Error in updateTemplate: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 删除排班模板
     * @param int $template_id 模板ID
     * @param int $tenant_id 租户ID
     * @return bool 成功返回true，失败返回false
     */
    public function deleteTemplate($template_id, $tenant_id) {
        $this->conn->beginTransaction();
        
        try {
            // 删除模板明细
            $detail_query = "DELETE FROM " . $this->detail_table . " WHERE template_id = ?";
            $detail_stmt = $this->conn->prepare($detail_query);
            $detail_result = $detail_stmt->execute([$template_id]);
            
            if (!$detail_result) {
                throw new Exception("Failed to delete template details");
            }
            
            // 删除模板主表记录
            $query = "DELETE FROM " . $this->table_name . " 
                     WHERE template_id = ? AND tenant_id = ?";
            
            $stmt = $this->conn->prepare($query);
            $result = $stmt->execute([$template_id, $tenant_id]);
            
            if (!$result) {
                throw new Exception("Failed to delete template");
            }
            
            $this->conn->commit();
            return true;
            
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("Error in deleteTemplate: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 检查模板名称是否存在
     * @param string $template_name 模板名称
     * @param int $tenant_id 租户ID
     * @param int|null $exclude_id 排除的模板ID
     * @return bool 存在返回true，不存在返回false
     */
    public function isTemplateNameExists($template_name, $tenant_id, $exclude_id = null) {
        $query = "SELECT COUNT(*) as count FROM " . $this->table_name . " 
                  WHERE template_name = ? AND tenant_id = ?";
        $params = [$template_name, $tenant_id];
        
        if ($exclude_id) {
            $query .= " AND template_id != ?";
            $params[] = $exclude_id;
        }
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['count'] > 0;
    }
}