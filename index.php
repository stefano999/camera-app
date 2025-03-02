<?php
// index.php - API主入口文件

// 允许跨域请求
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=UTF-8');

// 如果是OPTIONS请求，直接返回200状态码
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 引入必要文件
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/utils/Response.php';

// 获取请求路径
$request_uri = $_SERVER['REQUEST_URI'];
$base_path = '/attendance-api';  // 根路径
$path = str_replace($base_path, '', $request_uri);

// 解析请求路径以确定控制器和方法
$path_parts = explode('/', ltrim($path, '/'));
$controller = isset($path_parts[0]) && !empty($path_parts[0]) ? $path_parts[0] : 'index';
$method = isset($path_parts[1]) && !empty($path_parts[1]) ? $path_parts[1] : 'index';
$param = isset($path_parts[2]) ? $path_parts[2] : null;

// 路由分发
try {
    switch ($controller) {
        case 'auth':
            require_once __DIR__ . '/controllers/AuthController.php';
            $auth_controller = new AuthController();
            
            if ($method === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $auth_controller->login();
            } elseif ($method === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $auth_controller->logout();
            } elseif ($method === 'tenants' && $_SERVER['REQUEST_METHOD'] === 'GET') {
                $auth_controller->getTenants();
            } else {
                Response::json(404, 'Endpoint not found');
            }
            break;
            
        case 'user':
            require_once __DIR__ . '/controllers/UserController.php';
            $user_controller = new UserController();
            
            if ($method === 'info' && $_SERVER['REQUEST_METHOD'] === 'GET') {
                $user_controller->getUserInfo();
            } elseif ($method === 'profile' && $_SERVER['REQUEST_METHOD'] === 'PUT') {
                $user_controller->updateProfile();
            } elseif ($method === 'change-password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $user_controller->changePassword();
            } else {
                Response::json(404, 'Endpoint not found');
            }
            break;
            
        case 'users':
            require_once __DIR__ . '/controllers/UserController.php';
            $user_controller = new UserController();
            
            if ($method === 'index' && $_SERVER['REQUEST_METHOD'] === 'GET') {
                $user_controller->getUsers();
            } elseif (is_numeric($method) && $_SERVER['REQUEST_METHOD'] === 'GET') {
                $user_controller->getUserById($method);
            } elseif ($method === 'index' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $user_controller->addUser();
            } elseif (is_numeric($method) && $_SERVER['REQUEST_METHOD'] === 'PUT') {
                $user_controller->updateUser($method);
            } elseif (is_numeric($method) && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
                $user_controller->deleteUser($method);
            } elseif ($method === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $user_controller->importUsers();
            } else {
                Response::json(404, 'Endpoint not found');
            }
            break;
            
        case 'health':
            // 健康检查端点
            http_response_code(200);
            echo json_encode([
                'code' => 200,
                'message' => 'API is running',
                'data' => [
                    'status' => 'success',
                    'timestamp' => date('Y-m-d H:i:s'),
                    'environment' => getenv('APP_ENV') ?: 'production'
                ]
            ]);
            break;
            
        default:
            http_response_code(404);
            echo json_encode([
                'code' => 404,
                'message' => 'API endpoint not found'
            ]);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'code' => 500,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}