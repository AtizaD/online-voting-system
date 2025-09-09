<?php
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth(['election_officer']);

$page_title = 'Election Officer Dashboard';
$breadcrumbs = [];

$db = Database::getInstance()->getConnection();
$user = getCurrentUser();

// Get election statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_elections,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_elections,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as scheduled_elections,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_elections
    FROM elections
");
$stmt->execute();
$election_stats = $stmt->fetch();

// Get candidate statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_candidates,
        COUNT(*) as approved_candidates,
        0 as pending_candidates
    FROM candidates c
    JOIN elections e ON c.election_id = e.election_id
");
$stmt->execute();
$candidate_stats = $stmt->fetch();

// Get recent elections
$stmt = $db->prepare("
    SELECT 
        e.election_id,
        e.name as title,
        e.description,
        e.start_date,
        e.end_date,
        e.status,
        COUNT(c.candidate_id) as candidate_count,
        COUNT(v.vote_id) as vote_count
    FROM elections e
    LEFT JOIN candidates c ON e.election_id = c.election_id
    LEFT JOIN votes v ON e.election_id = v.election_id
    GROUP BY e.election_id
    ORDER BY e.created_at DESC
    LIMIT 5
");
$stmt->execute();
$recent_elections = $stmt->fetchAll();

// Get recent candidate registrations (since no approval system exists)
$stmt = $db->prepare("
    SELECT 
        c.candidate_id,
        CONCAT(s.first_name, ' ', s.last_name) as candidate_name,
        p.title as position_name,
        e.name as election_title,
        c.created_at
    FROM candidates c
    JOIN students s ON c.student_id = s.student_id
    JOIN positions p ON c.position_id = p.position_id
    JOIN elections e ON c.election_id = e.election_id
    ORDER BY c.created_at DESC
    LIMIT 5
");
$stmt->execute();
$pending_candidates = $stmt->fetchAll();

// Get verification statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_students,
        SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END) as verified_students,
        SUM(CASE WHEN is_verified = 0 THEN 1 ELSE 0 END) as pending_verification
    FROM students 
    WHERE is_active = 1
");
$stmt->execute();
$student_stats = $stmt->fetch();

include __DIR__ . '/../includes/header.php';
?>

<style>
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translate3d(0, 30px, 0);
    }
    to {
        opacity: 1;
        transform: translate3d(0, 0, 0);
    }
}

@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.05); }
    100% { transform: scale(1); }
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateX(-20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

.dashboard-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2rem 0;
    margin: -20px -20px 2rem -20px;
    border-radius: 0 0 15px 15px;
    position: relative;
    overflow: hidden;
}

.dashboard-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="%23ffffff" opacity="0.1"/><circle cx="75" cy="75" r="1" fill="%23ffffff" opacity="0.1"/><circle cx="50" cy="10" r="1" fill="%23ffffff" opacity="0.05"/><circle cx="10" cy="50" r="1" fill="%23ffffff" opacity="0.05"/><circle cx="90" cy="30" r="1" fill="%23ffffff" opacity="0.05"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
    opacity: 0.3;
}

.dashboard-welcome {
    position: relative;
    z-index: 1;
}

.welcome-title {
    font-size: 1.8rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
    animation: fadeInUp 0.6s ease-out;
}

.welcome-subtitle {
    font-size: 1rem;
    opacity: 0.9;
    margin: 0;
    animation: fadeInUp 0.6s ease-out 0.2s both;
}

.stat-card {
    background: white;
    border-radius: 15px;
    padding: 1.5rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    border: 1px solid rgba(255,255,255,0.8);
    position: relative;
    overflow: hidden;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    animation: fadeInUp 0.6s ease-out;
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #667eea, #764ba2, #f093fb, #f5576c);
    background-size: 300% 300%;
    animation: gradientFlow 3s ease infinite;
}

