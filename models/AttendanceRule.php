<?php
// models/AttendanceRule.php - 打卡规则模型

require_once __DIR__ . '/../config/database.php';

class AttendanceRule {
    private $conn;
    private $table_name = "attendance_rules";
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    // 获取租户所有考勤规则
    public function getRules($tenant_id) {
        $query = "SELECT * FROM " . $this->table_name . " 
                  WHERE tenant_id = ? 
                  ORDER BY is_default DESC, rule_name ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$tenant_id]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // 获取规则详情
    public function getRuleById($rule_id) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE rule_id = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$rule_id]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // 创建考勤规则
    public function createRule($data) {
        // 验证必填字段
        if (!isset($data['tenant_id']) || !isset($data['rule_name']) || 
            !isset($data['work_start_time']) || !isset($data['work_end_time'])) {
            return false;
        }
        
        // 如果设置为默认规则，先将其他规则的默认标志取消
        if (isset($data['is_default']) && $data['is_default']) {
            $this->clearDefaultRule($data['tenant_id']);
        }
        
        // 构建插入语句
        $fields = [];
        $placeholders = [];
        $values = [];
        
        foreach ($data as $key => $value) {
            if ($value !== null) {
                $fields[] = $key;
                $placeholders[] = '?';
                $values[] = $value;
            }
        }
        
        $query = "INSERT INTO " . $this->table_name . " 
                 (" . implode(', ', $fields) . ") 
                 VALUES (" . implode(', ', $placeholders) . ")";
        
        $stmt = $this->conn->prepare($query);
        $result = $stmt->execute($values);
        
        if ($result) {
            return $this->conn->lastInsertId();
        }
        
        return false;
    }
    
    // 更新考勤规则
    public function updateRule($rule_id, $data) {
        // 检查规则是否存在
        $rule = $this->getRuleById($rule_id);
        if (!$rule) {
            return false;
        }
        
        // 如果设置为默认规则，先将其他规则的默认标志取消
        if (isset($data['is_default']) && $data['is_default']) {
            $this->clearDefaultRule($rule['tenant_id']);
        }
        
        // 构建更新语句
        $sets = [];
        $values = [];
        
        foreach ($data as $key => $value) {
            if ($value !== null) {
                $sets[] = "$key = ?";
                $values[] = $value;
            }
        }
        
        $sets[] = "updated_at = NOW()";
        
        $query = "UPDATE " . $this->table_name . " 
                 SET " . implode(', ', $sets) . " 
                 WHERE rule_id = ?";
        
        $values[] = $rule_id;
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute($values);
    }
    
    // 删除考勤规则
    public function deleteRule($rule_id, $tenant_id) {
        // 检查规则是否正在被使用
        $check_query = "SELECT COUNT(*) as count FROM department_rules WHERE rule_id = ?";
        $check_stmt = $this->conn->prepare($check_query);
        $check_stmt->execute([$rule_id]);
        $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] > 0) {
            return [
                'success' => false,
                'message' => '规则正在被部门使用，无法删除'
            ];
        }
        
        // 执行删除操作
        $query = "DELETE FROM " . $this->table_name . " WHERE rule_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $result = $stmt->execute([$rule_id, $tenant_id]);
        
        if ($result) {
            return [
                'success' => true,
                'message' => '规则删除成功'
            ];
        }
        
        return [
            'success' => false,
            'message' => '删除失败，请重试'
        ];
    }
    
    // 获取默认规则
    public function getDefaultRule($tenant_id) {
        $query = "SELECT * FROM " . $this->table_name . " 
                  WHERE tenant_id = ? AND is_default = 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$tenant_id]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // 清除租户下的默认规则标记
    private function clearDefaultRule($tenant_id) {
        $query = "UPDATE " . $this->table_name . " 
                  SET is_default = 0 
                  WHERE tenant_id = ? AND is_default = 1";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([$tenant_id]);
    }
    
    // 获取用户适用的考勤规则
    public function getUserRule($user_id, $tenant_id) {
        // 先查找用户部门的规则
        $query = "SELECT r.* FROM " . $this->table_name . " r
                  JOIN department_rules dr ON r.rule_id = dr.rule_id
                  JOIN users u ON dr.department_id = u.department_id
                  WHERE u.user_id = ? AND u.tenant_id = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$user_id, $tenant_id]);
        $rule = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($rule) {
            return $rule;
        }
        
        // 如果没有部门规则，则使用默认规则
        return $this->getDefaultRule($tenant_id);
    }
    
    // 验证考勤规则名称是否唯一
    public function isRuleNameUnique($rule_name, $tenant_id, $exclude_id = null) {
        $query = "SELECT rule_id FROM " . $this->table_name . " 
                  WHERE rule_name = ? AND tenant_id = ?";
        
        $params = [$rule_name, $tenant_id];
        
        if ($exclude_id) {
            $query .= " AND rule_id != ?";
            $params[] = $exclude_id;
        }
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        
        return $stmt->rowCount() === 0;
    }
    
    // 获取当前适用的打卡规则
    public function getApplicableRule($user_id, $tenant_id, $date = null) {
        $date = $date ?? date('Y-m-d');
        
        // 从用户部门或默认规则获取基本规则
        $base_rule = $this->getUserRule($user_id, $tenant_id);
        
        if (!$base_rule) {
            return null;
        }
        
        // TODO: 这里可以扩展处理特殊日期规则、假期规则等
        
        return $base_rule;
    }
    
    // 判断员工打卡状态
    public function evaluateAttendanceStatus($check_in_time, $check_out_time, $rule) {
        if (!$rule) {
            return 'normal'; // 没有规则，默认为正常
        }
        
        if (!$check_in_time && !$check_out_time) {
            return 'absent'; // 没有签到签退，视为缺勤
        }
        
        $status = 'normal';
        
        // 解析工作时间
        $work_date = date('Y-m-d', strtotime($check_in_time ?? $check_out_time));
        $work_start = strtotime($work_date . ' ' . $rule['work_start_time']);
        $work_end = strtotime($work_date . ' ' . $rule['work_end_time']);
        
        // 检查是否迟到
        if ($check_in_time) {
            $check_in = strtotime($check_in_time);
            $late_threshold = $work_start + ($rule['late_threshold_minutes'] * 60);
            
            if ($check_in > $late_threshold) {
                $status = 'late';
            }
        }
        
        // 检查是否早退
        if ($check_out_time) {
            $check_out = strtotime($check_out_time);
            $early_leave_threshold = $work_end - ($rule['early_leave_threshold_minutes'] * 60);
            
            if ($check_out < $early_leave_threshold) {
                // 如果已经标记为迟到，则保持迟到状态，否则标记为早退
                if ($status != 'late') {
                    $status = 'early_leave';
                }
            }
        }
        
        return $status;
    }
}