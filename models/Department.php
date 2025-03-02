<?php
// models/Department.php - 部门模型

require_once __DIR__ . '/../config/database.php';

class Department {
    private $conn;
    private $table_name = "departments";
    private $rules_table = "department_rules";
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    // 获取部门列表
    public function getDepartments($tenant_id) {
        $query = "SELECT d.*, u.real_name as manager_name, 
                 (SELECT COUNT(*) FROM users WHERE department_id = d.department_id) as employee_count 
                 FROM " . $this->table_name . " d
                 LEFT JOIN users u ON d.manager_id = u.user_id
                 WHERE d.tenant_id = ?
                 ORDER BY d.parent_department_id, d.department_name";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$tenant_id]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // 获取部门详情
    public function getDepartmentById($department_id, $tenant_id) {
        $query = "SELECT d.*, u.real_name as manager_name, 
                 (SELECT COUNT(*) FROM users WHERE department_id = d.department_id) as employee_count 
                 FROM " . $this->table_name . " d
                 LEFT JOIN users u ON d.manager_id = u.user_id
                 WHERE d.department_id = ? AND d.tenant_id = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$department_id, $tenant_id]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // 创建部门
    public function createDepartment($data) {
        // 验证必填字段
        if (!isset($data['tenant_id']) || !isset($data['department_name'])) {
            return false;
        }
        
        // 构建插入语句
        $query = "INSERT INTO " . $this->table_name . " 
                 (tenant_id, parent_department_id, department_name, department_code, manager_id) 
                 VALUES (?, ?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($query);
        $result = $stmt->execute([
            $data['tenant_id'],
            $data['parent_department_id'] ?? null,
            $data['department_name'],
            $data['department_code'] ?? null,
            $data['manager_id'] ?? null
        ]);
        
        if ($result) {
            return $this->conn->lastInsertId();
        }
        
        return false;
    }
    
    // 更新部门
    public function updateDepartment($department_id, $data) {
        // 验证部门存在性
        $dept = $this->getDepartmentById($department_id, $data['tenant_id']);
        if (!$dept) {
            return false;
        }
        
        // 构建更新语句
        $query = "UPDATE " . $this->table_name . " 
                 SET department_name = ?, department_code = ?, 
                 parent_department_id = ?, manager_id = ?, updated_at = NOW()
                 WHERE department_id = ? AND tenant_id = ?";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            $data['department_name'],
            $data['department_code'] ?? $dept['department_code'],
            $data['parent_department_id'] ?? $dept['parent_department_id'],
            $data['manager_id'] ?? $dept['manager_id'],
            $department_id,
            $data['tenant_id']
        ]);
    }
    
