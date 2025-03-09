<?php
// models/Tenant.php - 租户模型

require_once __DIR__ . '/../config/database.php';

class Tenant {
    private $conn;
    private $table_name = "tenants";
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    /**
     * 获取活跃租户列表
     */
    public function getActiveTenants() {
        try {
            $query = "SELECT tenant_id, tenant_name, tenant_code, logo_url FROM " . $this->table_name . " 
                   WHERE status = 'active' 
                   ORDER BY tenant_name ASC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $result ?: []; // 确保返回数组，即使没有结果
        } catch (PDOException $e) {
            error_log('Database error in getActiveTenants: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取租户列表（带分页、搜索和状态过滤）
     */
    public function getTenantsList($status = null, $search = null, $page = 1, $page_size = 10) {
        // 计算偏移量
        $offset = ($page - 1) * $page_size;
        
        // 构建查询条件
        $conditions = [];
        $params = [];
        
        if ($status) {
            $conditions[] = "status = ?";
            $params[] = $status;
        }
        
        if ($search) {
            $conditions[] = "(tenant_name LIKE ? OR tenant_code LIKE ? OR contact_name LIKE ? OR contact_email LIKE ?)";
            $search_term = "%$search%";
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
        }
        
        $where_clause = count($conditions) > 0 ? "WHERE " . implode(" AND ", $conditions) : "";
        
        // 查询租户列表
        $query = "SELECT * FROM " . $this->table_name . " 
                 $where_clause
                 ORDER BY tenant_id DESC
                 LIMIT ? OFFSET ?";
        
        $params[] = $page_size;
        $params[] = $offset;
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 查询总数
        $count_query = "SELECT COUNT(*) as total FROM " . $this->table_name . " $where_clause";
        $count_stmt = $this->conn->prepare($count_query);
        $count_stmt->execute(array_slice($params, 0, -2)); // 移除分页参数
        $count_result = $count_stmt->fetch(PDO::FETCH_ASSOC);
        $total = $count_result['total'];
        
        return [
            'records' => $records,
            'total' => $total,
            'page' => $page,
            'pageSize' => $page_size
        ];
    }
    
    /**
     * 根据租户代码获取租户信息
     */
    public function getTenantByCode($tenant_code) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE tenant_code = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$tenant_code]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * 根据租户ID获取租户信息
     */
    public function getTenantById($tenant_id) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE tenant_id = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$tenant_id]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * 创建租户
     */
    public function createTenant($data) {
        // 构建插入语句
        $fields = array_keys($data);
        $placeholders = array_fill(0, count($fields), '?');
        
        $fields_str = implode(", ", $fields);
        $placeholders_str = implode(", ", $placeholders);
        
        $query = "INSERT INTO " . $this->table_name . " ($fields_str) VALUES ($placeholders_str)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute(array_values($data));
        
        return $this->conn->lastInsertId();
    }
    
    /**
     * 更新租户
     */
    public function updateTenant($tenant_id, $data) {
        // 构建更新语句
        $sets = [];
        $params = [];
        
        foreach ($data as $key => $value) {
            $sets[] = "$key = ?";
            $params[] = $value;
        }
        
        $sets[] = "updated_at = NOW()";
        $set_clause = implode(", ", $sets);
        
        $query = "UPDATE " . $this->table_name . " SET $set_clause WHERE tenant_id = ?";
        $params[] = $tenant_id;
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute($params);
    }
    
    /**
     * 更新租户状态
     */
    public function updateTenantStatus($tenant_id, $status) {
        $query = "UPDATE " . $this->table_name . " SET status = ?, updated_at = NOW() WHERE tenant_id = ?";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([$status, $tenant_id]);
    }
    
    /**
     * 获取租户统计信息
     */
    public function getTenantStats($tenant_id) {
        // 获取员工数
        $employee_query = "SELECT COUNT(*) as employee_count FROM users WHERE tenant_id = ?";
        $employee_stmt = $this->conn->prepare($employee_query);
        $employee_stmt->execute([$tenant_id]);
        $employee_result = $employee_stmt->fetch(PDO::FETCH_ASSOC);
        
        // 获取部门数
        $dept_query = "SELECT COUNT(*) as department_count FROM departments WHERE tenant_id = ?";
        $dept_stmt = $this->conn->prepare($dept_query);
        $dept_stmt->execute([$tenant_id]);
        $dept_result = $dept_stmt->fetch(PDO::FETCH_ASSOC);
        
        // 获取今日打卡记录数
        $today = date('Y-m-d');
        $attendance_query = "SELECT COUNT(*) as today_attendance_count FROM attendance_records 
                             WHERE tenant_id = ? AND work_date = ?";
        $attendance_stmt = $this->conn->prepare($attendance_query);
        $attendance_stmt->execute([$tenant_id, $today]);
        $attendance_result = $attendance_stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'employee_count' => $employee_result['employee_count'] ?? 0,
            'department_count' => $dept_result['department_count'] ?? 0,
            'today_attendance_count' => $attendance_result['today_attendance_count'] ?? 0,
            'max_employees' => $this->getTenantById($tenant_id)['max_employees'] ?? 0
        ];
    }
}