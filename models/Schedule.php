<?php

// models/Schedule.php - 排班模型



require_once __DIR__ . '/../config/database.php';



class Schedule {

    private $conn;

    private $table_name = "schedules";

    

    public function __construct() {

        $database = new Database();

        $this->conn = $database->getConnection();

    }

    

    /**

     * 获取员工的排班列表

     * @param int $user_id 员工ID

     * @param int $tenant_id 租户ID

     * @param string $start_date 开始日期

     * @param string $end_date 结束日期

     * @return array 排班列表

     */

    public function getUserSchedules($user_id, $tenant_id, $start_date, $end_date) {

        $query = "SELECT s.*, sh.shift_name, sh.start_time, sh.end_time, sh.color_code

                  FROM " . $this->table_name . " s

                  LEFT JOIN shifts sh ON s.shift_id = sh.shift_id

                  WHERE s.user_id = ? AND s.tenant_id = ? 

                  AND s.schedule_date BETWEEN ? AND ?

                  ORDER BY s.schedule_date ASC";

        

        $stmt = $this->conn->prepare($query);

        $stmt->execute([$user_id, $tenant_id, $start_date, $end_date]);

        

        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    }

    

    /**

     * 获取部门的排班列表

     * @param int $department_id 部门ID

     * @param int $tenant_id 租户ID

     * @param string $start_date 开始日期

     * @param string $end_date 结束日期

     * @return array 排班列表

     */

    public function getDepartmentSchedules($department_id, $tenant_id, $start_date, $end_date) {

        $query = "SELECT s.*, u.real_name, u.employee_id, sh.shift_name, sh.start_time, sh.end_time, sh.color_code

                  FROM " . $this->table_name . " s

                  JOIN users u ON s.user_id = u.user_id

                  LEFT JOIN shifts sh ON s.shift_id = sh.shift_id

                  WHERE u.department_id = ? AND s.tenant_id = ? 

                  AND s.schedule_date BETWEEN ? AND ?

                  ORDER BY s.schedule_date ASC, u.real_name ASC";

        

        $stmt = $this->conn->prepare($query);

        $stmt->execute([$department_id, $tenant_id, $start_date, $end_date]);

        

        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    }

    

    /**

     * 创建排班记录

     * @param array $schedule_data 排班数据

     * @return int|bool 成功返回ID，失败返回false

     */

    public function createSchedule($schedule_data) {

        // 检查是否已存在该员工在该日期的排班

        $check_query = "SELECT schedule_id FROM " . $this->table_name . " 

                        WHERE user_id = ? AND schedule_date = ?";

        $check_stmt = $this->conn->prepare($check_query);

        $check_stmt->execute([$schedule_data['user_id'], $schedule_data['schedule_date']]);

        

        if ($check_stmt->rowCount() > 0) {

            // 已存在记录，执行更新

            $record = $check_stmt->fetch(PDO::FETCH_ASSOC);

            return $this->updateSchedule($record['schedule_id'], $schedule_data);

        }

        

        // 不存在记录，执行插入

        $query = "INSERT INTO " . $this->table_name . " 

                  (tenant_id, user_id, shift_id, schedule_date, is_rest_day, notes, created_by) 

                  VALUES (?, ?, ?, ?, ?, ?, ?)";

        

        $stmt = $this->conn->prepare($query);

        $result = $stmt->execute([

            $schedule_data['tenant_id'],

            $schedule_data['user_id'],

            $schedule_data['shift_id'] ?? null,

            $schedule_data['schedule_date'],

            $schedule_data['is_rest_day'] ?? 0,

            $schedule_data['notes'] ?? null,

            $schedule_data['created_by']

        ]);

        

        if ($result) {

            return $this->conn->lastInsertId();

        }

        

        return false;

    }

    

    /**

     * 更新排班记录

     * @param int $schedule_id 排班ID

     * @param array $schedule_data 排班数据

     * @return bool 成功返回true，失败返回false

     */

    public function updateSchedule($schedule_id, $schedule_data) {

        $query = "UPDATE " . $this->table_name . " 

                  SET shift_id = ?, is_rest_day = ?, notes = ?, updated_by = ? 

                  WHERE schedule_id = ?";

        

        $stmt = $this->conn->prepare($query);

        return $stmt->execute([

            $schedule_data['shift_id'] ?? null,

            $schedule_data['is_rest_day'] ?? 0,

            $schedule_data['notes'] ?? null,

            $schedule_data['updated_by'] ?? $schedule_data['created_by'],

            $schedule_id

        ]);

    }

    

