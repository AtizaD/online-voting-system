<?php
// Set timezone for Ghana/Accra
date_default_timezone_set('Africa/Accra');

// Configure error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);  // Don't display errors on screen
ini_set('log_errors', 1);      // Log errors to file
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Fixed SITE_URL to avoid relative path issues  
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('SITE_URL', $protocol . '://' . $host . '/online_voting');
define('SITE_NAME', 'E-Voting System');
define('SITE_TITLE', 'Online Voting Platform');

define('ADMIN_EMAIL', 'admin@evoting.local');
define('SYSTEM_EMAIL', 'system@evoting.local');

define('TIMEZONE', 'Africa/Lagos');
date_default_timezone_set(TIMEZONE);

define('SESSION_TIMEOUT', 1800); // 30 minutes

// Session timeout is handled by auth/session.php - removed duplicate logic to prevent conflicts
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes

define('UPLOAD_MAX_SIZE', 5242880); // 5MB
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif']);
define('ALLOWED_DOCUMENT_TYPES', ['pdf', 'doc', 'docx']);

define('PAGINATION_LIMIT', 20);
define('SEARCH_MIN_LENGTH', 3);

define('VOTING_SESSION_TIMEOUT', 300); // 5 minutes
define('VOTE_VERIFICATION_ENABLED', true);
define('MULTIPLE_VOTES_ALLOWED', false);

define('EMAIL_ENABLED', false);
define('SMS_ENABLED', false);

define('MAINTENANCE_MODE', false);
define('DEBUG_MODE', false);

define('LOG_LEVEL', 'INFO'); // DEBUG, INFO, WARNING, ERROR
define('LOG_MAX_SIZE', 10485760); // 10MB

$user_roles = [
    'admin' => 'Administrator',
    'election_officer' => 'Election Officer', 
    'staff' => 'Staff',
    'student' => 'Student'
];

$user_permissions = [
    'admin' => [
        'manage_users', 'manage_students', 'manage_elections', 
        'manage_candidates', 'manage_voting', 'monitor_voting', 'view_results',
        'publish_results', 'manage_settings', 'view_reports',
        'manage_system'
    ],
    'election_officer' => [
        'manage_elections', 'manage_candidates', 'monitor_voting',
        'view_results', 'publish_results', 'verify_students'
    ],
    'staff' => [
        'manage_students', 'verify_students', 'assist_voting'
    ],
    'student' => [
        'vote', 'view_candidates', 'view_results', 'verify_vote'
    ]
];

$election_statuses = [
    'draft' => 'Draft',
    'scheduled' => 'Scheduled',
    'active' => 'Active',
    'paused' => 'Paused',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled'
];

$verification_statuses = [
    'pending' => 'Pending Verification',
    'verified' => 'Verified',
    'rejected' => 'Rejected',
    'suspended' => 'Suspended'
];

function getCurrentUser() {
    return $_SESSION['user'] ?? null;
}

// Map old column names to new schema column names
function mapUserColumns($user) {
    if (!$user) return null;
    
    // Map database columns to session format
    return [
        'id' => $user['user_id'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'], 
        'email' => $user['email'],
        'username' => $user['username'],
        'role' => getUserRoleName($user['role_id']),
        'last_login' => $user['last_login']
    ];
}

function getUserRoleName($role_id) {
    static $roles = [
        1 => 'admin',
        2 => 'election_officer',
        3 => 'staff',
        4 => 'student'
    ];
    return $roles[$role_id] ?? 'student';
}

function isLoggedIn() {
    return isset($_SESSION['user']) && isset($_SESSION['user']['id']);
}

function hasPermission($permission) {
    global $user_permissions;
    $user = getCurrentUser();
    
    if (!$user || !isset($user['role'])) {
        return false;
    }
    
    return in_array($permission, $user_permissions[$user['role']] ?? []);
}

function hasRole($role) {
    $user = getCurrentUser();
    return $user && ($user['role'] === $role);
}

function redirectTo($url) {
    // Use SITE_URL constant for better maintainability
    $cleanUrl = ltrim($url, '/');
    header("Location: " . SITE_URL . '/' . $cleanUrl);
    exit;
}

function sanitize($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function generateToken($length = 32) {
    return bin2hex(random_bytes($length));
}

// Safe require with file existence check
$logger_path = __DIR__ . '/../includes/logger.php';
if (file_exists($logger_path)) {
    require_once $logger_path;
}

function logActivity($action, $details = '', $user_id = null) {
    if (!$user_id && isLoggedIn()) {
        $user_id = getCurrentUser()['id'];
    }
    
    $message = "Action: $action";
    if ($details) {
        $message .= " | Details: $details";
    }
    if ($user_id) {
        $message .= " | User ID: $user_id";
    }
    
    // Check if Logger class exists before using it
    if (class_exists('Logger')) {
        Logger::info($message);
    } else {
        error_log("E-Voting: $message");
    }
    
    // Also insert into database audit_logs table
    try {
        // Only attempt database logging if Database class exists and is available
        if (class_exists('Database')) {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                INSERT INTO audit_logs (user_id, action, ip_address, user_agent)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $user_id,
                $action,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        }
    } catch (Exception $e) {
        error_log("Failed to log activity to database: " . $e->getMessage());
    }
}