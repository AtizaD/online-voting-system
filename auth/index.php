<?php
// Redirect all auth requests to the main landing page

require_once '../config/config.php';

// Debug logging
try {
    Logger::access("Auth page accessed - redirecting to landing page");
} catch (Exception $e) {
    error_log("Logger error: " . $e->getMessage());
}

// Check if already logged in
if (isLoggedIn()) {
    $user = getCurrentUser();
    switch ($user['role']) {
        case 'admin':
            redirectTo('admin/');
            break;
        case 'election_officer':
            redirectTo('election-officer/');
            break;
        case 'staff':
            redirectTo('staff/');
            break;
        case 'student':
            redirectTo('student/');
            break;
    }
}

// Preserve any error or success messages in session
if (isset($_GET['error'])) {
    $_SESSION['auth_error'] = $_GET['error'];
}
if (isset($_GET['success'])) {
    $_SESSION['auth_success'] = $_GET['success'];
}

// Redirect to landing page where login is available
redirectTo('');
?>