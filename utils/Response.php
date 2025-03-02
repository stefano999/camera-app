<?php
// utils/Response.php - JSON响应工具

class Response {
    // 返回标准格式的JSON响应
    public static function json($code, $message, $data = null) {
        // 设置HTTP响应状态码
        http_response_code($code);
        
        $response = [
            'code' => $code,
            'message' => $message
        ];
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        // 输出JSON并终止脚本
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
}