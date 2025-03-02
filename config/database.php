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
                "mysql:host=" . $this->host . ";dbname=" . $this->database,
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->exec("set names utf8");
        } catch(PDOException $e) {
            echo "Connection Error: " . $e->getMessage();
        }
        
        return $this->conn;
    }
}