<?php
// utils/Response.php - JSON响应工具

class Response {
    // 返回标准格式的JSON响应
    public static function json($code, $message, $data = null) {
        http_response_code($code);
        
        $response = [
            'code' => $code,
            'message' => $message
        ];
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        echo json_encode($response);
        exit;
    }
}