<?php
// Error reporting disabled for production

require_once '../config/config.php';
require_once '../config/database.php';

function redirectToAuth($error = '', $success = '') {
    // Check if this is an AJAX request
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    
    if ($isAjax) {
        // Return JSON response for AJAX requests
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $error ?: ($success ?: 'Authentication required')
        ]);
        exit;
    }
    
    // Traditional redirect for non-AJAX requests
    if ($error) {
        $_SESSION['auth_error'] = $error;
    }
    if ($success) {
        $_SESSION['auth_success'] = $success;
    }
    header("Location: " . SITE_URL);
    exit;
}

// Log all handler requests
try {
    Logger::access("Auth handler called - Method: " . $_SERVER['REQUEST_METHOD'] . " - Action: " . ($_POST['action'] ?? 'none'));
} catch (Exception $e) {
    error_log("Logger error in handler: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Logger::security("Invalid request method to auth handler: " . $_SERVER['REQUEST_METHOD']);
    
    // If someone accessed this page directly via GET, redirect them to the landing page
    // instead of showing an error
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        Logger::info("Redirecting GET request to auth handler back to landing page");
        header("Location: " . SITE_URL);
        exit;
    }
    
    redirectToAuth('Invalid request method');
}

$action = $_POST['action'] ?? '';

// Test database connection
try {
    $db = Database::getInstance()->getConnection();
    Logger::debug("Database connection successful");
} catch (Exception $e) {
    Logger::error("Database connection failed: " . $e->getMessage());
    redirectToAuth('Database connection failed');
}

try {
    Logger::debug("Processing action: $action");
    switch ($action) {
        case 'login':
            handleLogin($db);
            break;
        case 'register':
            handleRegister($db);
            break;
        case 'forgot_password':
            handleForgotPassword($db);
            break;
        case 'verify_email':
            handleEmailVerification($db);
            break;
        case 'reset_password':
            handlePasswordReset($db);
            break;
        default:
            redirectToAuth('Invalid action');
    }
} catch (Exception $e) {
    $errorMsg = "Auth Handler Error: " . $e->getMessage() . " in " . $e->getFile() . " at line " . $e->getLine();
    Logger::error($errorMsg);
    error_log($errorMsg);
    
    // Show detailed error in development
    if (DEBUG_MODE) {
        redirectToAuth('Error: ' . $e->getMessage());
    } else {
        redirectToAuth('An error occurred. Please try again.');
    }
}

