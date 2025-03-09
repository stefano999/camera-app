<?php
// config/database.php - 数据库配置

class Database {
    private $host = 'localhost';
    private $username = 'euromark';
    private $password = '91w5Fw-osG)2YG';
    private $database = 'euromark_885';
    private $conn;
    
    // 获取数据库连接
    public function getConnection() {
        $this->conn = null;
        
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->database . ";charset=utf8mb4",
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // 设置欧洲时区 (中欧时间)
            $this->conn->exec("SET time_zone = 'Europe/Berlin'");
            
            // 确保使用UTF-8mb4字符集，此字符集完全支持中文等多字节字符
            // 已在DSN中设置charset=utf8mb4，因此下面这行不是必须的
            // $this->conn->exec("set names utf8mb4");
        } catch(PDOException $e) {
            echo "Connection Error: " . $e->getMessage();
        }
        
        return $this->conn;
    }
}