<?php
// utils/JwtHelper.php - JWT令牌工具

class JwtHelper {
    private static $secret_key = 'your_jwt_secret_key_change_this_in_production';
    private static $algorithm = 'HS256';
    private static $expiry_seconds = 86400; // 24小时
    
    // 生成JWT令牌
    public static function generateToken($user_id, $tenant_id) {
        $issued_at = time();
        $expiration_time = $issued_at + self::$expiry_seconds;
        
        $payload = [
            'iat' => $issued_at,
            'exp' => $expiration_time,
            'userId' => $user_id,
            'tenantId' => $tenant_id
        ];
        
        // 简单的JWT实现，实际生产环境请使用专业的JWT库
        $header = base64_encode(json_encode(['alg' => self::$algorithm, 'typ' => 'JWT']));
        $payload = base64_encode(json_encode($payload));
        $signature = hash_hmac('sha256', "$header.$payload", self::$secret_key);
        
        return "$header.$payload.$signature";
    }
    
    // 验证JWT令牌
    public static function validateToken($token) {
        if (!$token) {
            return false;
        }
        
        $parts = explode('.', $token);
        if (count($parts) != 3) {
            return false;
        }
        
        list($header, $payload, $signature) = $parts;
        
        $recalc_signature = hash_hmac('sha256', "$header.$payload", self::$secret_key);
        if ($signature !== $recalc_signature) {
            return false;
        }
        
        $payload_data = json_decode(base64_decode($payload), true);
        if (!isset($payload_data['exp']) || $payload_data['exp'] < time()) {
            return false;
        }
        
        return $payload_data;
    }
}