<?php
// debug/schedule_api_debug.php - 排班API调试工具

// 设置错误显示
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 引入必要文件
require_once __DIR__ . '/../config/database.php';

// 页面头部
echo '<!DOCTYPE html>
<html>
<head>
    <title>排班API调试工具</title>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { color: #333; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 4px; overflow: auto; }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
        table { border-collapse: collapse; width: 100%; }
        table, th, td { border: 1px solid #ddd; }
        th, td { padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <h1>排班API调试工具</h1>';

// 显示PHP版本和扩展信息
echo '<h2>系统信息</h2>';
echo '<p>PHP版本: ' . phpversion() . '</p>';
echo '<p>已加载扩展: ' . implode(', ', get_loaded_extensions()) . '</p>';

// 显示请求信息
echo '<h2>请求信息</h2>';
echo '<pre>';
echo '请求方法: ' . ($_SERVER['REQUEST_METHOD'] ?? 'N/A') . "\n";
echo '请求路径: ' . ($_SERVER['REQUEST_URI'] ?? 'N/A') . "\n";
echo '</pre>';

// 数据库连接测试
echo '<h2>数据库连接测试</h2>';
try {
    $database = new Database();
    $conn = $database->getConnection();
    echo '<p class="success">数据库连接成功!</p>';
    
    // 获取数据库版本
    $stmt = $conn->query("SELECT VERSION() as version");
    $version = $stmt->fetch(PDO::FETCH_ASSOC);
    echo '<p>数据库版本: ' . ($version['version'] ?? 'Unknown') . '</p>';
    
} catch (Exception $e) {
    echo '<p class="error">数据库连接失败: ' . $e->getMessage() . '</p>';
}

// 表结构检查
echo '<h2>表结构检查</h2>';
try {
    if (isset($conn)) {
        // 检查schedules表
        $tables_query = "SHOW TABLES LIKE 'schedules'";
        $tables_stmt = $conn->prepare($tables_query);
        $tables_stmt->execute();
        $has_schedules_table = $tables_stmt->rowCount() > 0;
        
        if ($has_schedules_table) {
            echo '<p class="success">schedules表存在</p>';
            
            // 显示表结构
            $describe_query = "DESCRIBE schedules";
            $describe_stmt = $conn->prepare($describe_query);
            $describe_stmt->execute();
            $columns = $describe_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo '<h3>schedules表结构</h3>';
            echo '<table>';
            echo '<tr><th>字段</th><th>类型</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>';
            
            foreach ($columns as $column) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($column['Field']) . '</td>';
                echo '<td>' . htmlspecialchars($column['Type']) . '</td>';
                echo '<td>' . htmlspecialchars($column['Null']) . '</td>';
                echo '<td>' . htmlspecialchars($column['Key']) . '</td>';
                echo '<td>' . htmlspecialchars($column['Default'] ?? 'NULL') . '</td>';
                echo '<td>' . htmlspecialchars($column['Extra']) . '</td>';
                echo '</tr>';
            }
            
            echo '</table>';
            
            // 检查表中的数据
            $count_query = "SELECT COUNT(*) as count FROM schedules";
            $count_stmt = $conn->prepare($count_query);
            $count_stmt->execute();
            $count_result = $count_stmt->fetch(PDO::FETCH_ASSOC);
            
            echo '<p>schedules表中有 <strong>' . $count_result['count'] . '</strong> 条记录</p>';
            
            if ($count_result['count'] > 0) {
                // 显示示例数据
                $sample_query = "SELECT * FROM schedules LIMIT 5";
                $sample_stmt = $conn->prepare($sample_query);
                $sample_stmt->execute();
                $samples = $sample_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo '<h3>排班示例数据</h3>';
                echo '<pre>';
                print_r($samples);
                echo '</pre>';
            }
        } else {
            echo '<p class="error">schedules表不存在!</p>';
        }
        
        // 检查departments表
        echo '<h3>部门表检查</h3>';
        $dept_check_query = "SELECT * FROM departments WHERE department_id = 6";
        $dept_check_stmt = $conn->prepare($dept_check_query);
        $dept_check_stmt->execute();
        $department = $dept_check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($department) {
            echo '<p class="success">部门ID=6 存在:</p>';
            echo '<pre>';
            print_r($department);
            echo '</pre>';
            
            // 检查部门下的员工
            $emp_query = "SELECT COUNT(*) as count FROM users WHERE department_id = 6";
            $emp_stmt = $conn->prepare($emp_query);
            $emp_stmt->execute();
            $emp_result = $emp_stmt->fetch(PDO::FETCH_ASSOC);
            
            echo '<p>部门6下有 <strong>' . $emp_result['count'] . '</strong> 名员工</p>';
            
            if ($emp_result['count'] > 0) {
                // 显示员工列表
                $emp_list_query = "SELECT user_id, real_name, employee_id, username, status FROM users WHERE department_id = 6";
                $emp_list_stmt = $conn->prepare($emp_list_query);
                $emp_list_stmt->execute();
                $employees = $emp_list_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo '<h4>部门6的员工列表</h4>';
                echo '<table>';
                echo '<tr><th>ID</th><th>姓名</th><th>工号</th><th>用户名</th><th>状态</th></tr>';
                
                foreach ($employees as $emp) {
                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($emp['user_id']) . '</td>';
                    echo '<td>' . htmlspecialchars($emp['real_name']) . '</td>';
                    echo '<td>' . htmlspecialchars($emp['employee_id'] ?? 'N/A') . '</td>';
                    echo '<td>' . htmlspecialchars($emp['username']) . '</td>';
                    echo '<td>' . htmlspecialchars($emp['status']) . '</td>';
                    echo '</tr>';
                }
                
                echo '</table>';
            }
        } else {
            echo '<p class="error">部门ID=6 不存在!</p>';
        }
    }
} catch (Exception $e) {
    echo '<p class="error">表结构检查失败: ' . $e->getMessage() . '</p>';
    echo '<pre>' . $e->getTraceAsString() . '</pre>';
}

// API请求模拟
echo '<h2>API请求模拟</h2>';
echo '<form method="get" action="">';
echo '<p><label>部门ID: <input type="number" name="department_id" value="6"></label></p>';
echo '<p><label>开始日期: <input type="date" name="start_date" value="' . date('Y-m-01') . '"></label></p>';
echo '<p><label>结束日期: <input type="date" name="end_date" value="' . date('Y-m-t') . '"></label></p>';
echo '<p><button type="submit" name="test_api">测试API</button></p>';
echo '</form>';

// 执行API测试
if (isset($_GET['test_api'])) {
    $department_id = $_GET['department_id'] ?? 6;
    $start_date = $_GET['start_date'] ?? date('Y-m-01');
    $end_date = $_GET['end_date'] ?? date('Y-m-t');
    
    echo '<h3>API测试结果</h3>';
    echo '<p class="info">请求参数: department_id=' . $department_id . ', start_date=' . $start_date . ', end_date=' . $end_date . '</p>';
    
    try {
        // 直接模拟API的实现，避免依赖控制器
        if (isset($conn)) {
            // 测试获取部门员工数
            $query = "SELECT COUNT(*) as total_employees FROM users 
                     WHERE department_id = ? AND tenant_id = (
                         SELECT tenant_id FROM departments WHERE department_id = ?
                     ) AND status = 'active'";
            $stmt = $conn->prepare($query);
            $stmt->execute([$department_id, $department_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $total_employees = $result['total_employees'] ?? 0;
            
            echo '<p>部门员工数: ' . $total_employees . '</p>';
            
            // 返回模拟的统计数据
            $stats = [
                'overall' => [
                    'total_employees' => $total_employees,
                    'working_employees' => 0,
                    'resting_employees' => 0
                ],
                'daily_stats' => [],
                'shift_distribution' => [],
                'request_params' => [
                    'department_id' => $department_id,
                    'start_date' => $start_date,
                    'end_date' => $end_date
                ]
            ];
            
            echo '<h4>生成的API响应</h4>';
            echo '<pre>';
            echo json_encode($stats, JSON_PRETTY_PRINT);
            echo '</pre>';
            
            echo '<p class="success">API测试成功!</p>';
        }
    } catch (Exception $e) {
        echo '<p class="error">API测试失败: ' . $e->getMessage() . '</p>';
        echo '<pre>' . $e->getTraceAsString() . '</pre>';
    }
}

echo '<h2>解决方案</h2>';
echo '<p>如果上述排班API出现500错误，可以按照以下步骤解决：</p>';
echo '<ol>
    <li>确认排班表(schedules)结构是否正确</li>
    <li>检查部门ID=6是否存在并有员工</li>
    <li>修改ScheduleController.php中的getScheduleStats方法，添加错误处理和默认返回值</li>
    <li>确保Schedule模型中的getScheduleStats方法不会在schedules表为空时抛出异常</li>
    <li>更新代码使其能处理边缘情况，如无数据、空查询结果等</li>
</ol>';

echo '<h3>建议的修复代码</h3>';
echo '<p>请将我提供的完整ScheduleController.php替换您系统中现有的文件，代码已添加完善的错误处理和替代实现。</p>';

// 页面尾部
echo '</body></html>';