@keyframes gradientFlow {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

.stat-card:hover {
    transform: translateY(-8px) scale(1.02);
    box-shadow: 0 12px 40px rgba(102, 126, 234, 0.15);
}

.stat-card:nth-child(1) { animation-delay: 0.1s; }
.stat-card:nth-child(2) { animation-delay: 0.2s; }
.stat-card:nth-child(3) { animation-delay: 0.3s; }
.stat-card:nth-child(4) { animation-delay: 0.4s; }

.stat-icon {
    width: 56px;
    height: 56px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.5rem;
    margin-bottom: 1rem;
    position: relative;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
}

.stat-card:hover .stat-icon {
    animation: pulse 0.6s ease-in-out;
    box-shadow: 0 6px 25px rgba(102, 126, 234, 0.4);
}

.stat-number {
    font-size: 2.5rem;
    font-weight: 800;
    background: linear-gradient(135deg, #667eea, #764ba2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    margin: 0;
    line-height: 1;
}

.stat-label {
    color: #64748b;
    font-size: 0.875rem;
    font-weight: 500;
    margin: 0.5rem 0 0 0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.quick-actions {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.quick-action-btn {
    display: flex;
    align-items: center;
    padding: 1.25rem 1.75rem;
    background: white;
    border: 2px solid transparent;
    border-radius: 12px;
    text-decoration: none;
    color: #334155;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    animation: slideIn 0.6s ease-out;
}

.quick-action-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(102, 126, 234, 0.1), transparent);
    transition: left 0.6s;
}

.quick-action-btn:hover::before {
    left: 100%;
}

.quick-action-btn:hover {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    text-decoration: none;
    transform: translateY(-4px);
    box-shadow: 0 8px 30px rgba(102, 126, 234, 0.3);
}

.quick-action-btn:hover i {
    color: white;
    animation: pulse 0.6s ease-in-out;
}

.quick-action-btn i {
    font-size: 1.4rem;
    margin-right: 1rem;
    background: linear-gradient(135deg, #667eea, #764ba2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    transition: all 0.3s ease;
}

.quick-action-btn span {
    font-weight: 600;
    font-size: 0.95rem;
}

.activity-card, .pending-card {
    background: white;
    border-radius: 15px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    overflow: hidden;
    border: 1px solid rgba(255,255,255,0.8);
    transition: all 0.3s ease;
    animation: fadeInUp 0.6s ease-out 0.3s both;
}

.activity-card:hover, .pending-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 30px rgba(0,0,0,0.12);
}

.activity-header, .pending-header {
    padding: 1.75rem;
    border-bottom: 1px solid #f1f5f9;
    background: linear-gradient(135deg, #f8fafc, #ffffff);
    position: relative;
}

.activity-header::after, .pending-header::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: linear-gradient(90deg, #667eea, #764ba2);
    opacity: 0.6;
}

.activity-title, .pending-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
    position: relative;
}

.activity-list, .pending-list {
    padding: 0;
    margin: 0;
    list-style: none;
}

.activity-item, .pending-item {
    padding: 1.25rem 1.75rem;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: all 0.3s ease;
    position: relative;
}

.activity-item:hover, .pending-item:hover {
    background: linear-gradient(135deg, #f8fafc, #ffffff);
    transform: translateX(4px);
}

.activity-item::before, .pending-item::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.activity-item:hover::before, .pending-item:hover::before {
    opacity: 1;
}

.activity-item:last-child, .pending-item:last-child {
    border-bottom: none;
}

.status-badge {
    padding: 0.35rem 0.85rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    position: relative;
    overflow: hidden;
    transition: all 0.3s ease;
}

.status-badge::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
    transition: left 0.6s;
}

.status-badge:hover::before {
    left: 100%;
}

.status-active { 
    background: linear-gradient(135deg, #dcfce7, #bbf7d0); 
    color: #166534; 
    box-shadow: 0 2px 8px rgba(22, 101, 52, 0.2);
}

.status-scheduled { 
    background: linear-gradient(135deg, #dbeafe, #bfdbfe); 
    color: #1d4ed8; 
    box-shadow: 0 2px 8px rgba(29, 78, 216, 0.2);
}

.status-completed { 
    background: linear-gradient(135deg, #f3e8ff, #e9d5ff); 
    color: #7c3aed; 
    box-shadow: 0 2px 8px rgba(124, 58, 237, 0.2);
}

.status-pending { 
    background: linear-gradient(135deg, #fef3c7, #fed7aa); 
    color: #d97706; 
    box-shadow: 0 2px 8px rgba(217, 119, 6, 0.2);
}

.overview-stats {
    background: white;
    border-radius: 15px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    animation: fadeInUp 0.6s ease-out 0.4s both;
}

.overview-header {
    padding: 1.75rem;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    position: relative;
}

.overview-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="dots" width="20" height="20" patternUnits="userSpaceOnUse"><circle cx="10" cy="10" r="1" fill="%23ffffff" opacity="0.2"/></pattern></defs><rect width="100" height="100" fill="url(%23dots)"/></svg>');
}

.overview-title {
    font-size: 1.2rem;
    font-weight: 700;
    margin: 0;
    position: relative;
    z-index: 1;
}

.overview-content {
    padding: 2rem 1.75rem;
}

.overview-stat {
    text-align: center;
    transition: all 0.3s ease;
    cursor: pointer;
}

.overview-stat:hover {
    transform: translateY(-4px);
}

.overview-stat .h3 {
    font-size: 2.2rem;
    font-weight: 800;
    margin-bottom: 0.5rem;
    transition: all 0.3s ease;
}

.overview-stat:hover .h3 {
    transform: scale(1.1);
}

.text-success { color: #10b981 !important; }
.text-primary { color: #3b82f6 !important; }
.text-purple { color: #8b5cf6 !important; }
.text-warning { color: #f59e0b !important; }

.fade-in {
    animation: fadeInUp 0.6s ease-out both;
}

.delay-1 { animation-delay: 0.1s; }
.delay-2 { animation-delay: 0.2s; }
.delay-3 { animation-delay: 0.3s; }
.delay-4 { animation-delay: 0.4s; }
</style>

<!-- Dashboard Header -->
<div class="dashboard-header">
    <div class="container">
        <div class="dashboard-welcome text-center">
            <h1 class="welcome-title">
                <i class="fas fa-chart-line me-3"></i>
                Welcome back, <?= sanitize($user['first_name'] ?? 'Election Officer') ?>!
            </h1>
            <p class="welcome-subtitle">
                Manage elections, monitor voting progress, and oversee the democratic process
            </p>
        </div>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4 fade-in">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-poll"></i>
            </div>
            <h3 class="stat-number"><?= number_format($election_stats['total_elections']) ?></h3>
            <p class="stat-label">Total Elections</p>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-users"></i>
            </div>
            <h3 class="stat-number"><?= number_format($candidate_stats['total_candidates']) ?></h3>
            <p class="stat-label">Total Candidates</p>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-user-check"></i>
            </div>
            <h3 class="stat-number"><?= number_format($student_stats['verified_students']) ?></h3>
            <p class="stat-label">Verified Students</p>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h3 class="stat-number"><?= number_format($candidate_stats['pending_candidates']) ?></h3>
            <p class="stat-label">Pending Approvals</p>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="quick-actions mb-4">
    <a href="<?= SITE_URL ?>/election-officer/elections/manage.php" class="quick-action-btn fade-in delay-1">
        <i class="fas fa-plus-circle"></i>
        <span>Create Election</span>
    </a>
    <a href="<?= SITE_URL ?>/election-officer/candidates/" class="quick-action-btn fade-in delay-2">
        <i class="fas fa-user-plus"></i>
        <span>Manage Candidates</span>
    </a>
    <a href="<?= SITE_URL ?>/election-officer/voting/monitor.php" class="quick-action-btn fade-in delay-3">
        <i class="fas fa-eye"></i>
        <span>Monitor Voting</span>
    </a>
    <a href="<?= SITE_URL ?>/election-officer/results/" class="quick-action-btn fade-in delay-4">
        <i class="fas fa-chart-bar"></i>
        <span>View Results</span>
    </a>
</div>

<!-- Main Content -->
<div class="row">
    <!-- Recent Elections -->
    <div class="col-lg-8 mb-4">
        <div class="activity-card">
            <div class="activity-header">
                <h4 class="activity-title">Recent Elections</h4>
            </div>
            <div class="activity-content">
                <?php if (empty($recent_elections)): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-poll fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No elections found. Create your first election to get started.</p>
                        <a href="<?= SITE_URL ?>/election-officer/elections/manage.php" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i>Create Election
                        </a>
                    </div>
                <?php else: ?>
                    <ul class="activity-list">
                        <?php foreach ($recent_elections as $election): ?>
                            <li class="activity-item">
                                <div>
                                    <strong><?= sanitize($election['title']) ?></strong>
                                    <div class="small text-muted">
                                        <?= date('M j, Y', strtotime($election['start_date'])) ?>
                                        - <?= date('M j, Y', strtotime($election['end_date'])) ?>
                                    </div>
                                    <div class="small">
                                        <?= $election['candidate_count'] ?> candidates, 
                                        <?= $election['vote_count'] ?> votes
                                    </div>
                                </div>
                                <div>
                                    <span class="status-badge status-<?= $election['status'] ?>">
                                        <?= ucfirst($election['status']) ?>
                                    </span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Pending Approvals -->
    <div class="col-lg-4 mb-4">
        <div class="pending-card">
            <div class="pending-header">
                <h4 class="pending-title">Pending Candidate Approvals</h4>
            </div>
            <div class="pending-content">
                <?php if (empty($pending_candidates)): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-check-circle fa-2x text-success mb-3"></i>
                        <p class="text-muted">No pending approvals.</p>
                    </div>
                <?php else: ?>
                    <ul class="pending-list">
                        <?php foreach ($pending_candidates as $candidate): ?>
                            <li class="pending-item">
                                <div>
                                    <strong><?= sanitize($candidate['candidate_name']) ?></strong>
                                    <div class="small text-muted">
                                        <?= sanitize($candidate['position_name']) ?>
                                    </div>
                                    <div class="small text-muted">
                                        <?= sanitize($candidate['election_title']) ?>
                                    </div>
                                </div>
                                <div>
                                    <span class="status-badge status-pending">Pending</span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="p-3 border-top">
                        <a href="<?= SITE_URL ?>/election-officer/candidates/" class="btn btn-sm btn-primary w-100">
                            View All Candidates
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Election Status Overview -->
<div class="row">
    <div class="col-12">
        <div class="overview-stats">
            <div class="overview-header">
                <h4 class="overview-title">
                    <i class="fas fa-chart-pie me-2"></i>
                    Election Status Overview
                </h4>
            </div>
            <div class="overview-content">
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <div class="overview-stat fade-in delay-1">
                            <div class="h3 text-success"><?= $election_stats['active_elections'] ?></div>
                            <div class="small text-muted">
                                <i class="fas fa-play-circle me-1"></i>
                                Active Elections
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="overview-stat fade-in delay-2">
                            <div class="h3 text-primary"><?= $election_stats['scheduled_elections'] ?></div>
                            <div class="small text-muted">
                                <i class="fas fa-clock me-1"></i>
                                Scheduled Elections
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="overview-stat fade-in delay-3">
                            <div class="h3 text-purple"><?= $election_stats['completed_elections'] ?></div>
                            <div class="small text-muted">
                                <i class="fas fa-check-circle me-1"></i>
                                Completed Elections
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="overview-stat fade-in delay-4">
                            <div class="h3 text-warning"><?= $student_stats['pending_verification'] ?></div>
                            <div class="small text-muted">
                                <i class="fas fa-hourglass-half me-1"></i>
                                Pending Verifications
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Live Status Indicators -->
<div class="row mt-4">
    <div class="col-12">
        <div class="activity-card fade-in">
            <div class="activity-header">
                <h4 class="activity-title">
                    <i class="fas fa-wifi me-2"></i>
                    System Status
                    <span class="badge bg-success ms-2">
                        <i class="fas fa-circle me-1" style="font-size: 0.5rem;"></i>
                        All Systems Operational
                    </span>
                </h4>
            </div>
            <div class="activity-content p-3">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <div class="d-flex align-items-center">
                            <div class="me-3">
                                <div class="bg-success rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                    <i class="fas fa-database text-white"></i>
                                </div>
                            </div>
                            <div>
                                <div class="fw-bold">Database</div>
                                <div class="small text-success">Connected</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="d-flex align-items-center">
                            <div class="me-3">
                                <div class="bg-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                    <i class="fas fa-users text-white"></i>
                                </div>
                            </div>
                            <div>
                                <div class="fw-bold">Active Users</div>
                                <div class="small text-primary">Online Now</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="d-flex align-items-center">
                            <div class="me-3">
                                <div class="bg-info rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                    <i class="fas fa-shield-alt text-white"></i>
                                </div>
                            </div>
                            <div>
                                <div class="fw-bold">Security</div>
                                <div class="small text-info">Protected</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>