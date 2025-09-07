<?php
require_once 'config/config.php';
require_once 'config/database.php';

// Log page access
Logger::access("Landing page accessed");

if (MAINTENANCE_MODE && !hasRole('admin')) {
    Logger::info("Maintenance mode redirect for non-admin user");
    http_response_code(503);
    include 'maintenance.html';
    exit;
}

if (isLoggedIn()) {
    $user = getCurrentUser();
    Logger::access("User {$user['email']} accessing landing page - redirecting to {$user['role']} dashboard");
    
    switch ($user['role']) {
        case 'admin':
            logActivity('dashboard_access', 'Admin dashboard accessed', $user['id']);
            redirectTo('/admin/');
            break;
        case 'election_officer':
            logActivity('dashboard_access', 'Election officer dashboard accessed', $user['id']);
            redirectTo('/election-officer/');
            break;
        case 'staff':
            logActivity('dashboard_access', 'Staff dashboard accessed', $user['id']);
            redirectTo('/staff/');
            break;
        case 'student':
            logActivity('dashboard_access', 'Student dashboard accessed', $user['id']);
            redirectTo('/student/');
            break;
        default:
            logSecurityEvent('invalid_role', "User {$user['email']} has invalid role: {$user['role']}", 'WARNING');
            session_destroy();
            redirectTo('');
    }
} else {
    Logger::access("Unauthenticated user shown landing page");
    
    // Fetch real data from database
    $db = Database::getInstance()->getConnection();
    
    // Get active elections count
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM elections WHERE start_date <= NOW() AND end_date >= NOW()");
    $stmt->execute();
    $active_elections = $stmt->fetch()['count'] ?? 0;
    
    // Get total votes cast
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM votes");
    $stmt->execute();
    $total_votes = $stmt->fetch()['count'] ?? 0;
    
    // Get total eligible voters (verified students)
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM students WHERE is_active = 1 AND is_verified = 1");
    $stmt->execute();
    $eligible_voters = $stmt->fetch()['count'] ?? 0;
    
    // Calculate turnout rate
    $turnout_rate = $eligible_voters > 0 ? round(($total_votes / $eligible_voters) * 100, 1) : 0;
    
    // Check if there are any active elections
    $has_active_elections = $active_elections > 0;
    
    // Handle auth messages
    $auth_error = '';
    $auth_success = '';
    if (isset($_SESSION['auth_error'])) {
        $auth_error = $_SESSION['auth_error'];
        unset($_SESSION['auth_error']);
    }
    if (isset($_SESSION['auth_success'])) {
        $auth_success = $_SESSION['auth_success'];
        unset($_SESSION['auth_success']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Online Voting System - Cast Your Vote</title>
    <link href="<?= SITE_URL ?>/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= SITE_URL ?>/assets/css/font-awesome.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --secondary-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --success-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --vote-gradient: linear-gradient(135deg, #ff6b6b 0%, #feca57 100%);
            
            --primary-color: #667eea;
            --secondary-color: #764ba2;
            --success-color: #00d4aa;
            --danger-color: #ff6b6b;
            --warning-color: #feca57;
            --vote-color: #ff6b6b;
            
            --text-primary: #2c3e50;
            --text-secondary: #7f8c8d;
            --text-light: #95a5a6;
            --text-white: #ffffff;
            
            --shadow-light: 0 5px 15px rgba(0,0,0,0.08);
            --shadow-medium: 0 10px 30px rgba(0,0,0,0.12);
            --shadow-heavy: 0 20px 60px rgba(0,0,0,0.15);
            
            --border-radius: 20px;
            --border-radius-small: 12px;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--primary-gradient);
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 20% 50%, rgba(120, 119, 198, 0.3) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255, 119, 198, 0.3) 0%, transparent 50%),
                radial-gradient(circle at 40% 80%, rgba(120, 219, 226, 0.3) 0%, transparent 50%);
            z-index: -1;
            animation: backgroundFlow 20s ease-in-out infinite;
        }

        @keyframes backgroundFlow {
            0%, 100% { transform: scale(1) rotate(0deg); }
            50% { transform: scale(1.1) rotate(5deg); }
        }

        /* Header Section */
        .header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            padding: 0.5rem 2rem;
            transition: all 0.3s ease;
        }

        .header.scrolled {
            background: rgba(255, 255, 255, 0.98);
            box-shadow: var(--shadow-light);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1200px;
            margin: 0 auto;
        }

        .logo {
            display: flex;
            align-items: center;
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-primary);
            text-decoration: none;
        }

        .logo i {
            margin-right: 0.5rem;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 1.8rem;
        }

        .login-section {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .login-form {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: rgba(255, 255, 255, 0.9);
            padding: 0.75rem 1.25rem;
            border-radius: 50px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            transition: all 0.3s ease;
        }

        .login-form:hover {
            border-color: var(--primary-color);
            box-shadow: var(--shadow-light);
        }

        .login-input {
            border: none;
            background: none;
            outline: none;
            padding: 0.5rem;
            font-size: 0.9rem;
            color: var(--text-primary);
            width: 120px;
        }

        .login-input::placeholder {
            color: var(--text-light);
        }

        /* Remove autofill background - comprehensive approach */
        .login-input:-webkit-autofill,
        .login-input:-webkit-autofill:hover,
        .login-input:-webkit-autofill:focus,
        .login-input:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0 1000px rgba(255, 255, 255, 0.1) inset !important;
            -webkit-text-fill-color: var(--text-primary) !important;
            background-color: transparent !important;
            background-image: none !important;
            transition: background-color 5000s ease-in-out 0s !important;
        }

        /* Firefox autofill */
        .login-input:-moz-autofill {
            background-color: transparent !important;
            color: var(--text-primary) !important;
            filter: none !important;
        }

        /* Force transparent background for all browsers */
        .login-input {
            background-color: transparent !important;
            background-image: none !important;
        }

        /* Additional autofill overrides */
        .login-form input:-webkit-autofill,
        .login-form input:-webkit-autofill:hover,
        .login-form input:-webkit-autofill:focus,
        .login-form input:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0 1000px rgba(255, 255, 255, 0.1) inset !important;
            -webkit-text-fill-color: var(--text-primary) !important;
        }

        .login-btn {
            background: var(--primary-gradient);
            color: white;
            border: none;
            padding: 0.6rem 1.2rem;
            border-radius: 25px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        /* Main Content */
        .main-content {
            margin-top: 60px;
            padding: 2rem;
            min-height: calc(100vh - 60px);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .voting-container {
            max-width: 1200px;
            width: 100%;
            text-align: center;
        }

        .hero-section {
            margin-bottom: 4rem;
        }

        .main-title {
            font-size: clamp(3rem, 8vw, 6rem);
            font-weight: 900;
            background: linear-gradient(135deg, #ff6b6b 0%, #feca57 30%, #48dbfb 70%, #0abde3 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 1rem;
            letter-spacing: -0.02em;
            line-height: 1;
            text-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .main-subtitle {
            font-size: clamp(1.2rem, 3vw, 1.8rem);
            color: var(--text-white);
            font-weight: 400;
            margin-bottom: 2rem;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        .vote-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: var(--border-radius);
            padding: 2rem;
            box-shadow: var(--shadow-heavy);
            margin-bottom: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            position: relative;
            overflow: hidden;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }

        .vote-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--vote-gradient);
            border-radius: var(--border-radius) var(--border-radius) 0 0;
        }

        .vote-title {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 1rem;
        }

        .vote-description {
            font-size: 1.2rem;
            color: var(--text-secondary);
            margin-bottom: 2rem;
            line-height: 1.6;
        }

        .student-id-form {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            gap: 1.5rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .student-id-input {
            padding: 0.8rem 1.2rem;
            font-size: 1.6rem;
            border: 2px solid #e0e0e0;
            border-radius: var(--border-radius-small);
            outline: none;
            transition: all 0.3s ease;
            min-width: 250px;
            text-align: center;
            font-weight: 600;
            text-transform: uppercase;
        }

        .student-id-input:focus {
            border-color: var(--vote-color);
            box-shadow: 0 0 0 3px rgba(255, 107, 107, 0.1);
            transform: scale(1.02);
        }

        .vote-btn {
            background: var(--vote-gradient);
            color: white;
            border: none;
            padding: 1.2rem 3rem;
            font-size: 1.3rem;
            font-weight: 700;
            border-radius: var(--border-radius-small);
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
            display: inline-flex;
            align-items: center;
            gap: 1rem;
            box-shadow: 0 8px 30px rgba(255, 107, 107, 0.4);
        }

        .vote-btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
        }

        .vote-btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .vote-btn:hover {
            transform: translateY(-5px) scale(1.05);
            box-shadow: 0 15px 40px rgba(255, 107, 107, 0.6);
        }

        .vote-btn:active {
            transform: translateY(-3px) scale(1.02);
        }

        .vote-btn i {
            font-size: 1.5rem;
            animation: votePulse 2s ease-in-out infinite;
        }

        @keyframes votePulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.2); }
        }

        /* Features Grid */
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2rem;
            margin-bottom: 3rem;
        }

        .feature-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: var(--border-radius);
            padding: 2rem;
            text-align: center;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            cursor: pointer;
        }

        .feature-card:hover {
            transform: translateY(-10px);
            border-color: rgba(255, 255, 255, 0.5);
            box-shadow: var(--shadow-heavy);
        }

        .feature-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 1rem;
            background: var(--success-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: white;
        }

        .feature-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .feature-description {
            color: var(--text-secondary);
            line-height: 1.6;
        }

        /* Election Status */
        .election-status {
            background: linear-gradient(135deg, rgba(0, 212, 170, 0.1), rgba(0, 212, 170, 0.05));
            border: 2px solid var(--success-color);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
        }

        .status-indicator {
            width: 12px;
            height: 12px;
            background: var(--success-color);
            border-radius: 50%;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.2); }
        }

        .status-text {
            font-weight: 600;
            color: var(--success-color);
            font-size: 1.1rem;
        }

        /* Quick Stats */
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-item {
            text-align: center;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: var(--border-radius-small);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 800;
            color: var(--text-white);
            display: block;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        .stat-label {
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.8);
            font-weight: 500;
            margin-top: 0.25rem;
        }

        /* Mobile Responsiveness */
        @media (max-width: 768px) {
            .header {
                padding: 0.75rem 1rem;
                height: auto;
                min-height: 70px;
            }

            .header-content {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch;
            }

            .nav-brand {
                font-size: 1.3rem;
                text-align: center;
            }

            .login-section {
                width: 100%;
                justify-content: center;
            }

            .login-form {
                flex-direction: column;
                gap: 0.75rem;
                width: 100%;
                max-width: 400px;
            }

            .form-group {
                width: 100%;
            }

            .form-control {
                padding: 0.75rem;
                font-size: 1rem;
                border-radius: 8px;
            }

            .btn-login {
                padding: 0.8rem 1.5rem;
                font-size: 1rem;
                width: 100%;
                justify-content: center;
            }

            .main-content {
                margin-top: 80px;
                padding: 1rem;
            }

            .main-title {
                font-size: 2.2rem;
                line-height: 1.3;
                margin-bottom: 1rem;
            }

            .main-subtitle {
                font-size: 1rem;
                margin-bottom: 2rem;
            }

            .vote-section {
                padding: 1.5rem 1rem;
                margin-bottom: 2rem;
                border-radius: 15px;
            }

            .vote-title {
                font-size: 1.3rem;
                margin-bottom: 1rem;
            }

            .student-id-form {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch;
            }

            .student-id-input {
                width: 100%;
                min-width: auto;
                padding: 0.8rem 1rem;
                font-size: 1rem;
                border-radius: 10px;
            }

            .vote-btn {
                width: 100%;
                padding: 1rem 1.5rem;
                font-size: 1.1rem;
                justify-content: center;
                border-radius: 10px;
            }

            .vote-btn i {
                font-size: 1.3rem;
            }

            .features-grid {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }

            .feature-card {
                padding: 1.5rem;
            }

            .feature-icon {
                width: 60px;
                height: 60px;
                font-size: 1.5rem;
                margin-bottom: 1rem;
            }

            .feature-title {
                font-size: 1.1rem;
            }

            .feature-description {
                font-size: 0.95rem;
            }

            .quick-stats {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }

            .stat-card {
                padding: 1rem;
            }

            .stat-value {
                font-size: 1.5rem;
            }

            .stat-label {
                font-size: 0.8rem;
            }
        }

        @media (max-width: 480px) {
            .header {
                padding: 0.6rem 0.8rem;
                min-height: 65px;
            }

            .nav-brand {
                font-size: 1.1rem;
            }

            .main-content {
                margin-top: 70px;
                padding: 0.8rem;
            }

            .main-title {
                font-size: 1.8rem;
                line-height: 1.2;
            }

            .main-subtitle {
                font-size: 0.9rem;
                margin-bottom: 1.5rem;
            }

            .vote-section {
                padding: 1.2rem 0.8rem;
                margin-bottom: 1.5rem;
                border-radius: 12px;
            }

            .vote-title {
                font-size: 1.1rem;
                margin-bottom: 0.8rem;
            }

            .student-id-form {
                gap: 0.8rem;
            }

            .student-id-input {
                padding: 0.7rem 0.8rem;
                font-size: 0.95rem;
                border-radius: 8px;
            }

            .vote-btn {
                padding: 0.8rem 1.2rem;
                font-size: 1rem;
                border-radius: 8px;
            }

            .vote-btn i {
                font-size: 1.1rem;
            }

            .features-grid {
                gap: 1.2rem;
            }

            .feature-card {
                padding: 1.2rem;
                border-radius: 12px;
            }

            .feature-icon {
                width: 50px;
                height: 50px;
                font-size: 1.3rem;
            }

            .feature-title {
                font-size: 1rem;
            }

            .feature-description {
                font-size: 0.9rem;
                line-height: 1.5;
            }

            .quick-stats {
                grid-template-columns: 1fr;
                gap: 0.8rem;
            }

            .stat-card {
                padding: 0.8rem;
                border-radius: 8px;
            }

            .stat-value {
                font-size: 1.3rem;
            }

            .stat-label {
                font-size: 0.75rem;
            }

            /* Touch-friendly adjustments */
            .form-control:focus {
                transform: none;
                box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.3);
            }

            .btn:active {
                transform: scale(0.98);
            }

            /* Prevent zoom on form inputs */
            .form-control {
                font-size: 16px !important;
            }

            .student-id-input {
                font-size: 16px !important;
            }
        }

        /* Extra small screens */
        @media (max-width: 360px) {
            .header {
                padding: 0.5rem;
            }

            .nav-brand {
                font-size: 1rem;
            }

            .main-title {
                font-size: 1.6rem;
            }

            .vote-section {
                padding: 1rem 0.6rem;
            }

            .vote-title {
                font-size: 1rem;
            }

            .student-id-input,
            .vote-btn {
                padding: 0.6rem;
                font-size: 14px;
            }

            .feature-card {
                padding: 1rem;
            }

            .stat-card {
                padding: 0.6rem;
            }
        }

        /* Loading states */
        .loading {
            pointer-events: none;
            opacity: 0.7;
        }

        .loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Keyframe to detect autofill */
        @keyframes autofill {
            to { color: transparent; }
        }

        /* Apply animation to detect autofill */
        .login-input:-webkit-autofill,
        .student-id-input:-webkit-autofill {
            animation-name: autofill;
            animation-fill-mode: both;
        }

        /* Remove autofill background for student input */
        .student-id-input:-webkit-autofill,
        .student-id-input:-webkit-autofill:hover,
        .student-id-input:-webkit-autofill:focus,
        .student-id-input:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0 1000px rgba(255, 255, 255, 0.9) inset !important;
            -webkit-text-fill-color: var(--text-primary) !important;
            background-color: transparent !important;
            background-image: none !important;
            transition: background-color 5000s ease-in-out 0s !important;
        }

        .student-id-input:-moz-autofill {
            background-color: transparent !important;
            color: var(--text-primary) !important;
            filter: none !important;
        }

        /* Success message */
        .success-message {
            background: linear-gradient(135deg, var(--success-color), #10b981);
            color: white;
            padding: 1rem 2rem;
            border-radius: var(--border-radius-small);
            margin-top: 1rem;
            display: none;
            animation: slideIn 0.5s ease;
            text-align: center;
        }

        /* Error message */
        .error-message {
            background: linear-gradient(135deg, var(--danger-color), #ef4444);
            color: white;
            padding: 1rem 2rem;
            border-radius: var(--border-radius-small);
            margin-top: 1rem;
            display: none;
            animation: slideIn 0.5s ease;
            text-align: center;
        }

        .success-message i,
        .error-message i {
            margin-right: 0.5rem;
            font-size: 1.1rem;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <!-- Header with Login -->
    <header class="header" id="header">
        <div class="header-content">
            <a href="#" class="logo">
                <i class="fas fa-vote-yea"></i>
                VoteSystem
            </a>
            
            <div class="login-section">
                <?php if ($auth_error): ?>
                <div class="alert alert-danger alert-sm mb-2" style="font-size: 0.8rem; padding: 0.5rem;">
                    <?= htmlspecialchars($auth_error) ?>
                </div>
                <?php endif; ?>
                <?php if ($auth_success): ?>
                <div class="alert alert-success alert-sm mb-2" style="font-size: 0.8rem; padding: 0.5rem;">
                    <?= htmlspecialchars($auth_success) ?>
                </div>
                <?php endif; ?>
                <form class="login-form" id="staffLoginForm">
                    <i class="fas fa-user"></i>
                    <input type="text" class="login-input" placeholder="Username" id="username" required>
                    <input type="password" class="login-input" placeholder="Password" id="password" required>
                    <button type="submit" class="login-btn">
                        <i class="fas fa-sign-in-alt"></i>
                        Staff Login
                    </button>
                </form>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main-content">
        <div class="voting-container">
            <!-- Hero Section -->
            <div class="hero-section">
                <h1 class="main-title">CLICK TO VOTE</h1>
                <p class="main-subtitle">Your voice matters - Make it count in our secure digital voting system</p>
            </div>


            <!-- Voting Section -->
            <div class="vote-section">
                <form class="student-id-form" id="votingForm" action="javascript:void(0)">
                    <input 
                        type="text" 
                        class="student-id-input" 
                        placeholder="Enter Student Number"
                        id="studentNumber"
                        required
                        autocomplete="off"
                        maxlength="20"
                    >
                    <button type="submit" class="vote-btn" id="voteButton">
                        <i class="fas fa-vote-yea"></i>
                        VOTE NOW
                    </button>
                </form>

                <div class="success-message" id="successMessage" style="display: none;">
                    <i class="fas fa-check-circle"></i>
                    Student verified! Redirecting to voting...
                </div>
                
                <div class="error-message" id="errorMessage" style="display: none;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span id="errorText"></span>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Header scroll effect
        window.addEventListener('scroll', function() {
            const header = document.getElementById('header');
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });

        // Staff login form handling
        document.getElementById('staffLoginForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const username = document.getElementById('username').value;
            const password = document.getElementById('password').value;
            const button = e.target.querySelector('.login-btn');
            
            // Add loading state
            button.classList.add('loading');
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Logging in...';
            
            try {
                // Submit to auth handler
                const response = await fetch('/online_voting/auth/handler.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: `action=login&login=${encodeURIComponent(username)}&password=${encodeURIComponent(password)}`
                });
                
                if (response.ok) {
                    const data = await response.json();
                    if (data.success) {
                        // Successful login, redirect to the specified URL
                        window.location.href = data.redirect;
                        return;
                    } else {
                        throw new Error(data.message || 'Login failed');
                    }
                } else {
                    throw new Error('Login failed');
                }
                
            } catch (error) {
                console.error('Login error:', error);
                
                // Show error message
                const errorDiv = document.createElement('div');
                errorDiv.className = 'alert alert-danger alert-sm mt-2';
                errorDiv.style.fontSize = '0.8rem';
                errorDiv.style.padding = '0.5rem';
                errorDiv.textContent = 'Login failed. Please check your credentials and try again.';
                
                // Remove existing error message if any
                const existingError = button.parentElement.querySelector('.alert-danger');
                if (existingError) {
                    existingError.remove();
                }
                
                // Add error message after button
                button.parentElement.appendChild(errorDiv);
                
                // Remove error message after 5 seconds
                setTimeout(() => {
                    if (errorDiv.parentElement) {
                        errorDiv.remove();
                    }
                }, 5000);
                
                // Reset button
                button.classList.remove('loading');
                button.innerHTML = originalText;
            }
        });

        // Student voting form handling  
        document.getElementById('votingForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            console.log('Form submit event triggered - this should appear in browser console');
            
            const studentNumber = document.getElementById('studentNumber').value.trim();
            const button = document.getElementById('voteButton');
            const successMessage = document.getElementById('successMessage');
            const errorMessage = document.getElementById('errorMessage');
            const errorText = document.getElementById('errorText');
            
            // Hide any existing messages
            successMessage.style.display = 'none';
            errorMessage.style.display = 'none';
            
            if (!studentNumber) {
                showError('Please enter your student number');
                return;
            }
            
            // Add loading state
            button.classList.add('loading');
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';
            
            try {
                // Call API to verify student using public lookup endpoint
                console.log('Making GET request to lookup API for student:', studentNumber);
                const response = await fetch(`/online_voting/api/students/lookup.php?student_number=${encodeURIComponent(studentNumber)}`, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                
                console.log('Response status:', response.status);
                console.log('Response ok:', response.ok);
                
                if (!response.ok) {
                    console.error('Response not ok:', response.statusText);
                    throw new Error('Failed to verify student. Please try again.');
                }
                
                const text = await response.text();
                let data;
                
                try {
                    data = JSON.parse(text);
                } catch (parseError) {
                    console.error('JSON Parse Error:', parseError);
                    console.error('Response text:', text);
                    throw new Error('Server returned invalid response. Please try again.');
                }
                
                if (data.success && data.data) {
                    const student = data.data;
                    
                    // Check if student is verified
                    if (student.verification_status === 'verified') {
                        // Create student session
                        const sessionResponse = await fetch('/online_voting/api/students/session.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({
                                student_number: studentNumber,
                                student_id: student.student_id
                            })
                        });
                        
                        const sessionData = await sessionResponse.json();
                        
                        if (sessionData.success) {
                            // Show success message
                            successMessage.style.display = 'block';
                            
                            // Redirect to voting dashboard after brief delay
                            setTimeout(() => {
                                window.location.href = sessionData.redirect || '/online_voting/student/dashboard.php';
                            }, 1500);
                        } else {
                            throw new Error(sessionData.message || 'Failed to create voting session');
                        }
                    } else {
                        throw new Error('Your student account is not yet verified. Please contact the administration office.');
                    }
                } else {
                    throw new Error('Student number not found. Please check your student number and try again.');
                }
                
            } catch (error) {
                showError(error.message);
                console.error('Verification error:', error);
                
                // Reset button and form
                button.classList.remove('loading');
                button.innerHTML = originalText;
                successMessage.style.display = 'none';
            }
            
            // Helper function to show error messages
            function showError(message) {
                errorText.textContent = message;
                errorMessage.style.display = 'block';
                
                // Auto-hide error after 10 seconds
                setTimeout(() => {
                    errorMessage.style.display = 'none';
                }, 10000);
            }
        });

        // Remove autofill backgrounds with JavaScript
        function removeAutofillBackground() {
            const inputs = document.querySelectorAll('.login-input, .student-id-input');
            inputs.forEach(input => {
                input.addEventListener('animationstart', function(e) {
                    if (e.animationName === 'autofill') {
                        this.style.backgroundColor = 'transparent';
                        this.style.backgroundImage = 'none';
                        this.style.boxShadow = 'none';
                    }
                });
                
                // Force remove background after a delay
                setTimeout(() => {
                    input.style.backgroundColor = 'transparent';
                    input.style.backgroundImage = 'none';
                    input.style.boxShadow = 'none';
                }, 100);
            });
        }

        // Enhanced interactions
        document.addEventListener('DOMContentLoaded', function() {
            // Remove autofill backgrounds
            removeAutofillBackground();
            
            // Re-apply removal periodically to catch late autofills
            setInterval(removeAutofillBackground, 1000);
            // Animate feature cards on scroll
            const observeElements = document.querySelectorAll('.feature-card');
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.animation = 'slideIn 0.6s ease forwards';
                    }
                });
            });

            observeElements.forEach(el => {
                observer.observe(el);
                el.style.opacity = '0';
                el.style.transform = 'translateY(30px)';
            });


            // Add focus effects
            const inputs = document.querySelectorAll('input');
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.style.transform = 'scale(1.02)';
                });
                
                input.addEventListener('blur', function() {
                    this.parentElement.style.transform = 'scale(1)';
                });
            });

            // Auto-uppercase student number input
            const studentNumberInput = document.getElementById('studentNumber');
            if (studentNumberInput) {
                studentNumberInput.addEventListener('input', function() {
                    this.value = this.value.toUpperCase();
                });
            }

        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Alt + L to focus on login
            if (e.altKey && e.key === 'l') {
                e.preventDefault();
                document.getElementById('username').focus();
            }
            
            // Alt + V to focus on student number input
            if (e.altKey && e.key === 'v') {
                e.preventDefault();
                const studentInput = document.getElementById('studentNumber');
                if (studentInput) {
                    studentInput.focus();
                }
            }
        });

        // Add some visual feedback for interactions
        document.querySelectorAll('.feature-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-10px) scale(1.02)';
                this.style.boxShadow = '0 20px 40px rgba(0,0,0,0.15)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
                this.style.boxShadow = '';
            });
        });
    </script>
</body>
</html>