    // 删除部门
    public function deleteDepartment($department_id, $tenant_id) {
        // 检查是否有员工属于该部门
        $check_query = "SELECT COUNT(*) as count FROM users WHERE department_id = ? AND tenant_id = ?";
        $check_stmt = $this->conn->prepare($check_query);
        $check_stmt->execute([$department_id, $tenant_id]);
        $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] > 0) {
            return [
                'success' => false,
                'message' => '部门下还有员工，无法删除'
            ];
        }
        
        // 检查是否有子部门
        $check_children_query = "SELECT COUNT(*) as count FROM " . $this->table_name . " 
                                WHERE parent_department_id = ? AND tenant_id = ?";
        $check_children_stmt = $this->conn->prepare($check_children_query);
        $check_children_stmt->execute([$department_id, $tenant_id]);
        $children_result = $check_children_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($children_result['count'] > 0) {
            return [
                'success' => false,
                'message' => '部门下还有子部门，无法删除'
            ];
        }
        
        // 执行删除操作
        $query = "DELETE FROM " . $this->table_name . " WHERE department_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $result = $stmt->execute([$department_id, $tenant_id]);
        
        if ($result) {
            // 同时删除部门的考勤规则关联
            $this->deleteDepartmentRules($department_id);
            
            return [
                'success' => true,
                'message' => '部门删除成功'
            ];
        }
        
        return [
            'success' => false,
            'message' => '删除失败，请重试'
        ];
    }
    
    // 获取部门树结构
    public function getDepartmentTree($tenant_id) {
        // 先获取所有部门
        $departments = $this->getDepartments($tenant_id);
        
        // 构建部门树
        $deptMap = [];
        $tree = [];
        
        // 首先映射所有部门
        foreach ($departments as $dept) {
            $deptMap[$dept['department_id']] = [
                'id' => $dept['department_id'],
                'name' => $dept['department_name'],
                'code' => $dept['department_code'],
                'manager_id' => $dept['manager_id'],
                'manager_name' => $dept['manager_name'],
                'employee_count' => $dept['employee_count'],
                'children' => []
            ];
        }
        
        // 然后构建树结构
        foreach ($departments as $dept) {
            $department_id = $dept['department_id'];
            $parent_id = $dept['parent_department_id'];
            
            if ($parent_id === null) {
                // 顶级部门
                $tree[] = &$deptMap[$department_id];
            } else {
                // 子部门
                if (isset($deptMap[$parent_id])) {
                    $deptMap[$parent_id]['children'][] = &$deptMap[$department_id];
                }
            }
        }
        
        return $tree;
    }
    
    // 获取部门下的员工
    public function getDepartmentEmployees($department_id, $tenant_id, $include_subdepts = false) {
        if ($include_subdepts) {
            // 获取所有子部门ID
            $dept_ids = $this->getAllChildDepartmentIds($department_id, $tenant_id);
            $dept_ids[] = $department_id; // 包含当前部门
            
            $dept_id_str = implode(',', array_map('intval', $dept_ids));
            
            $query = "SELECT u.*, d.department_name, r.role_name 
                     FROM users u
                     LEFT JOIN departments d ON u.department_id = d.department_id
                     LEFT JOIN user_roles r ON u.role_id = r.role_id
                     WHERE u.department_id IN ($dept_id_str) AND u.tenant_id = ?
                     ORDER BY u.department_id, u.real_name";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$tenant_id]);
        } else {
            // 只获取当前部门员工
            $query = "SELECT u.*, d.department_name, r.role_name 
                     FROM users u
                     LEFT JOIN departments d ON u.department_id = d.department_id
                     LEFT JOIN user_roles r ON u.role_id = r.role_id
                     WHERE u.department_id = ? AND u.tenant_id = ?
                     ORDER BY u.real_name";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$department_id, $tenant_id]);
        }
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // 获取所有子部门ID
    private function getAllChildDepartmentIds($department_id, $tenant_id) {
        $result = [];
        
        $query = "SELECT department_id FROM " . $this->table_name . " 
                 WHERE parent_department_id = ? AND tenant_id = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$department_id, $tenant_id]);
        $children = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($children as $child_id) {
            $result[] = $child_id;
            $sub_children = $this->getAllChildDepartmentIds($child_id, $tenant_id);
            $result = array_merge($result, $sub_children);
        }
        
        return $result;
    }
    
    // 关联部门考勤规则
    public function setDepartmentRule($department_id, $rule_id) {
        // 先检查是否已经存在关联
        $check_query = "SELECT id FROM " . $this->rules_table . " 
                       WHERE department_id = ? AND rule_id = ?";
        
        $check_stmt = $this->conn->prepare($check_query);
        $check_stmt->execute([$department_id, $rule_id]);
        
        if ($check_stmt->rowCount() > 0) {
            // 已经存在关联，直接返回成功
            return true;
        }
        
        // 创建关联
        $query = "INSERT INTO " . $this->rules_table . " (department_id, rule_id) VALUES (?, ?)";
        $stmt = $this->conn->prepare($query);
        
        return $stmt->execute([$department_id, $rule_id]);
    }
    
    // 获取部门考勤规则
    public function getDepartmentRules($department_id) {
        $query = "SELECT r.* FROM attendance_rules r
                 JOIN " . $this->rules_table . " dr ON r.rule_id = dr.rule_id
                 WHERE dr.department_id = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$department_id]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // 删除部门考勤规则关联
    public function deleteDepartmentRule($department_id, $rule_id) {
        $query = "DELETE FROM " . $this->rules_table . " 
                 WHERE department_id = ? AND rule_id = ?";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([$department_id, $rule_id]);
    }
    
    // 删除部门的所有考勤规则关联
    private function deleteDepartmentRules($department_id) {
        $query = "DELETE FROM " . $this->rules_table . " WHERE department_id = ?";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([$department_id]);
    }
    
    // 检查部门编码是否存在
    public function departmentCodeExists($department_code, $tenant_id, $exclude_id = null) {
        $query = "SELECT department_id FROM " . $this->table_name . " 
                 WHERE department_code = ? AND tenant_id = ?";
        
        $params = [$department_code, $tenant_id];
        
        if ($exclude_id) {
            $query .= " AND department_id != ?";
            $params[] = $exclude_id;
        }
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        
        return $stmt->rowCount() > 0;
    }
}