<?php
require_once '../config/config.php';
require_once 'session.php';

// Verify user is logged in
if (!isLoggedIn()) {
    redirectTo('/?error=' . urlencode('You are not logged in'));
}

$user = getCurrentUser();

// Log logout activity
logActivity('user_logout', "User {$user['email']} logged out", $user['id']);

// Destroy session and clean up tokens
destroySession();

// Redirect to landing page
redirectTo('');
?>