function handleLogin($db) {
    Logger::debug("Starting login process");
    
    $login = sanitize($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    
    Logger::debug("Login attempt for: $login");
    
    if (empty($login) || empty($password)) {
        Logger::warning("Empty login credentials provided");
        redirectToAuth('Email/Username and password are required');
    }
    
    // Check for login attempts
    checkLoginAttempts($db, $login);
    
    // Check if login is email or username
    $isEmail = validateEmail($login);
    
    if ($isEmail) {
        $stmt = $db->prepare("
            SELECT user_id, first_name, last_name, email, username, password_hash, role_id, 
                   is_active, is_verified, last_login, failed_login_attempts, account_locked_until
            FROM users 
            WHERE email = ? AND is_active = 1
        ");
    } else {
        $stmt = $db->prepare("
            SELECT user_id, first_name, last_name, email, username, password_hash, role_id, 
                   is_active, is_verified, last_login, failed_login_attempts, account_locked_until
            FROM users 
            WHERE username = ? AND is_active = 1
        ");
    }
    
    $stmt->execute([$login]);
    $user = $stmt->fetch();
    
    if (!$user || !password_verify($password, $user['password_hash'])) {
        recordFailedLogin($db, $login);
        logSecurityEvent('failed_login_attempt', "Failed login for: {$login}", 'WARNING');
        redirectToAuth('Invalid credentials');
    }
    
    // Check if account is locked
    if ($user['account_locked_until'] && strtotime($user['account_locked_until']) > time()) {
        logSecurityEvent('account_lockout_access_attempt', "Locked account access attempt for: {$user['email']}", 'WARNING');
        redirectToAuth('Account is temporarily locked due to multiple failed login attempts');
    }
    
    // Check account status  
    if (!$user['is_active']) {
        redirectToAuth('Your account is inactive. Please contact administrator.');
    }
    
    // Check email verification
    if (!$user['is_verified']) {
        redirectToAuth('Please verify your account before logging in.');
    }
    
    // Successful login - reset failed attempts
    $stmt = $db->prepare("
        UPDATE users 
        SET failed_login_attempts = 0, account_locked_until = NULL, last_login = NOW() 
        WHERE user_id = ?
    ");
    $stmt->execute([$user['user_id']]);
    
    // Set session using mapped columns
    $_SESSION['user'] = mapUserColumns($user);
    
    $_SESSION['login_time'] = time();
    
    // Set remember me cookie
    if ($remember) {
        $token = generateToken();
        $expires = time() + (30 * 24 * 60 * 60); // 30 days
        
        $stmt = $db->prepare("
            INSERT INTO remember_tokens (user_id, token, expires_at) 
            VALUES (?, ?, FROM_UNIXTIME(?))
            ON DUPLICATE KEY UPDATE token = ?, expires_at = FROM_UNIXTIME(?)
        ");
        $stmt->execute([$user['user_id'], hash('sha256', $token), $expires, hash('sha256', $token), $expires]);
        
        setcookie('remember_token', $token, $expires, '/', '', true, true);
    }
    
    // Log successful login
    logActivity('user_login', "User {$user['email']} logged in successfully", $user['user_id']);
    Logger::access("Successful login for user: {$user['email']}");
    
    // Check if this is an AJAX request
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    
    // Get redirect URL based on role
    $roleName = getUserRoleName($user['role_id']);
    $redirectUrl = '';
    switch ($roleName) {
        case 'admin':
            $redirectUrl = '/admin/';
            break;
        case 'election_officer':
            $redirectUrl = '/election-officer/';
            break;
        case 'staff':
            $redirectUrl = '/staff/';
            break;
        case 'student':
            $redirectUrl = '/student/';
            break;
        default:
            redirectToAuth('Invalid user role');
    }
    
    if ($isAjax) {
        // Return JSON response for AJAX requests
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Login successful',
            'redirect' => SITE_URL . $redirectUrl
        ]);
        exit;
    } else {
        // Traditional redirect for non-AJAX requests
        redirectTo($redirectUrl);
    }
}

function handleRegister($db) {
    $first_name = sanitize($_POST['first_name'] ?? '');
    $last_name = sanitize($_POST['last_name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $student_id = sanitize($_POST['student_id'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validate input
    if (empty($first_name) || empty($last_name) || empty($email) || empty($student_id) || empty($password)) {
        redirectTo('auth/?action=register&error=' . urlencode('All fields are required'));
    }
    
    if (!validateEmail($email)) {
        redirectTo('auth/?action=register&error=' . urlencode('Invalid email format'));
    }
    
    if (strlen($password) < 8) {
        redirectTo('auth/?action=register&error=' . urlencode('Password must be at least 8 characters long'));
    }
    
    if ($password !== $confirm_password) {
        redirectTo('auth/?action=register&error=' . urlencode('Passwords do not match'));
    }
    
    // Check if email already exists
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        redirectTo('auth/?action=register&error=' . urlencode('Email address already registered'));
    }
    
    // Check if student ID already exists
    $stmt = $db->prepare("SELECT id FROM students WHERE student_id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();
    
    if (!$student) {
        redirectTo('auth/?action=register&error=' . urlencode('Student ID not found. Please contact administrator.'));
    }
    
    // Check if student already has an account
    $stmt = $db->prepare("SELECT id FROM users WHERE student_id = ?");
    $stmt->execute([$student['id']]);
    if ($stmt->fetch()) {
        redirectTo('auth/?action=register&error=' . urlencode('An account already exists for this student ID'));
    }
    
    // Create user account
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $verification_token = generateToken();
    
    $stmt = $db->prepare("
        INSERT INTO users (first_name, last_name, email, password, role, student_id, 
                          email_verification_token, status, created_at) 
        VALUES (?, ?, ?, ?, 'student', ?, ?, 'inactive', NOW())
    ");
    $stmt->execute([$first_name, $last_name, $email, $hashed_password, $student['id'], $verification_token]);
    
    $user_id = $db->lastInsertId();
    
    // Log registration
    logActivity('user_register', "New user registered: {$email}", $user_id);
    Logger::access("New user registration: {$email}");
    
    // Send verification email (if email is enabled)
    if (EMAIL_ENABLED) {
        sendVerificationEmail($email, $first_name, $verification_token);
        $message = 'Account created successfully! Please check your email for verification instructions.';
    } else {
        $message = 'Account created successfully! Please contact administrator for account activation.';
    }
    
    redirectTo('auth/?success=' . urlencode($message));
}

function handleForgotPassword($db) {
    $email = sanitize($_POST['email'] ?? '');
    
    if (empty($email) || !validateEmail($email)) {
        redirectTo('auth/?action=forgot&error=' . urlencode('Valid email address is required'));
    }
    
    $stmt = $db->prepare("SELECT id, first_name FROM users WHERE email = ? AND status != 'deleted'");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user) {
        $reset_token = generateToken();
        $expires = time() + 3600; // 1 hour
        
        $stmt = $db->prepare("
            INSERT INTO password_resets (user_id, token, expires_at) 
            VALUES (?, ?, FROM_UNIXTIME(?))
            ON DUPLICATE KEY UPDATE token = ?, expires_at = FROM_UNIXTIME(?)
        ");
        $stmt->execute([$user['id'], $reset_token, $expires, $reset_token, $expires]);
        
        // Log password reset request
        logActivity('password_reset_request', "Password reset requested for: {$email}", $user['id']);
        logSecurityEvent('password_reset_request', "Password reset requested for: {$email}", 'INFO');
        
        // Send reset email (if email is enabled)
        if (EMAIL_ENABLED) {
            sendPasswordResetEmail($email, $user['first_name'], $reset_token);
        }
    }
    
    // Always show success message (security best practice)
    redirectTo('auth/?success=' . urlencode('If the email exists in our system, you will receive password reset instructions.'));
}

function checkLoginAttempts($db, $login) {
    $isEmail = validateEmail($login);
    
    if ($isEmail) {
        $stmt = $db->prepare("
            SELECT failed_login_attempts, account_locked_until 
            FROM users 
            WHERE email = ?
        ");
    } else {
        $stmt = $db->prepare("
            SELECT failed_login_attempts, account_locked_until 
            FROM users 
            WHERE username = ?
        ");
    }
    
    $stmt->execute([$login]);
    $user = $stmt->fetch();
    
    if ($user && $user['failed_login_attempts'] >= MAX_LOGIN_ATTEMPTS) {
        $lockoutTime = strtotime($user['account_locked_until']);
        if ($lockoutTime > time()) {
            logSecurityEvent('account_lockout', "Account locked due to failed attempts: {$login}", 'WARNING');
            redirectToAuth('Too many failed login attempts. Please try again later.');
        }
    }
}

function recordFailedLogin($db, $login) {
    $isEmail = validateEmail($login);
    
    if ($isEmail) {
        $stmt = $db->prepare("
            UPDATE users 
            SET failed_login_attempts = failed_login_attempts + 1,
                account_locked_until = IF(failed_login_attempts + 1 >= ?, 
                                         DATE_ADD(NOW(), INTERVAL ? SECOND), 
                                         account_locked_until)
            WHERE email = ?
        ");
    } else {
        $stmt = $db->prepare("
            UPDATE users 
            SET failed_login_attempts = failed_login_attempts + 1,
                account_locked_until = IF(failed_login_attempts + 1 >= ?, 
                                         DATE_ADD(NOW(), INTERVAL ? SECOND), 
                                         account_locked_until)
            WHERE username = ?
        ");
    }
    
    $stmt->execute([MAX_LOGIN_ATTEMPTS, LOGIN_LOCKOUT_TIME, $login]);
    
    logActivity('failed_login', "Failed login attempt for: {$login}");
    logSecurityEvent('failed_login', "Failed login attempt for: {$login}", 'WARNING');
}

function sendVerificationEmail($email, $name, $token) {
    // Email sending logic would go here
    // For now, just log it
    error_log("Verification email for {$email}: " . SITE_URL . "auth/verify.php?token={$token}");
}

function sendPasswordResetEmail($email, $name, $token) {
    // Email sending logic would go here
    // For now, just log it
    error_log("Password reset email for {$email}: " . SITE_URL . "auth/reset.php?token={$token}");
}