    /**

     * 批量创建或更新排班

     * @param array $schedules 排班数据数组

     * @return array 成功和失败的记录数

     */

    public function batchCreateOrUpdateSchedules($schedules) {

        $success_count = 0;

        $fail_count = 0;

        

        $this->conn->beginTransaction();

        

        try {

            foreach ($schedules as $schedule) {

                $result = $this->createSchedule($schedule);

                if ($result) {

                    $success_count++;

                } else {

                    $fail_count++;

                }

            }

            

            $this->conn->commit();

        } catch (Exception $e) {

            $this->conn->rollback();

            error_log("Error in batchCreateOrUpdateSchedules: " . $e->getMessage());

            

            return [

                'success' => false,

                'message' => 'Transaction failed: ' . $e->getMessage()

            ];

        }

        

        return [

            'success' => true,

            'success_count' => $success_count,

            'fail_count' => $fail_count

        ];

    }

    

    /**

     * 删除排班记录

     * @param int $schedule_id 排班ID

     * @return bool 成功返回true，失败返回false

     */

    public function deleteSchedule($schedule_id, $tenant_id) {

        $query = "DELETE FROM " . $this->table_name . " 

                  WHERE schedule_id = ? AND tenant_id = ?";

        

        $stmt = $this->conn->prepare($query);

        return $stmt->execute([$schedule_id, $tenant_id]);

    }

    

    /**

     * 获取排班统计

     * @param int $department_id 部门ID

     * @param int $tenant_id 租户ID

     * @param string $date 日期

     * @return array 统计数据

     */

    public function getDailyScheduleStats($department_id, $tenant_id, $date) {

        $query = "SELECT 

                    COUNT(DISTINCT s.user_id) AS total_employees,

                    SUM(CASE WHEN s.is_rest_day = 0 THEN 1 ELSE 0 END) AS working_employees,

                    SUM(CASE WHEN s.is_rest_day = 1 THEN 1 ELSE 0 END) AS resting_employees

                  FROM " . $this->table_name . " s

                  JOIN users u ON s.user_id = u.user_id

                  WHERE u.department_id = ? AND s.tenant_id = ? AND s.schedule_date = ?";

        

        $stmt = $this->conn->prepare($query);

        $stmt->execute([$department_id, $tenant_id, $date]);

        

        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        

        // 获取班次分布

        $shift_query = "SELECT sh.shift_id, sh.shift_name, COUNT(*) AS count

                        FROM " . $this->table_name . " s

                        JOIN users u ON s.user_id = u.user_id

                        JOIN shifts sh ON s.shift_id = sh.shift_id

                        WHERE u.department_id = ? AND s.tenant_id = ? 

                        AND s.schedule_date = ? AND s.is_rest_day = 0

                        GROUP BY sh.shift_id

                        ORDER BY count DESC";

        

        $shift_stmt = $this->conn->prepare($shift_query);

        $shift_stmt->execute([$department_id, $tenant_id, $date]);

        $shift_distribution = $shift_stmt->fetchAll(PDO::FETCH_ASSOC);

        

        $stats['shift_distribution'] = $shift_distribution;

        

        return $stats;

    }

    

