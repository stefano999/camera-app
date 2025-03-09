<?php
// models/Role.php - 角色模型

require_once __DIR__ . '/../config/database.php';

class Role {
    private $conn;
    private $table_name = "user_roles";
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    // 获取租户的所有角色
    public function getRoles($tenant_id) {
        $query = "SELECT * FROM " . $this->table_name . " 
                  WHERE tenant_id = ? 
                  ORDER BY role_id ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$tenant_id]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // 根据ID获取角色详情
    public function getRoleById($role_id, $tenant_id) {
        $query = "SELECT * FROM " . $this->table_name . " 
                  WHERE role_id = ? AND tenant_id = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$role_id, $tenant_id]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // 创建角色
    public function createRole($data) {
        // 必填字段验证
        if (!isset($data['tenant_id']) || !isset($data['role_name']) || !isset($data['permissions'])) {
            return false;
        }
        
        $query = "INSERT INTO " . $this->table_name . " 
                 (tenant_id, role_name, role_description, permissions, created_at, updated_at) 
                 VALUES (?, ?, ?, ?, NOW(), NOW())";
        
        $stmt = $this->conn->prepare($query);
        $result = $stmt->execute([
            $data['tenant_id'],
            $data['role_name'],
            $data['role_description'],
            $data['permissions']
        ]);
        
        if ($result) {
            return $this->conn->lastInsertId();
        }
        
        return false;
    }
    
    // 更新角色
    public function updateRole($role_id, $data) {
        $query = "UPDATE " . $this->table_name . " 
                 SET role_name = ?, role_description = ?, permissions = ?, updated_at = NOW() 
                 WHERE role_id = ?";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            $data['role_name'],
            $data['role_description'],
            $data['permissions'],
            $role_id
        ]);
    }
    
    // 删除角色
    public function deleteRole($role_id, $tenant_id) {
        $query = "DELETE FROM " . $this->table_name . " 
                 WHERE role_id = ? AND tenant_id = ?";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([$role_id, $tenant_id]);
    }
    
    // 检查角色名称是否唯一
    public function isRoleNameUnique($role_name, $tenant_id, $exclude_id = null) {
        $query = "SELECT COUNT(*) as count FROM " . $this->table_name . " 
                 WHERE role_name = ? AND tenant_id = ?";
        $params = [$role_name, $tenant_id];
        
        if ($exclude_id) {
            $query .= " AND role_id != ?";
            $params[] = $exclude_id;
        }
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['count'] == 0;
    }
    
    // 检查角色是否正在被用户使用
    public function isRoleInUse($role_id) {
        $query = "SELECT COUNT(*) as count FROM users WHERE role_id = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$role_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['count'] > 0;
    }
}