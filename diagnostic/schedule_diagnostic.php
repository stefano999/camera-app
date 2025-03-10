<?php
// diagnostic/schedule_diagnostic.php - 排班统计诊断工具

require_once __DIR__ . '/../config/database.php';

// 记录诊断开始
error_log("=== 开始排班系统诊断 ===");

try {
    // 测试数据库连接
    $database = new Database();
    $conn = $database->getConnection();
    error_log("数据库连接成功");
    
    // 检查schedules表
    $tables_query = "SHOW TABLES LIKE 'schedules'";
    $tables_stmt = $conn->prepare($tables_query);
    $tables_stmt->execute();
    $has_schedules_table = $tables_stmt->rowCount() > 0;
    
    error_log("schedules表存在: " . ($has_schedules_table ? "是" : "否"));
    
    if ($has_schedules_table) {
        // 检查表结构
        $describe_query = "DESCRIBE schedules";
        $describe_stmt = $conn->prepare($describe_query);
        $describe_stmt->execute();
        $columns = $describe_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("schedules表列数: " . count($columns));
        
        // 检查表中的数据
        $count_query = "SELECT COUNT(*) as count FROM schedules";
        $count_stmt = $conn->prepare($count_query);
        $count_stmt->execute();
        $count_result = $count_stmt->fetch(PDO::FETCH_ASSOC);
        
        error_log("schedules表记录数: " . $count_result['count']);
        
        // 检查特定部门的排班
        $dept_query = "SELECT COUNT(*) as count FROM schedules s
                      JOIN users u ON s.user_id = u.user_id
                      WHERE u.department_id = 6";
        $dept_stmt = $conn->prepare($dept_query);
        $dept_stmt->execute();
        $dept_result = $dept_stmt->fetch(PDO::FETCH_ASSOC);
        
        error_log("部门ID 6 的排班记录数: " . $dept_result['count']);
    }
    
    // 检查departments表中的部门6
    $dept_check_query = "SELECT * FROM departments WHERE department_id = 6";
    $dept_check_stmt = $conn->prepare($dept_check_query);
    $dept_check_stmt->execute();
    $department = $dept_check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($department) {
        error_log("部门 6 存在: " . json_encode($department));
        
        // 检查部门下的员工
        $emp_query = "SELECT COUNT(*) as count FROM users WHERE department_id = 6";
        $emp_stmt = $conn->prepare($emp_query);
        $emp_stmt->execute();
        $emp_result = $emp_stmt->fetch(PDO::FETCH_ASSOC);
        
        error_log("部门 6 的员工数: " . $emp_result['count']);
    } else {
        error_log("部门 6 不存在");
    }
    
    error_log("=== 诊断完成 ===");
    
    // 输出诊断结果
    echo "<h1>排班系统诊断结果</h1>";
    echo "<p>诊断已完成，详情请查看服务器错误日志</p>";
    
} catch (Exception $e) {
    error_log("诊断过程中出错: " . $e->getMessage());
    error_log("错误堆栈: " . $e->getTraceAsString());
    
    echo "<h1>诊断过程中出错</h1>";
    echo "<p>错误: " . htmlspecialchars($e->getMessage()) . "</p>";
}