    /**

     * 获取日期范围内的排班统计

     * @param int $department_id 部门ID

     * @param int $tenant_id 租户ID

     * @param string $start_date 开始日期

     * @param string $end_date 结束日期

     * @return array 统计数据

     */

    
/**
 * 获取日期范围内的排班统计
 * @param int $department_id 部门ID
 * @param int $tenant_id 租户ID
 * @param string $start_date 开始日期
 * @param string $end_date 结束日期
 * @return array 统计数据
 */
public function getScheduleStats($department_id, $tenant_id, $start_date, $end_date) {
    try {
        // 获取总体统计
        $overall_query = "SELECT 
                            COUNT(DISTINCT u.user_id) AS total_employees,
                            COUNT(DISTINCT CASE WHEN s.is_rest_day = 0 THEN s.user_id ELSE NULL END) AS working_employees,
                            COUNT(DISTINCT CASE WHEN s.is_rest_day = 1 THEN s.user_id ELSE NULL END) AS resting_employees
                          FROM users u
                          LEFT JOIN " . $this->table_name . " s ON u.user_id = s.user_id AND s.schedule_date = CURDATE()
                          WHERE u.department_id = ? AND u.tenant_id = ? AND u.status = 'active'";
        
        $overall_stmt = $this->conn->prepare($overall_query);
        $overall_stmt->execute([$department_id, $tenant_id]);
        $overall_stats = $overall_stmt->fetch(PDO::FETCH_ASSOC);
        
        // 如果没有数据，提供默认值
        if (!$overall_stats) {
            $overall_stats = [
                'total_employees' => 0,
                'working_employees' => 0,
                'resting_employees' => 0
            ];
        }
        
        // 获取当前部门员工数量（作为备用，确保至少有员工总数）
        $emp_query = "SELECT COUNT(*) as total FROM users 
                     WHERE department_id = ? AND tenant_id = ? AND status = 'active'";
        $emp_stmt = $this->conn->prepare($emp_query);
        $emp_stmt->execute([$department_id, $tenant_id]);
        $emp_result = $emp_stmt->fetch(PDO::FETCH_ASSOC);
        
        // 确保总员工数正确
        if ($overall_stats['total_employees'] == 0 && isset($emp_result['total'])) {
            $overall_stats['total_employees'] = $emp_result['total'];
        }
        
        // 初始化每日统计和班次分布
        $daily_stats = [];
        $shift_distribution = [];
        
        // 获取每日统计（仅当有排班数据时）
        $has_schedules = $this->hasScheduleData($department_id, $tenant_id, $start_date, $end_date);
        
        if ($has_schedules) {
            // 获取每日统计
            $daily_query = "SELECT 
                             s.schedule_date AS date, 
                             SUM(CASE WHEN s.is_rest_day = 0 THEN 1 ELSE 0 END) AS working, 
                             SUM(CASE WHEN s.is_rest_day = 1 THEN 1 ELSE 0 END) AS resting
                           FROM " . $this->table_name . " s
                           JOIN users u ON s.user_id = u.user_id
                           WHERE u.department_id = ? AND s.tenant_id = ? 
                           AND s.schedule_date BETWEEN ? AND ?
                           GROUP BY s.schedule_date
                           ORDER BY s.schedule_date";
            
            $daily_stmt = $this->conn->prepare($daily_query);
            $daily_stmt->execute([$department_id, $tenant_id, $start_date, $end_date]);
            $daily_stats = $daily_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 获取班次分布
            $shift_query = "SELECT sh.shift_name, COUNT(*) AS count
                           FROM " . $this->table_name . " s
                           JOIN users u ON s.user_id = u.user_id
                           JOIN shifts sh ON s.shift_id = sh.shift_id
                           WHERE u.department_id = ? AND s.tenant_id = ? 
                           AND s.schedule_date BETWEEN ? AND ? AND s.is_rest_day = 0
                           GROUP BY sh.shift_id
                           ORDER BY count DESC";
            
            $shift_stmt = $this->conn->prepare($shift_query);
            $shift_stmt->execute([$department_id, $tenant_id, $start_date, $end_date]);
            $shift_distribution = $shift_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // 如果没有数据，生成默认日期范围的空统计
        if (empty($daily_stats)) {
            $current_date = new DateTime($start_date);
            $end_date_obj = new DateTime($end_date);
            
            while ($current_date <= $end_date_obj) {
                $date_str = $current_date->format('Y-m-d');
                $daily_stats[] = [
                    'date' => $date_str,
                    'working' => 0,
                    'resting' => 0
                ];
                $current_date->modify('+1 day');
            }
        }
        
        // 返回完整统计数据
        return [
            'overall' => $overall_stats,
            'daily_stats' => $daily_stats,
            'shift_distribution' => $shift_distribution
        ];
        
    } catch (Exception $e) {
        // 记录错误，但返回空结果而不是抛出异常
        error_log("Error in getScheduleStats: " . $e->getMessage());
        return [
            'overall' => [
                'total_employees' => 0,
                'working_employees' => 0,
                'resting_employees' => 0
            ],
            'daily_stats' => [],
            'shift_distribution' => []
        ];
    }
}

/**
 * 检查是否有排班数据
 * @param int $department_id 部门ID
 * @param int $tenant_id 租户ID
 * @param string $start_date 开始日期
 * @param string $end_date 结束日期
 * @return bool 是否存在排班数据
 */
private function hasScheduleData($department_id, $tenant_id, $start_date, $end_date) {
    $query = "SELECT COUNT(*) as count FROM " . $this->table_name . " s
              JOIN users u ON s.user_id = u.user_id
              WHERE u.department_id = ? AND s.tenant_id = ? 
              AND s.schedule_date BETWEEN ? AND ?";
    
    $stmt = $this->conn->prepare($query);
    $stmt->execute([$department_id, $tenant_id, $start_date, $end_date]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result['count'] > 0;
}
    

    /**

     * 根据模板生成排班

     * @param int $department_id 部门ID

     * @param int $tenant_id 租户ID

     * @param int $template_id 模板ID

     * @param string $start_date 开始日期

     * @param string $end_date 结束日期

     * @param bool $override_existing 是否覆盖现有排班

     * @param int $created_by 创建人ID

     * @return array 生成结果

     */

    public function generateSchedulesFromTemplate($department_id, $tenant_id, $template_id, $start_date, $end_date, $override_existing, $created_by) {

        // 获取部门所有员工

        $user_query = "SELECT user_id FROM users 

                       WHERE department_id = ? AND tenant_id = ? AND status = 'active'";

        $user_stmt = $this->conn->prepare($user_query);

        $user_stmt->execute([$department_id, $tenant_id]);

        $users = $user_stmt->fetchAll(PDO::FETCH_COLUMN);

        

        if (empty($users)) {

            return [

                'success' => false,

                'message' => 'No active employees found in this department'

            ];

        }

        

        // 获取模板详情

        $template_query = "SELECT * FROM schedule_template_details 

                           WHERE template_id = ? ORDER BY day_of_week";

        $template_stmt = $this->conn->prepare($template_query);

        $template_stmt->execute([$template_id]);

        $template_details = $template_stmt->fetchAll(PDO::FETCH_ASSOC);

        

        if (empty($template_details)) {

            return [

                'success' => false,

                'message' => 'Template details not found'

            ];

        }

        

        // 获取特殊日期

        $special_dates_query = "SELECT date_value, date_type FROM special_schedule_dates 

                               WHERE tenant_id = ? AND date_value BETWEEN ? AND ?";

        $special_dates_stmt = $this->conn->prepare($special_dates_query);

        $special_dates_stmt->execute([$tenant_id, $start_date, $end_date]);

        $special_dates = [];

        while ($row = $special_dates_stmt->fetch(PDO::FETCH_ASSOC)) {

            $special_dates[$row['date_value']] = $row['date_type'];

        }

        

        // 准备批量插入的数据

        $schedules = [];

        $current_date = new DateTime($start_date);

        $end_date_obj = new DateTime($end_date);

        

        // 如果需要，删除现有排班

        if ($override_existing) {

            $delete_query = "DELETE FROM " . $this->table_name . " 

                            WHERE tenant_id = ? AND schedule_date BETWEEN ? AND ? 

                            AND user_id IN (" . implode(',', $users) . ")";

            $delete_stmt = $this->conn->prepare($delete_query);

            $delete_stmt->execute([$tenant_id, $start_date, $end_date]);

        }

        

        // 为每个日期和每个用户生成排班

        while ($current_date <= $end_date_obj) {

            $date_str = $current_date->format('Y-m-d');

            $day_of_week = $current_date->format('N'); // 1(周一) 到 7(周日)

            

            // 查找对应的模板详情

            $template_detail = null;

            foreach ($template_details as $detail) {

                if ($detail['day_of_week'] == $day_of_week) {

                    $template_detail = $detail;

                    break;

                }

            }

            

            if (!$template_detail) {

                $current_date->modify('+1 day');

                continue;

            }

            

            // 检查是否为特殊日期

            $is_special_date = isset($special_dates[$date_str]);

            $is_holiday = $is_special_date && $special_dates[$date_str] == 'holiday';

            $is_special_workday = $is_special_date && $special_dates[$date_str] == 'special_workday';

            

            // 处理特殊日期逻辑

            $is_rest_day = $template_detail['is_rest_day'];

            if ($is_holiday) {

                $is_rest_day = true;

            } else if ($is_special_workday) {

                $is_rest_day = false;

            }

            

            foreach ($users as $user_id) {

                // 检查是否已存在排班（如果不覆盖）

                if (!$override_existing) {

                    $check_query = "SELECT COUNT(*) FROM " . $this->table_name . " 

                                   WHERE user_id = ? AND schedule_date = ?";

                    $check_stmt = $this->conn->prepare($check_query);

                    $check_stmt->execute([$user_id, $date_str]);

                    

                    if ($check_stmt->fetchColumn() > 0) {

                        continue; // 跳过已存在的排班

                    }

                }

                

                $schedules[] = [

                    'tenant_id' => $tenant_id,

                    'user_id' => $user_id,

                    'shift_id' => $is_rest_day ? null : $template_detail['shift_id'],

                    'schedule_date' => $date_str,

                    'is_rest_day' => $is_rest_day ? 1 : 0,

                    'created_by' => $created_by

                ];

            }

            

            $current_date->modify('+1 day');

        }

        

        // 批量创建排班

        $result = $this->batchCreateOrUpdateSchedules($schedules);

        

        return $result;

    }

}