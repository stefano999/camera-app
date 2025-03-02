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
    
    // 获取活跃租户列表
    public function getActiveTenants() {
        $query = "SELECT tenant_id, tenant_name, tenant_code FROM " . $this->table_name . " WHERE status = 'active'";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // 根据租户代码获取租户信息
    public function getTenantByCode($tenant_code) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE tenant_code = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$tenant_code]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // 根据租户ID获取租户信息
    public function getTenantById($tenant_id) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE tenant_id = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$tenant_id]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // 创建租户
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
    
    // 更新租户
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
    
    // 更新租户状态
    public function updateTenantStatus($tenant_id, $status) {
        $query = "UPDATE " . $this->table_name . " SET status = ?, updated_at = NOW() WHERE tenant_id = ?";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([$status, $tenant_id]);
    }
}