<?php
session_start();

// Log the logout
if (isset($_SESSION['student_number'])) {
    error_log("Student logout: {$_SESSION['student_number']} - {$_SESSION['student_name']}");
}

// Clear student session
$_SESSION['student_logged_in'] = false;
unset($_SESSION['student_id']);
unset($_SESSION['student_number']);
unset($_SESSION['student_name']);
unset($_SESSION['student_program']);
unset($_SESSION['student_class']);
unset($_SESSION['student_login_time']);

// Destroy session if it only contained student data
if (empty($_SESSION) || (count($_SESSION) == 1 && isset($_SESSION['student_logged_in']))) {
    session_destroy();
}

header('Content-Type: application/json');
echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
?>