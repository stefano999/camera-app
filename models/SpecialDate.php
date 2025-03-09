<?php
// models/SpecialDate.php - 特殊日期模型

require_once __DIR__ . '/../config/database.php';

class SpecialDate {
    private $conn;
    private $table_name = "special_schedule_dates";
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    /**
     * 获取租户的特殊日期列表
     * @param int $tenant_id 租户ID
     * @param string|null $start_date 开始日期(可选)
     * @param string|null $end_date 结束日期(可选)
     * @return array 特殊日期列表
     */
    public function getSpecialDates($tenant_id, $start_date = null, $end_date = null) {
        $query = "SELECT * FROM " . $this->table_name . " 
                  WHERE tenant_id = ?";
        $params = [$tenant_id];
        
        if ($start_date) {
            $query .= " AND date_value >= ?";
            $params[] = $start_date;
        }
        
        if ($end_date) {
            $query .= " AND date_value <= ?";
            $params[] = $end_date;
        }
        
        $query .= " ORDER BY date_value ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * 获取特定日期的特殊日期记录
     * @param string $date_value 日期
     * @param int $tenant_id 租户ID
     * @return array|false 特殊日期记录或false
     */
    public function getSpecialDateByValue($date_value, $tenant_id) {
        $query = "SELECT * FROM " . $this->table_name . " 
                  WHERE date_value = ? AND tenant_id = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$date_value, $tenant_id]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * 创建特殊日期
     * @param array $date_data 日期数据
     * @return int|false 成功返回日期ID，失败返回false
     */
    public function createSpecialDate($date_data) {
        // 检查日期是否已存在
        $existing = $this->getSpecialDateByValue($date_data['date_value'], $date_data['tenant_id']);
        if ($existing) {
            // 如果已存在，则更新而不是创建
            return $this->updateSpecialDate($existing['date_id'], $date_data);
        }
        
        $query = "INSERT INTO " . $this->table_name . " 
                  (tenant_id, date_value, date_type, date_name)
                  VALUES (?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($query);
        $result = $stmt->execute([
            $date_data['tenant_id'],
            $date_data['date_value'],
            $date_data['date_type'],
            $date_data['date_name']
        ]);
        
        if ($result) {
            return $this->conn->lastInsertId();
        }
        
        return false;
    }
    
    /**
     * 批量创建特殊日期
     * @param array $dates 日期数据数组
     * @param int $tenant_id 租户ID
     * @return array 操作结果
     */
    public function batchCreateSpecialDates($dates, $tenant_id) {
        $success_count = 0;
        $fail_count = 0;
        
        $this->conn->beginTransaction();
        
        try {
            foreach ($dates as $date) {
                // 添加租户ID
                $date['tenant_id'] = $tenant_id;
                
                $result = $this->createSpecialDate($date);
                if ($result) {
                    $success_count++;
                } else {
                    $fail_count++;
                }
            }
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'success_count' => $success_count,
                'fail_count' => $fail_count
            ];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("Error in batchCreateSpecialDates: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Transaction failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 更新特殊日期
     * @param int $date_id 日期ID
     * @param array $date_data 日期数据
     * @return bool 成功返回true，失败返回false
     */
    public function updateSpecialDate($date_id, $date_data) {
        $query = "UPDATE " . $this->table_name . " 
                  SET date_value = ?, date_type = ?, date_name = ? 
                  WHERE date_id = ? AND tenant_id = ?";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            $date_data['date_value'],
            $date_data['date_type'],
            $date_data['date_name'],
            $date_id,
            $date_data['tenant_id']
        ]);
    }
    
    /**
     * 删除特殊日期
     * @param int $date_id 日期ID
     * @param int $tenant_id 租户ID
     * @return bool 成功返回true，失败返回false
     */
    public function deleteSpecialDate($date_id, $tenant_id) {
        $query = "DELETE FROM " . $this->table_name . " 
                  WHERE date_id = ? AND tenant_id = ?";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([$date_id, $tenant_id]);
    }
}