<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

function initializeSession() {
    if (session_status() === PHP_SESSION_NONE) {
        // Configure session settings
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
        ini_set('session.use_strict_mode', 1);
        ini_set('session.cookie_samesite', 'Strict');
        
        session_start();
    }
    
    // Check for session timeout based on last activity (not login time)
    if (isset($_SESSION['last_activity'])) {
        if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
            logActivity('session_timeout', 'Session expired due to inactivity');
            $_SESSION['auth_error'] = 'Session expired. Please log in again.';
            destroySession();
            redirectTo('');
        }
    }
    
    // Update last activity time for active users
    if (isLoggedIn()) {
        $_SESSION['last_activity'] = time();
    }
    
    // Check for remember me token if not logged in
    if (!isLoggedIn() && isset($_COOKIE['remember_token'])) {
        validateRememberToken($_COOKIE['remember_token']);
    }
    
    // Regenerate session ID periodically
    if (!isset($_SESSION['last_regeneration'])) {
        $_SESSION['last_regeneration'] = time();
    } elseif (time() - $_SESSION['last_regeneration'] > 300) { // 5 minutes
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
}

function validateRememberToken($token) {
    if (empty($token)) {
        return false;
    }
    
    $db = Database::getInstance()->getConnection();
    $hashedToken = hash('sha256', $token);
    
    // Remember tokens disabled - skip database query
    $result = false;
    
    if ($result) {
        // Auto-login user
        $_SESSION['user'] = [
            'id' => $result['user_id'],
            'first_name' => $result['first_name'],
            'last_name' => $result['last_name'],
            'email' => $result['email'],
            'role' => $result['role']
        ];
        $_SESSION['login_time'] = time();
        
        // Update last login
        $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$result['user_id']]);
        
        logActivity('auto_login', 'User auto-logged in via remember token', $result['user_id']);
        Logger::access("Auto-login via remember token for user: {$result['email']}");
        
        // Extend remember token
        $newExpires = time() + (30 * 24 * 60 * 60); // 30 days
        // Remember tokens disabled
        // $stmt = $db->prepare("UPDATE remember_tokens SET expires_at = FROM_UNIXTIME(?) WHERE token = ?");
        // $stmt->execute([$newExpires, $hashedToken]);
        
        return true;
    } else {
        // Invalid or expired token - clean it up
        clearRememberToken($token);
        return false;
    }
}

function clearRememberToken($token = null) {
    if ($token) {
        $hashedToken = hash('sha256', $token);
        $db = Database::getInstance()->getConnection();
        // Remember tokens disabled
        // $stmt = $db->prepare("DELETE FROM remember_tokens WHERE token = ?");
        // $stmt->execute([$hashedToken]);
    }
    
    // Clear cookie
    setcookie('remember_token', '', time() - 3600, '/', '', true, true);
}

function destroySession() {
    // Clear remember token if exists
    if (isset($_COOKIE['remember_token'])) {
        clearRememberToken($_COOKIE['remember_token']);
    }
    
    // Log session destruction
    if (isLoggedIn()) {
        $user = getCurrentUser();
        logActivity('session_destroy', 'User session destroyed', $user['id']);
        Logger::access("Session destroyed for user: {$user['email']}");
    }
    
    // Destroy session
    $_SESSION = array();
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
}

function requireAuth($allowedRoles = []) {
    initializeSession();
    
    if (!isLoggedIn()) {
        Logger::access("Unauthorized access attempt to protected page: " . ($_SERVER['REQUEST_URI'] ?? ''));
        $_SESSION['auth_error'] = 'Please log in to access this page';
        redirectTo('');
    }
    
    $user = getCurrentUser();
    
    // Check if user role is allowed
    if (!empty($allowedRoles) && !in_array($user['role'], $allowedRoles)) {
        logSecurityEvent('access_denied', "User {$user['email']} attempted to access page requiring roles: " . implode(', ', $allowedRoles), 'WARNING');
        http_response_code(403);
        $_SESSION['auth_error'] = 'Access denied. Insufficient permissions.';
        redirectTo('');
    }
    
    // Check if account is still active
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT is_active FROM users WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $result = $stmt->fetch();
    
    if (!$result || !$result['is_active']) {
        logSecurityEvent('inactive_account_access', "Inactive account access attempt by user: {$user['email']}", 'WARNING');
        destroySession();
        $_SESSION['auth_error'] = 'Your account is no longer active. Please contact administrator.';
        redirectTo('');
    }
}

function requirePermission($permission) {
    requireAuth();
    
    if (!hasPermission($permission)) {
        $user = getCurrentUser();
        logSecurityEvent('permission_denied', "User {$user['email']} attempted action requiring permission: {$permission}", 'WARNING');
        http_response_code(403);
        $_SESSION['auth_error'] = 'Access denied. You do not have permission to perform this action.';
        redirectTo('');
    }
}

function preventAuthAccess() {
    if (isLoggedIn()) {
        $user = getCurrentUser();
        switch ($user['role']) {
            case 'admin':
                redirectTo('/admin/');
                break;
            case 'election_officer':
                redirectTo('/election-officer/');
                break;
            case 'staff':
                redirectTo('/staff/');
                break;
            case 'student':
                redirectTo('/student/');
                break;
        }
    }
}

function cleanupExpiredSessions() {
    $db = Database::getInstance()->getConnection();
    
    // Remember tokens functionality disabled
    // $stmt = $db->prepare("DELETE FROM remember_tokens WHERE expires_at < NOW()");
    // $stmt->execute();
    
    // Clean up expired password reset tokens - DISABLED for local student system
    // $stmt = $db->prepare("DELETE FROM password_resets WHERE expires_at < NOW()");
    // $stmt->execute();
    
    // Email verification cleanup skipped - column not implemented
}

function getSessionInfo() {
    if (!isLoggedIn()) {
        return null;
    }
    
    $user = getCurrentUser();
    $loginTime = $_SESSION['login_time'] ?? time();
    $lastActivity = $_SESSION['last_activity'] ?? time();
    $timeRemaining = SESSION_TIMEOUT - (time() - $lastActivity);
    
    return [
        'user_id' => $user['id'],
        'login_time' => date('Y-m-d H:i:s', $loginTime),
        'last_activity' => date('Y-m-d H:i:s', $lastActivity),
        'time_remaining' => max(0, $timeRemaining),
        'session_id' => session_id()
    ];
}

function extendSession() {
    if (isLoggedIn()) {
        $_SESSION['last_activity'] = time();
        return true;
    }
    return false;
}

// Initialize session automatically when this file is included
initializeSession();

// Run cleanup occasionally (1% chance)
if (mt_rand(1, 100) === 1) {
    cleanupExpiredSessions();
}