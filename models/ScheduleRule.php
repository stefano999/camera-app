<?php
// models/ScheduleRule.php - 排班规则模型

require_once __DIR__ . '/../config/database.php';

class ScheduleRule {
    private $conn;
    private $table_name = "schedule_rules";
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    /**
     * 获取租户的所有排班规则
     * @param int $tenant_id 租户ID
     * @return array 规则列表
     */
    public function getRules($tenant_id) {
        $query = "SELECT * FROM " . $this->table_name . " 
                  WHERE tenant_id = ? 
                  ORDER BY priority DESC, rule_name ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$tenant_id]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * 获取特定规则详情
     * @param int $rule_id 规则ID
     * @param int $tenant_id 租户ID
     * @return array|false 规则详情或false
     */
    public function getRuleById($rule_id, $tenant_id) {
        $query = "SELECT * FROM " . $this->table_name . " 
                  WHERE rule_id = ? AND tenant_id = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$rule_id, $tenant_id]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * 获取适用于特定目标的规则
     * @param string $rule_type 规则类型(department, position, employee)
     * @param int $target_id 目标ID
     * @param int $tenant_id 租户ID
     * @return array 规则列表
     */
    public function getApplicableRules($rule_type, $target_id, $tenant_id) {
        $query = "SELECT * FROM " . $this->table_name . " 
                  WHERE rule_type = ? AND target_id = ? AND tenant_id = ? AND is_active = 1
                  ORDER BY priority DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$rule_type, $target_id, $tenant_id]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * 创建排班规则
     * @param array $rule_data 规则数据
     * @return int|false 成功返回规则ID，失败返回false
     */
    public function createRule($rule_data) {
        $query = "INSERT INTO " . $this->table_name . " 
                  (tenant_id, rule_name, rule_type, target_id, min_staff_count, 
                   max_consecutive_workdays, min_rest_days, is_active, priority)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($query);
        $result = $stmt->execute([
            $rule_data['tenant_id'],
            $rule_data['rule_name'],
            $rule_data['rule_type'],
            $rule_data['target_id'],
            $rule_data['min_staff_count'] ?? 1,
            $rule_data['max_consecutive_workdays'] ?? 6,
            $rule_data['min_rest_days'] ?? 1,
            $rule_data['is_active'] ?? 1,
            $rule_data['priority'] ?? 0
        ]);
        
        if ($result) {
            return $this->conn->lastInsertId();
        }
        
        return false;
    }
    
    /**
     * 更新排班规则
     * @param int $rule_id 规则ID
     * @param array $rule_data 规则数据
     * @return bool 成功返回true，失败返回false
     */
    public function updateRule($rule_id, $rule_data) {
        $query = "UPDATE " . $this->table_name . " 
                  SET rule_name = ?, rule_type = ?, target_id = ?, min_staff_count = ?, 
                      max_consecutive_workdays = ?, min_rest_days = ?, is_active = ?, priority = ? 
                  WHERE rule_id = ? AND tenant_id = ?";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            $rule_data['rule_name'],
            $rule_data['rule_type'],
            $rule_data['target_id'],
            $rule_data['min_staff_count'] ?? 1,
            $rule_data['max_consecutive_workdays'] ?? 6,
            $rule_data['min_rest_days'] ?? 1,
            $rule_data['is_active'] ?? 1,
            $rule_data['priority'] ?? 0,
            $rule_id,
            $rule_data['tenant_id']
        ]);
    }
    
    /**
     * 删除排班规则
     * @param int $rule_id 规则ID
     * @param int $tenant_id 租户ID
     * @return bool 成功返回true，失败返回false
     */
    public function deleteRule($rule_id, $tenant_id) {
        $query = "DELETE FROM " . $this->table_name . " 
                  WHERE rule_id = ? AND tenant_id = ?";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([$rule_id, $tenant_id]);
    }
    
    /**
     * 启用或禁用规则
     * @param int $rule_id 规则ID
     * @param int $tenant_id 租户ID
     * @param bool $is_active 是否启用
     * @return bool 成功返回true，失败返回false
     */
    public function toggleRuleStatus($rule_id, $tenant_id, $is_active) {
        $query = "UPDATE " . $this->table_name . " 
                  SET is_active = ? 
                  WHERE rule_id = ? AND tenant_id = ?";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([$is_active ? 1 : 0, $rule_id, $tenant_id]);
    }
    
    /**
     * 检查规则名称是否存在
     * @param string $rule_name 规则名称
     * @param int $tenant_id 租户ID
     * @param int|null $exclude_id 排除的规则ID
     * @return bool 存在返回true，不存在返回false
     */
    public function isRuleNameExists($rule_name, $tenant_id, $exclude_id = null) {
        $query = "SELECT COUNT(*) as count FROM " . $this->table_name . " 
                  WHERE rule_name = ? AND tenant_id = ?";
        $params = [$rule_name, $tenant_id];
        
        if ($exclude_id) {
            $query .= " AND rule_id != ?";
            $params[] = $exclude_id;
        }
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['count'] > 0;
    }
}