<?php
// middleware/TenantMiddleware.php - 租户隔离中间件

class TenantMiddleware {
    // 确保用户只能访问自己租户的数据
    public static function tenantAccessControl($auth, $tenantId = null) {
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return false;
        }
        
        $user = $auth->getUser();
        
        // 如果指定了租户ID，检查是否匹配用户的租户
        if ($tenantId && intval($tenantId) !== intval($user['tenant_id'])) {
            // 系统管理员可以访问任何租户
            if ($user['permissions'] === 'all') {
                return true;
            }
            
            Response::json(403, 'Access denied. You cannot access data from other tenants.');
            return false;
        }
        
        return true;
    }
    
    // 确保部门管理员只能访问自己部门的数据
    public static function departmentAccessControl($auth, $departmentId = null) {
        if (!$auth->isAuthenticated()) {
            Response::json(401, 'Unauthorized');
            return false;
        }
        
        $user = $auth->getUser();
        
        // 系统管理员和租户管理员可以访问任何部门
        if ($user['permissions'] === 'all' || $user['permissions'] === 'tenant_admin') {
            return true;
        }
        
        // 如果未指定部门ID，则允许访问
        if (!$departmentId) {
            return true;
        }
        
        // 部门管理员可以访问自己的部门
        if ($user['permissions'] === 'department_admin') {
            if (intval($departmentId) === intval($user['department_id'])) {
                return true;
            }
            
            // TODO: 检查子部门访问权限
            
            Response::json(403, 'Access denied. You can only access data from your department and sub-departments.');
            return false;
        }
        
        // 普通员工只能访问自己的部门
        if (intval($departmentId) !== intval($user['department_id'])) {
            Response::json(403, 'Access denied. You can only access data from your department.');
            return false;
        }
        
        return true;
    }
}