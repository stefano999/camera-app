<?php
// utils/DebugResponse.php - 增强日志记录的响应工具

class DebugResponse {
    // 返回标准格式的JSON响应，同时记录详细日志
    public static function json($code, $message, $data = null) {
        try {
            // 记录响应日志
            error_log("准备输出响应: code={$code}, message={$message}");
            
            // 设置HTTP响应状态码
            http_response_code($code);
            
            $response = [
                'code' => $code,
                'message' => $message
            ];
            
            if ($data !== null) {
                $response['data'] = $data;
                // 避免记录太大的数据
                error_log("响应包含数据结构: " . substr(json_encode($data), 0, 500) . "...(可能已截断)");
            }
            
            // 输出JSON
            header('Content-Type: application/json');
            
            // 捕获可能的JSON编码错误
            $json_result = json_encode($response);
            if ($json_result === false) {
                error_log("JSON编码失败: " . json_last_error_msg());
                // 退回到简单响应
                echo json_encode([
                    'code' => 500,
                    'message' => 'Error encoding response: ' . json_last_error_msg()
                ]);
            } else {
                echo $json_result;
            }
        } catch (Exception $e) {
            error_log("响应生成异常: " . $e->getMessage());
            
            // 尝试返回简单的错误响应
            http_response_code(500);
            echo json_encode([
                'code' => 500,
                'message' => 'Internal server error in response generation'
            ]);
        }
        
        exit;
    }
}