<?php
if (!defined('SITE_URL')) {
    require_once __DIR__ . '/../config/config.php';
}

$current_user = getCurrentUser();
$page_title = $page_title ?? 'Dashboard';
$current_page = basename($_SERVER['PHP_SELF']);
$current_dir = basename(dirname($_SERVER['PHP_SELF']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($page_title) ?> - <?= SITE_NAME ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="<?= SITE_URL ?>/assets/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="<?= SITE_URL ?>/assets/css/font-awesome.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="<?= SITE_URL ?>/assets/css/main.css" rel="stylesheet">
    
    <style>
        :root {
            --sidebar-width: 240px;
            --sidebar-collapsed-width: 0px;
            --header-height: 60px;
            --primary-color: #667eea;
            --secondary-color: #764ba2;
            --sidebar-bg: #2c3e50;
            --sidebar-hover: #34495e;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: #f8fafc;
            margin: 0;
            padding: 0;
        }

        /* Header */
        .main-header {
            position: fixed;
            top: 0;
            left: var(--sidebar-width);
            right: 0;
            height: var(--header-height);
            background: white;
            border-bottom: 1px solid #e2e8f0;
            z-index: 1000;
            display: flex;
            align-items: center;
            padding: 0 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--sidebar-bg);
            z-index: 1001;
            overflow-y: auto;
            transition: all 0.3s ease;
        }

        .sidebar.collapsed {
            transform: translateX(-100%);
        }

        .menu-item {
            display: flex;
            align-items: center;
        }

        /* Initial collapsed state to prevent flash */
        .sidebar-initially-collapsed .sidebar {
            transform: translateX(-100%);
        }

        .sidebar-initially-collapsed .main-content {
            margin-left: 0;
        }

        .sidebar-initially-collapsed .main-header {
            left: 0;
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .sidebar-brand {
            color: white;
            font-size: 1.25rem;
            font-weight: 600;
            text-decoration: none;
            display: flex;
            align-items: center;
            position: relative;
        }

        .sidebar-brand i {
            margin-right: 0.5rem;
            color: var(--primary-color);
        }

        .sidebar-toggle {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: #64748b;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 0.375rem;
            transition: all 0.2s ease;
            margin-right: 1rem;
        }

        .sidebar-toggle:hover {
            background-color: #f1f5f9;
            color: var(--primary-color);
        }

        .menu-item {
            display: flex;
            align-items: center;
        }

        .menu-item span {
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }

        .sidebar-menu {
            padding: 1rem 0;
        }

        .menu-section {
            margin-bottom: 2rem;
        }

        .menu-title {
            color: #94a3b8;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 0 1.5rem 0.5rem;
        }

        .menu-item {
            display: block;
            color: #cbd5e1;
            text-decoration: none;
            padding: 0.75rem 1.5rem;
            border-left: 3px solid transparent;
            transition: all 0.2s ease;
            position: relative;
        }

        .menu-item:hover {
            background: var(--sidebar-hover);
            color: white;
            border-left-color: var(--primary-color);
        }

        .menu-item.active {
            background: var(--sidebar-hover);
            color: white;
            border-left-color: var(--primary-color);
        }

        .menu-item i {
            width: 20px;
            margin-right: 0.75rem;
        }

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            margin-top: var(--header-height);
            padding: 2rem;
            min-height: calc(100vh - var(--header-height));
            transition: margin-left 0.3s ease;
        }

        .main-content.sidebar-collapsed {
            margin-left: 0;
        }

        .main-header.sidebar-collapsed {
            left: 0;
        }

        /* User dropdown */
        .user-dropdown {
            margin-left: auto;
        }

        .user-info {
            display: flex;
            align-items: center;
            color: #64748b;
            cursor: pointer;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            transition: background-color 0.2s;
        }

        .user-info:hover {
            background-color: #f1f5f9;
        }

        .user-avatar {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            margin-right: 0.5rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-header {
                left: 0;
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .mobile-menu-toggle {
                display: block !important;
            }
        }

        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.25rem;
            color: #64748b;
            cursor: pointer;
        }

        /* Hide sidebar toggle on mobile */
        @media (max-width: 768px) {
            .sidebar-toggle {
                display: none !important;
            }
        }

        /* Page header */
        .page-header {
            background: white;
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #1e293b;
            margin: 0;
        }

        .breadcrumb {
            margin: 0;
            background: transparent;
            padding: 0;
            font-size: 0.875rem;
        }

        .breadcrumb-item a {
            color: #64748b;
            text-decoration: none;
        }

        .breadcrumb-item.active {
            color: #94a3b8;
        }
    </style>
</head>
<body>

<script>
    // Apply collapsed state immediately to prevent flash
    (function() {
        const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        if (isCollapsed) {
            document.documentElement.style.setProperty('--initial-sidebar-width', 'var(--sidebar-collapsed-width)');
            document.documentElement.classList.add('sidebar-initially-collapsed');
        }
    })();
</script>

<!-- Sidebar -->
<nav class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <a href="<?= SITE_URL ?>" class="sidebar-brand">
            <i class="fas fa-vote-yea"></i>
            <span><?= SITE_NAME ?></span>
        </a>
    </div>
    
    <div class="sidebar-menu">
        <?php if ($current_user && $current_user['role'] === 'admin'): ?>
        <!-- Admin Menu -->
        <div class="menu-section">
            <div class="menu-title">Main</div>
            <a href="<?= SITE_URL ?>/admin/" class="menu-item <?= ($current_dir === 'admin' && $current_page === 'index.php') ? 'active' : '' ?>">
                <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
            </a>
            <a href="<?= SITE_URL ?>/admin/profile.php" class="menu-item <?= ($current_page === 'profile.php') ? 'active' : '' ?>">
                <i class="fas fa-user-cog"></i> <span>Profile</span>
            </a>
        </div>

        <div class="menu-section">
            <div class="menu-title">Elections</div>
            <a href="<?= SITE_URL ?>/admin/elections/" class="menu-item <?= ($current_dir === 'elections') ? 'active' : '' ?>">
                <i class="fas fa-poll"></i> <span>Elections</span>
            </a>
            <a href="<?= SITE_URL ?>/admin/positions/" class="menu-item <?= ($current_dir === 'positions') ? 'active' : '' ?>">
                <i class="fas fa-list"></i> <span>Positions</span>
            </a>
            <a href="<?= SITE_URL ?>/admin/candidates/" class="menu-item <?= ($current_dir === 'candidates') ? 'active' : '' ?>">
                <i class="fas fa-users"></i> <span>Candidates</span>
            </a>
        </div>

        <div class="menu-section">
            <div class="menu-title">Voting</div>
            <a href="<?= SITE_URL ?>/admin/voting/" class="menu-item <?= ($current_dir === 'voting') ? 'active' : '' ?>">
                <i class="fas fa-vote-yea"></i> <span>Monitor Voting</span>
            </a>
            <a href="<?= SITE_URL ?>/admin/results/" class="menu-item <?= ($current_dir === 'results') ? 'active' : '' ?>">
                <i class="fas fa-chart-bar"></i> <span>Results</span>
            </a>
        </div>

        <div class="menu-section">
            <div class="menu-title">Management</div>
            <a href="<?= SITE_URL ?>/admin/students/" class="menu-item <?= ($current_dir === 'students') ? 'active' : '' ?>">
                <i class="fas fa-graduation-cap"></i> <span>Students</span>
            </a>
            <a href="<?= SITE_URL ?>/admin/classes/" class="menu-item <?= ($current_dir === 'classes') ? 'active' : '' ?>">
                <i class="fas fa-chalkboard-teacher"></i> <span>Classes</span>
            </a>
            <a href="<?= SITE_URL ?>/admin/users/" class="menu-item <?= ($current_dir === 'users') ? 'active' : '' ?>">
                <i class="fas fa-users-cog"></i> <span>Users</span>
            </a>
        </div>

        <div class="menu-section">
            <div class="menu-title">Reports</div>
            <a href="<?= SITE_URL ?>/admin/reports/" class="menu-item <?= ($current_dir === 'reports' && $current_page === 'index.php') ? 'active' : '' ?>">
                <i class="fas fa-chart-line"></i> <span>Analytics</span>
            </a>
            <a href="<?= SITE_URL ?>/admin/reports/audit-logs.php" class="menu-item <?= ($current_dir === 'reports' && $current_page === 'audit-logs.php') ? 'active' : '' ?>">
                <i class="fas fa-file-alt"></i> <span>Audit Logs</span>
            </a>
        </div>

        <div class="menu-section">
            <div class="menu-title">System</div>
            <a href="<?= SITE_URL ?>/admin/settings/" class="menu-item <?= ($current_dir === 'settings') ? 'active' : '' ?>">
                <i class="fas fa-cog"></i> <span>Settings</span>
            </a>
        </div>
        
        <?php elseif ($current_user && $current_user['role'] === 'election_officer'): ?>
        <!-- Election Officer Menu -->
        <div class="menu-section">
            <div class="menu-title">Main</div>
            <a href="<?= SITE_URL ?>/election-officer/" class="menu-item <?= ($current_dir === 'election-officer' && $current_page === 'index.php') ? 'active' : '' ?>">
                <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
            </a>
            <a href="<?= SITE_URL ?>/election-officer/profile.php" class="menu-item <?= ($current_page === 'profile.php') ? 'active' : '' ?>">
                <i class="fas fa-user-cog"></i> <span>Profile</span>
            </a>
        </div>

        <div class="menu-section">
            <div class="menu-title">Elections</div>
            <a href="<?= SITE_URL ?>/election-officer/elections/" class="menu-item <?= ($current_dir === 'elections') ? 'active' : '' ?>">
                <i class="fas fa-poll"></i> <span>Elections</span>
            </a>
            <a href="<?= SITE_URL ?>/election-officer/candidates/" class="menu-item <?= ($current_dir === 'candidates') ? 'active' : '' ?>">
                <i class="fas fa-users"></i> <span>Candidates</span>
            </a>
        </div>

        <div class="menu-section">
            <div class="menu-title">Voting</div>
            <a href="<?= SITE_URL ?>/election-officer/voting/monitor.php" class="menu-item <?= ($current_dir === 'voting') ? 'active' : '' ?>">
                <i class="fas fa-eye"></i> <span>Monitor Voting</span>
            </a>
            <a href="<?= SITE_URL ?>/election-officer/results/" class="menu-item <?= ($current_dir === 'results') ? 'active' : '' ?>">
                <i class="fas fa-chart-bar"></i> <span>Results</span>
            </a>
        </div>

        <div class="menu-section">
            <div class="menu-title">Students</div>
            <a href="<?= SITE_URL ?>/election-officer/students/verify.php" class="menu-item <?= ($current_dir === 'students') ? 'active' : '' ?>">
                <i class="fas fa-user-check"></i> <span>Verify Students</span>
            </a>
        </div>
        
        <?php elseif ($current_user && $current_user['role'] === 'staff'): ?>
        <!-- Staff Menu -->
        <div class="menu-section">
            <div class="menu-title">Main</div>
            <a href="<?= SITE_URL ?>/staff/" class="menu-item <?= ($current_page === 'index.php' && $current_dir === 'staff') ? 'active' : '' ?>">
                <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
            </a>
        </div>
        
        <div class="menu-section">
            <div class="menu-title">Students</div>
            <a href="<?= SITE_URL ?>/staff/students/" class="menu-item <?= ($current_dir === 'students' && $current_page === 'index.php') ? 'active' : '' ?>">
                <i class="fas fa-users"></i> <span>Student Overview</span>
            </a>
            <a href="<?= SITE_URL ?>/staff/students/manage.php" class="menu-item <?= ($current_dir === 'students' && $current_page === 'manage.php') ? 'active' : '' ?>">
                <i class="fas fa-user-graduate"></i> <span>Manage Students</span>
            </a>
            <a href="<?= SITE_URL ?>/staff/students/verify.php" class="menu-item <?= ($current_dir === 'students' && $current_page === 'verify.php') ? 'active' : '' ?>">
                <i class="fas fa-user-check"></i> <span>Verify Students</span>
            </a>
        </div>
        
        <div class="menu-section">
            <div class="menu-title">Reports</div>
            <a href="<?= SITE_URL ?>/staff/reports/" class="menu-item <?= ($current_dir === 'reports' && $current_page === 'index.php') ? 'active' : '' ?>">
                <i class="fas fa-chart-bar"></i> <span>Reports Dashboard</span>
            </a>
            <a href="<?= SITE_URL ?>/staff/reports/students.php" class="menu-item <?= ($current_dir === 'reports' && $current_page === 'students.php') ? 'active' : '' ?>">
                <i class="fas fa-user-graduate"></i> <span>Student Reports</span>
            </a>
            <a href="<?= SITE_URL ?>/staff/reports/verification.php" class="menu-item <?= ($current_dir === 'reports' && $current_page === 'verification.php') ? 'active' : '' ?>">
                <i class="fas fa-clipboard-check"></i> <span>Verification Reports</span>
            </a>
        </div>
        
        <div class="menu-section">
            <div class="menu-title">Voting Support</div>
            <a href="<?= SITE_URL ?>/staff/voting/assist.php" class="menu-item <?= ($current_dir === 'voting' && $current_page === 'assist.php') ? 'active' : '' ?>">
                <i class="fas fa-hands-helping"></i> <span>Assist Students</span>
            </a>
            <a href="<?= SITE_URL ?>/staff/voting/issues.php" class="menu-item <?= ($current_dir === 'voting' && $current_page === 'issues.php') ? 'active' : '' ?>">
                <i class="fas fa-exclamation-triangle"></i> <span>Report Issues</span>
            </a>
        </div>
        
        <?php elseif ($current_user && $current_user['role'] === 'student'): ?>
        <!-- Student Menu -->
        <div class="menu-section">
            <div class="menu-title">Voting</div>
            <a href="<?= SITE_URL ?>/student/" class="menu-item">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            <a href="<?= SITE_URL ?>/student/vote.php" class="menu-item">
                <i class="fas fa-vote-yea"></i> Vote
            </a>
            <a href="<?= SITE_URL ?>/student/results.php" class="menu-item">
                <i class="fas fa-chart-bar"></i> Results
            </a>
        </div>
        <?php endif; ?>
    </div>
</nav>

<!-- Main Header -->
<header class="main-header">
    <button class="sidebar-toggle" onclick="toggleSidebarCollapse()" title="Toggle Sidebar">
        <i class="fas fa-bars"></i>
    </button>
    <button class="mobile-menu-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>
    
    <div class="user-dropdown">
        <?php if ($current_user): ?>
        <div class="dropdown">
            <div class="user-info" data-bs-toggle="dropdown">
                <div class="user-avatar">
                    <?= substr($current_user['first_name'], 0, 1) . substr($current_user['last_name'], 0, 1) ?>
                </div>
                <div>
                    <div style="font-weight: 500;"><?= sanitize($current_user['first_name'] . ' ' . $current_user['last_name']) ?></div>
                    <div style="font-size: 0.75rem; color: #94a3b8;"><?= ucfirst($current_user['role']) ?></div>
                </div>
                <i class="fas fa-chevron-down" style="margin-left: 0.5rem; font-size: 0.75rem;"></i>
            </div>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="<?= SITE_URL ?>/admin/profile"><i class="fas fa-user me-2"></i>Profile</a></li>
                <li><a class="dropdown-item" href="<?= SITE_URL ?>/admin/settings"><i class="fas fa-cog me-2"></i>Settings</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= SITE_URL ?>/auth/logout"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</header>

<!-- Main Content -->
<main class="main-content" id="main-content">
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title"><?= sanitize($page_title) ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= SITE_URL ?>">Home</a></li>
                <?php if (isset($breadcrumbs) && is_array($breadcrumbs)): ?>
                    <?php foreach ($breadcrumbs as $crumb): ?>
                        <?php if (isset($crumb['url'])): ?>
                            <li class="breadcrumb-item"><a href="<?= sanitize($crumb['url']) ?>"><?= sanitize($crumb['title']) ?></a></li>
                        <?php else: ?>
                            <li class="breadcrumb-item active"><?= sanitize($crumb['title']) ?></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li class="breadcrumb-item active"><?= sanitize($page_title) ?></li>
                <?php endif; ?>
            </ol>
        </nav>
    </div>