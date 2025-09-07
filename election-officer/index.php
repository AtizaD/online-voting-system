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
.stat-card {
    background: white;
    border-radius: 0.5rem;
    padding: 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border-left: 4px solid var(--primary-color);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.stat-icon {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
    border-radius: 0.75rem;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.25rem;
    margin-bottom: 1rem;
}

.stat-number {
    font-size: 2rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
}

.stat-label {
    color: #64748b;
    font-size: 0.875rem;
    margin: 0;
}

.quick-actions {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.quick-action-btn {
    display: flex;
    align-items: center;
    padding: 1rem 1.5rem;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 0.5rem;
    text-decoration: none;
    color: #334155;
    transition: all 0.2s ease;
}

.quick-action-btn:hover {
    background: #f8fafc;
    border-color: var(--primary-color);
    color: var(--primary-color);
    text-decoration: none;
}

.quick-action-btn i {
    font-size: 1.25rem;
    margin-right: 0.75rem;
    color: var(--primary-color);
}

.activity-card, .pending-card {
    background: white;
    border-radius: 0.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    overflow: hidden;
}

.activity-header, .pending-header {
    padding: 1.5rem;
    border-bottom: 1px solid #f1f5f9;
    background: #f8fafc;
}

.activity-title, .pending-title {
    font-size: 1.125rem;
    font-weight: 600;
    color: #1e293b;
    margin: 0;
}

.activity-list, .pending-list {
    padding: 0;
    margin: 0;
    list-style: none;
}

.activity-item, .pending-item {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.activity-item:last-child, .pending-item:last-child {
    border-bottom: none;
}

.status-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 500;
}

.status-active { background: #dcfce7; color: #166534; }
.status-scheduled { background: #dbeafe; color: #1d4ed8; }
.status-completed { background: #f3e8ff; color: #7c3aed; }
.status-pending { background: #fef3c7; color: #d97706; }
</style>

<!-- Statistics Cards -->
<div class="row mb-4">
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
    <a href="<?= SITE_URL ?>election-officer/elections/manage.php" class="quick-action-btn">
        <i class="fas fa-plus-circle"></i>
        <span>Create Election</span>
    </a>
    <a href="<?= SITE_URL ?>election-officer/candidates/" class="quick-action-btn">
        <i class="fas fa-user-plus"></i>
        <span>Manage Candidates</span>
    </a>
    <a href="<?= SITE_URL ?>election-officer/voting/monitor.php" class="quick-action-btn">
        <i class="fas fa-eye"></i>
        <span>Monitor Voting</span>
    </a>
    <a href="<?= SITE_URL ?>election-officer/results/" class="quick-action-btn">
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
                        <a href="<?= SITE_URL ?>election-officer/elections/manage.php" class="btn btn-primary">
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
                        <a href="<?= SITE_URL ?>election-officer/candidates/" class="btn btn-sm btn-primary w-100">
                            View All Candidates
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Recent Activity Section -->
<div class="row">
    <div class="col-12">
        <div class="activity-card">
            <div class="activity-header">
                <h4 class="activity-title">Election Status Overview</h4>
            </div>
            <div class="activity-content p-3">
                <div class="row text-center">
                    <div class="col-md-3 mb-3">
                        <div class="h3 text-success"><?= $election_stats['active_elections'] ?></div>
                        <div class="small text-muted">Active Elections</div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="h3 text-primary"><?= $election_stats['scheduled_elections'] ?></div>
                        <div class="small text-muted">Scheduled Elections</div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="h3 text-purple"><?= $election_stats['completed_elections'] ?></div>
                        <div class="small text-muted">Completed Elections</div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="h3 text-warning"><?= $student_stats['pending_verification'] ?></div>
                        <div class="small text-muted">Pending Student Verifications</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>