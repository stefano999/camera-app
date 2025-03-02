<?php
// models/User.php - 用户模型

require_once __DIR__ . '/../config/database.php';

class User {
    private $conn;
    private $table_name = "users";
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    // 获取系统管理员
    public function getSysAdmin($username) {
        $query = "SELECT u.user_id, u.username, u.password, u.tenant_id, u.real_name, u.role_id, r.permissions 
                  FROM " . $this->table_name . " u
                  JOIN user_roles r ON u.role_id = r.role_id
                  WHERE u.username = ? AND u.tenant_id = 1 AND r.permissions = 'all'";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$username]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // 根据用户名和租户ID获取用户
    public function getUserByUsername($username, $tenant_id) {
        $query = "SELECT u.user_id, u.username, u.password, u.tenant_id, u.real_name, u.role_id, u.status
                  FROM " . $this->table_name . " u
                  WHERE u.username = ? AND u.tenant_id = ? AND u.status = 'active'";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$username, $tenant_id]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // 根据用户ID获取用户
    public function getUserById($user_id) {
        $query = "SELECT u.*, r.role_name, r.permissions, d.department_name
                  FROM " . $this->table_name . " u
                  LEFT JOIN departments d ON u.department_id = d.department_id
                  LEFT JOIN user_roles r ON u.role_id = r.role_id
                  WHERE u.user_id = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$user_id]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // 获取用户详情
    public function getUserDetail($user_id, $tenant_id) {
        $query = "SELECT u.*, r.role_name, r.permissions, d.department_name
                  FROM " . $this->table_name . " u
                  LEFT JOIN departments d ON u.department_id = d.department_id
                  LEFT JOIN user_roles r ON u.role_id = r.role_id
                  WHERE u.user_id = ? AND u.tenant_id = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$user_id, $tenant_id]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // 获取用户列表
    public function getUsers($tenant_id, $page = 1, $page_size = 10, $department_id = null, $status = null, $search = null) {
        // 计算偏移量
        $offset = ($page - 1) * $page_size;
        
        // 构建查询条件
        $conditions = ["u.tenant_id = ?"];
        $params = [$tenant_id];
        
        if ($department_id) {
            $conditions[] = "u.department_id = ?";
            $params[] = $department_id;
        }
        
        if ($status) {
            $conditions[] = "u.status = ?";
            $params[] = $status;
        }
        
        if ($search) {
            $conditions[] = "(u.username LIKE ? OR u.real_name LIKE ? OR u.employee_id LIKE ?)";
            $search_term = "%$search%";
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
        }
        
        $where_clause = implode(" AND ", $conditions);
        
        // 查询用户列表
        $query = "SELECT u.user_id, u.tenant_id, u.department_id, u.employee_id, u.username, 
                        u.real_name, u.email, u.phone, u.avatar_url, u.role_id, u.position, 
                        u.hire_date, u.status, d.department_name, r.role_name
                 FROM " . $this->table_name . " u
                 LEFT JOIN departments d ON u.department_id = d.department_id
                 LEFT JOIN user_roles r ON u.role_id = r.role_id
                 WHERE $where_clause
                 ORDER BY u.user_id DESC
                 LIMIT ? OFFSET ?";
        
        $params[] = $page_size;
        $params[] = $offset;
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 查询总数
        $count_query = "SELECT COUNT(*) as total FROM " . $this->table_name . " u WHERE $where_clause";
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
    
    // 更新最后登录时间
    public function updateLastLogin($user_id) {
        $query = "UPDATE " . $this->table_name . " SET last_login = NOW() WHERE user_id = ?";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([$user_id]);
    }
    
    // 更新用户资料
    public function updateProfile($user_id, $data) {
        // 构建更新语句
        $sets = [];
        $params = [];
        
        foreach ($data as $key => $value) {
            $sets[] = "$key = ?";
            $params[] = $value;
        }
        
        $sets[] = "updated_at = NOW()";
        $set_clause = implode(", ", $sets);
        
        $query = "UPDATE " . $this->table_name . " SET $set_clause WHERE user_id = ?";
        $params[] = $user_id;
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute($params);
    }
    
    // 更新密码
    public function updatePassword($user_id, $hashed_password) {
        $query = "UPDATE " . $this->table_name . " SET password = ?, updated_at = NOW() WHERE user_id = ?";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([$hashed_password, $user_id]);
    }
    
    // 检查用户名是否存在
    public function usernameExists($username, $tenant_id, $exclude_id = null) {
        $query = "SELECT user_id FROM " . $this->table_name . " WHERE username = ? AND tenant_id = ?";
        $params = [$username, $tenant_id];
        
        if ($exclude_id) {
            $query .= " AND user_id != ?";
            $params[] = $exclude_id;
        }
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        
        return $stmt->rowCount() > 0;
    }
    
    // 检查员工ID是否存在
    public function employeeIdExists($employee_id, $tenant_id, $exclude_id = null) {
        $query = "SELECT user_id FROM " . $this->table_name . " WHERE employee_id = ? AND tenant_id = ?";
        $params = [$employee_id, $tenant_id];
        
        if ($exclude_id) {
            $query .= " AND user_id != ?";
            $params[] = $exclude_id;
        }
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        
        return $stmt->rowCount() > 0;
    }
    
    // 添加用户
    public function addUser($data) {
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
    
    // 更新用户
    public function updateUser($user_id, $data) {
        // 构建更新语句
        $sets = [];
        $params = [];
        
        foreach ($data as $key => $value) {
            $sets[] = "$key = ?";
            $params[] = $value;
        }
        
        $sets[] = "updated_at = NOW()";
        $set_clause = implode(", ", $sets);
        
        $query = "UPDATE " . $this->table_name . " SET $set_clause WHERE user_id = ?";
        $params[] = $user_id;
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute($params);
    }
    
    // 删除用户
    public function deleteUser($user_id) {
        $query = "DELETE FROM " . $this->table_name . " WHERE user_id = ?";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([$user_id]);
    }
}