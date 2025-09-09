<?php
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth(['election_officer']);

$page_title = 'Candidates Management';
$breadcrumbs = [
    ['title' => 'Election Officer', 'url' => SITE_URL . 'election-officer/'],
    ['title' => 'Candidates']
];

$db = Database::getInstance()->getConnection();

// Handle pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 12;
$offset = ($page - 1) * $per_page;

// Handle filters
$election_filter = $_GET['election'] ?? '';
$position_filter = $_GET['position'] ?? '';
$status_filter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

// Build WHERE clause
$where_conditions = [];
$params = [];

if ($election_filter) {
    $where_conditions[] = 'c.election_id = ?';
    $params[] = $election_filter;
}

if ($position_filter) {
    $where_conditions[] = 'c.position_id = ?';
    $params[] = $position_filter;
}

// Note: approval_status doesn't exist in schema, removing this filter for now

if ($search) {
    $where_conditions[] = '(CONCAT(s.first_name, " ", s.last_name) LIKE ? OR s.student_id LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count
$count_sql = "
    SELECT COUNT(*) 
    FROM candidates c
    JOIN students s ON c.student_id = s.student_id
    JOIN elections e ON c.election_id = e.election_id
    JOIN positions p ON c.position_id = p.position_id
    $where_clause
";
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_records = $stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

// Get candidates
$sql = "
    SELECT 
        c.*,
        CONCAT(s.first_name, ' ', s.last_name) as candidate_name,
        s.student_number as student_number,
        prog.program_name as program,
        cl.class_name as class,
        s.gender,
        '' as student_email,
        e.name as election_title,
        e.status as election_status,
        p.title as position_name,
        COUNT(v.vote_id) as vote_count
    FROM candidates c
    JOIN students s ON c.student_id = s.student_id
    JOIN programs prog ON s.program_id = prog.program_id
    JOIN classes cl ON s.class_id = cl.class_id
    JOIN elections e ON c.election_id = e.election_id
    JOIN positions p ON c.position_id = p.position_id
    LEFT JOIN votes v ON c.candidate_id = v.candidate_id
    $where_clause
    GROUP BY c.candidate_id
    ORDER BY c.created_at DESC
    LIMIT ? OFFSET ?
";

$params[] = $per_page;
$params[] = $offset;
$stmt = $db->prepare($sql);
$stmt->execute($params);
$candidates = $stmt->fetchAll();

// Get elections for filter
$stmt = $db->prepare("SELECT election_id, name as title FROM elections ORDER BY name");
$stmt->execute();
$elections = $stmt->fetchAll();

// Get positions for filter (based on selected election)
$positions = [];
if ($election_filter) {
    $stmt = $db->prepare("SELECT position_id, title as position_name FROM positions WHERE election_id = ? AND is_active = 1 ORDER BY title");
    $stmt->execute([$election_filter]);
    $positions = $stmt->fetchAll();
}

// Get candidate statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total,
        0 as pending,
        COUNT(*) as approved,
        0 as rejected
    FROM candidates c
    JOIN elections e ON c.election_id = e.election_id
");
$stmt->execute();
$stats = $stmt->fetch();

include __DIR__ . '/../../includes/header.php';
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

@keyframes gradientFlow {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

@keyframes bounceIn {
    0% {
        opacity: 0;
        transform: scale(0.3);
    }
    50% {
        opacity: 1;
        transform: scale(1.05);
    }
    70% {
        transform: scale(0.9);
    }
    100% {
        opacity: 1;
        transform: scale(1);
    }
}

.page-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2rem 0;
    margin: -20px -20px 2rem -20px;
    border-radius: 0 0 15px 15px;
    position: relative;
    overflow: hidden;
}

.page-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="candidates-pattern" width="20" height="20" patternUnits="userSpaceOnUse"><circle cx="10" cy="10" r="1.5" fill="%23ffffff" opacity="0.3"/><circle cx="5" cy="15" r="1" fill="%23ffffff" opacity="0.2"/><circle cx="15" cy="5" r="1" fill="%23ffffff" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23candidates-pattern)"/></svg>');
}

.page-title {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
    position: relative;
    z-index: 1;
    animation: fadeInUp 0.6s ease-out;
}

.page-subtitle {
    font-size: 1rem;
    opacity: 0.9;
    margin: 0;
    position: relative;
    z-index: 1;
    animation: fadeInUp 0.6s ease-out 0.2s both;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: white;
    border-radius: 15px;
    padding: 1.75rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    text-align: center;
    position: relative;
    overflow: hidden;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    animation: fadeInUp 0.6s ease-out;
    border: 1px solid rgba(255,255,255,0.8);
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

.stat-card:hover {
    transform: translateY(-8px) scale(1.02);
    box-shadow: 0 12px 40px rgba(102, 126, 234, 0.15);
}

.stat-card:nth-child(1) { animation-delay: 0.1s; }
.stat-card:nth-child(2) { animation-delay: 0.2s; }
.stat-card:nth-child(3) { animation-delay: 0.3s; }
.stat-card:nth-child(4) { animation-delay: 0.4s; }

.stat-icon {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.2rem;
    margin: 0 auto 1rem;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
}

.stat-card:hover .stat-icon {
    animation: pulse 0.6s ease-in-out;
}

.stat-number {
    font-size: 2.2rem;
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
    margin: 0.5rem 0 0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.candidates-table-container {
    background: white;
    border-radius: 15px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    border: 1px solid rgba(255,255,255,0.8);
    animation: fadeInUp 0.6s ease-out 0.4s both;
}

.candidates-table {
    width: 100%;
    margin: 0;
    border-collapse: separate;
    border-spacing: 0;
}

.candidates-table thead {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
}

.candidates-table thead th {
    padding: 1.25rem 1rem;
    font-weight: 600;
    text-align: left;
    border: none;
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    position: relative;
}

.candidates-table thead th:first-child {
    border-top-left-radius: 15px;
}

.candidates-table thead th:last-child {
    border-top-right-radius: 15px;
}

.candidates-table tbody tr {
    transition: all 0.3s ease;
    border-bottom: 1px solid #f1f5f9;
}

.candidates-table tbody tr:hover {
    background: linear-gradient(135deg, #f8fafc, #ffffff);
    transform: translateX(4px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.candidates-table tbody tr:last-child {
    border-bottom: none;
}

.candidates-table tbody td {
    padding: 1rem;
    vertical-align: middle;
    border: none;
    position: relative;
}

.candidate-avatar {
    width: 45px;
    height: 45px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea, #764ba2);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 700;
    font-size: 1rem;
    margin-right: 1rem;
    box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
    transition: all 0.3s ease;
}

.candidates-table tbody tr:hover .candidate-avatar {
    transform: scale(1.1);
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
}

.candidate-info-cell {
    display: flex;
    align-items: center;
}

.candidate-details h6 {
    margin: 0;
    font-weight: 600;
    color: #1e293b;
    font-size: 1rem;
}

.candidate-details small {
    color: #64748b;
    font-size: 0.875rem;
}

.status-badge {
    padding: 0.35rem 0.85rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: inline-block;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
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

.status-pending { 
    background: linear-gradient(135deg, #fbbf24, #f59e0b); 
    color: #ffffff; 
    box-shadow: 0 2px 8px rgba(251, 191, 36, 0.4);
}

.status-approved { 
    background: linear-gradient(135deg, #10b981, #059669); 
    color: #ffffff; 
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.4);
}

.status-rejected { 
    background: linear-gradient(135deg, #ef4444, #dc2626); 
    color: #ffffff; 
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4);
}

.vote-count {
    font-weight: 700;
    color: #667eea;
    font-size: 1.1rem;
}

.action-buttons {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}

.action-buttons .btn {
    padding: 0.4rem 0.8rem;
    font-size: 0.8rem;
    border-radius: 6px;
    transition: all 0.3s ease;
}

.action-buttons .btn:hover {
    transform: translateY(-1px);
}

.filter-card {
    background: white;
    border-radius: 15px;
    padding: 2rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    margin-bottom: 2rem;
    border: 1px solid rgba(255,255,255,0.8);
    animation: fadeInUp 0.6s ease-out 0.3s both;
}

.btn-primary {
    background: linear-gradient(135deg, #667eea, #764ba2);
    border: none;
    border-radius: 10px;
    padding: 0.6rem 1.5rem;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
}

.btn-primary:hover {
    background: linear-gradient(135deg, #5a67d8, #6b46c1);
    transform: translateY(-2px);
    box-shadow: 0 6px 25px rgba(102, 126, 234, 0.4);
}

.btn-success {
    background: linear-gradient(135deg, #10b981, #059669);
    border: none;
    border-radius: 10px;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
}

.btn-success:hover {
    background: linear-gradient(135deg, #059669, #047857);
    transform: translateY(-2px);
    box-shadow: 0 6px 25px rgba(16, 185, 129, 0.4);
}

.btn-outline-primary {
    border: 2px solid var(--primary-color);
    border-radius: 8px;
    font-weight: 500;
    transition: all 0.3s ease;
}

.btn-outline-primary:hover {
    background: linear-gradient(135deg, #667eea, #764ba2);
    transform: translateY(-1px);
}

.btn-sm {
    padding: 0.4rem 0.8rem;
    font-size: 0.8rem;
    border-radius: 6px;
}

.no-candidates {
    text-align: center;
    padding: 3rem 1rem;
    background: white;
    border-radius: 15px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    animation: fadeInUp 0.6s ease-out;
}

.no-candidates i {
    color: #cbd5e1;
    margin-bottom: 1rem;
    animation: pulse 2s infinite;
}

.form-control, .form-select {
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    transition: all 0.3s ease;
    padding: 0.6rem 0.75rem;
}

.form-control:focus, .form-select:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.pagination .page-link {
    border-radius: 8px;
    margin: 0 2px;
    border: 1px solid #e2e8f0;
    transition: all 0.3s ease;
}

.pagination .page-link:hover {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    transform: translateY(-1px);
}

.pagination .page-item.active .page-link {
    background: linear-gradient(135deg, #667eea, #764ba2);
    border-color: var(--primary-color);
}

.fade-in {
    animation: fadeInUp 0.6s ease-out both;
}

.delay-1 { animation-delay: 0.1s; }
.delay-2 { animation-delay: 0.2s; }
.delay-3 { animation-delay: 0.3s; }
.delay-4 { animation-delay: 0.4s; }

/* Responsive Table Styles */
@media (max-width: 1200px) {
    .candidates-table th:nth-child(4),
    .candidates-table td:nth-child(4) {
        display: none; /* Hide Election column on smaller screens */
    }
}

@media (max-width: 992px) {
    .candidates-table th:nth-child(3),
    .candidates-table td:nth-child(3) {
        display: none; /* Hide Position column on tablets */
    }
    
    .candidates-table-container {
        font-size: 0.875rem;
    }
    
    .candidate-details h6 {
        font-size: 0.9rem;
    }
    
    .action-buttons {
        flex-direction: column;
        gap: 0.25rem;
    }
    
    .action-buttons .btn {
        font-size: 0.75rem;
        padding: 0.25rem 0.5rem;
    }
}

@media (max-width: 768px) {
    .candidates-table {
        display: none; /* Hide table on mobile */
    }
    
    .mobile-candidates-list {
        display: block; /* Show mobile cards instead */
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
    }
    
    .filter-card .d-flex {
        flex-direction: column;
        align-items: stretch !important;
        gap: 1rem;
    }
    
    .filter-card form {
        width: 100%;
    }
    
    .filter-card .form-group {
        margin-bottom: 1rem;
    }
}

/* Mobile candidates list (cards) */
.mobile-candidates-list {
    display: none;
}

@media (max-width: 768px) {
    .mobile-candidates-list {
        display: block;
    }
}

.mobile-candidate-card {
    background: white;
    border-radius: 12px;
    padding: 1.25rem;
    margin-bottom: 1rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #e2e8f0;
    animation: fadeInUp 0.6s ease-out;
}

.mobile-candidate-header {
    display: flex;
    align-items: center;
    margin-bottom: 1rem;
    gap: 1rem;
}

.mobile-candidate-avatar {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 1rem;
}

.mobile-candidate-info h6 {
    margin: 0 0 0.25rem;
    font-size: 1.1rem;
    font-weight: 600;
    color: #1e293b;
}

.mobile-candidate-info .text-muted {
    font-size: 0.875rem;
}

.mobile-candidate-details {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
    margin-bottom: 1rem;
    font-size: 0.875rem;
}

.mobile-detail-item {
    display: flex;
    flex-direction: column;
}

.mobile-detail-label {
    font-weight: 500;
    color: #64748b;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.25rem;
}

.mobile-detail-value {
    color: #1e293b;
    font-weight: 500;
}

.mobile-candidate-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.mobile-candidate-actions .btn {
    flex: 1;
    min-width: calc(50% - 0.25rem);
    font-size: 0.875rem;
    padding: 0.5rem;
}

/* Small mobile screens */
@media (max-width: 480px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .mobile-candidate-details {
        grid-template-columns: 1fr;
        gap: 0.5rem;
    }
    
    .mobile-candidate-actions {
        flex-direction: column;
    }
    
    .mobile-candidate-actions .btn {
        min-width: 100%;
    }
}
</style>

<!-- Page Header -->
<div class="page-header">
    <div class="container">
        <div class="text-center">
            <h1 class="page-title">
                <i class="fas fa-users me-3"></i>
                Candidates Management
            </h1>
            <p class="page-subtitle">
                Review, approve, and manage all candidate applications
            </p>
        </div>
    </div>
</div>

<!-- Statistics -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-users"></i>
        </div>
        <h3 class="stat-number"><?= number_format($stats['total']) ?></h3>
        <p class="stat-label">Total Candidates</p>
    </div>
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-clock"></i>
        </div>
        <h3 class="stat-number"><?= number_format($stats['pending']) ?></h3>
        <p class="stat-label">Pending Approval</p>
    </div>
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-check-circle"></i>
        </div>
        <h3 class="stat-number"><?= number_format($stats['approved']) ?></h3>
        <p class="stat-label">Approved</p>
    </div>
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-times-circle"></i>
        </div>
        <h3 class="stat-number"><?= number_format($stats['rejected']) ?></h3>
        <p class="stat-label">Rejected</p>
    </div>
</div>

<!-- Filters and Actions -->
<div class="filter-card">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <form method="GET" class="d-flex gap-2 flex-wrap align-items-end" id="filterForm">
            <!-- Search -->
            <div class="form-group">
                <label for="search" class="form-label small">Search</label>
                <input type="text" name="search" id="search" class="form-control" placeholder="Name or Student ID..." 
                       value="<?= sanitize($search) ?>" style="min-width: 200px;">
            </div>
            
            <!-- Election Filter -->
            <div class="form-group">
                <label for="election" class="form-label small">Election</label>
                <select name="election" id="election" class="form-select" onchange="loadPositions()">
                    <option value="">All Elections</option>
                    <?php foreach ($elections as $election): ?>
                        <option value="<?= $election['election_id'] ?>" 
                                <?= $election_filter == $election['election_id'] ? 'selected' : '' ?>>
                            <?= sanitize($election['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Position Filter -->
            <div class="form-group">
                <label for="position" class="form-label small">Position</label>
                <select name="position" id="position" class="form-select">
                    <option value="">All Positions</option>
                    <?php foreach ($positions as $position): ?>
                        <option value="<?= $position['position_id'] ?>" 
                                <?= $position_filter == $position['position_id'] ? 'selected' : '' ?>>
                            <?= sanitize($position['position_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Status Filter -->
            <div class="form-group">
                <label for="status" class="form-label small">Status</label>
                <select name="status" id="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                </select>
            </div>
            
            <!-- Filter Actions -->
            <div class="form-group">
                <label class="form-label small">&nbsp;</label>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-outline-primary">
                        <i class="fas fa-search"></i>
                    </button>
                    <?php if ($search || $election_filter || $position_filter || $status_filter): ?>
                        <a href="?" class="btn btn-outline-secondary">
                            <i class="fas fa-times"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
        
        <div class="d-flex gap-2">
            <a href="<?= SITE_URL ?>/election-officer/candidates/manage.php" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>Add Candidate
            </a>
            <button class="btn btn-success" onclick="bulkApprove()">
                <i class="fas fa-check me-2"></i>Bulk Approve
            </button>
        </div>
    </div>
</div>

<!-- Candidates Table -->
<?php if (empty($candidates)): ?>
    <div class="no-candidates">
        <i class="fas fa-users fa-4x"></i>
        <h4>No Candidates Found</h4>
        <p class="text-muted mb-4">
            <?php if ($search || $election_filter || $position_filter || $status_filter): ?>
                No candidates match your current filters. Try adjusting your search criteria.
            <?php else: ?>
                No candidates have been registered yet. Start by adding your first candidate!
            <?php endif; ?>
        </p>
        <a href="<?= SITE_URL ?>/election-officer/candidates/manage.php" class="btn btn-primary">
            <i class="fas fa-plus me-2"></i>Add First Candidate
        </a>
    </div>
<?php else: ?>
    <div class="candidates-table-container">
        <table class="candidates-table">
            <thead>
                <tr>
                    <th>Candidate</th>
                    <th>Position</th>
                    <th>Election</th>
                    <th>Class</th>
                    <th>Votes</th>
                    <th>Status</th>
                    <th>Registered</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($candidates as $candidate): ?>
                    <tr>
                        <td>
                            <div class="candidate-info-cell">
                                <div class="candidate-avatar">
                                    <?php
                                    $names = explode(' ', $candidate['candidate_name']);
                                    echo substr($names[0], 0, 1) . (isset($names[1]) ? substr($names[1], 0, 1) : '');
                                    ?>
                                </div>
                                <div class="candidate-details">
                                    <h6><?= sanitize($candidate['candidate_name']) ?></h6>
                                    <small class="text-muted"><?= sanitize($candidate['gender']) ?></small>
                                </div>
                            </div>
                        </td>
                        <td>
                            <strong><?= sanitize($candidate['position_name']) ?></strong>
                        </td>
                        <td>
                            <?= sanitize($candidate['election_title']) ?>
                            <br>
                            <small class="text-muted">Status: <?= ucfirst($candidate['election_status']) ?></small>
                        </td>
                        <td>
                            <?= sanitize($candidate['class']) ?>
                        </td>
                        <td>
                            <span class="vote-count"><?= $candidate['vote_count'] ?></span>
                        </td>
                        <td>
                            <span class="status-badge status-approved">
                                Approved
                            </span>
                        </td>
                        <td>
                            <?= date('M j, Y', strtotime($candidate['created_at'])) ?>
                            <br>
                            <small class="text-muted"><?= date('g:i A', strtotime($candidate['created_at'])) ?></small>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <a href="<?= SITE_URL ?>/election-officer/candidates/manage.php?candidate_id=<?= $candidate['candidate_id'] ?>" 
                                   class="btn btn-outline-primary btn-sm" title="Edit Candidate">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <button class="btn btn-outline-info btn-sm" 
                                        onclick="viewCandidate(<?= $candidate['candidate_id'] ?>)" title="View Details">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="btn btn-outline-danger btn-sm" 
                                        onclick="deleteCandidate(<?= $candidate['candidate_id'] ?>, '<?= sanitize($candidate['candidate_name']) ?>')" title="Delete Candidate">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Mobile Candidates List -->
    <div class="mobile-candidates-list">
        <?php foreach ($candidates as $candidate): ?>
            <div class="mobile-candidate-card">
                <div class="mobile-candidate-header">
                    <div class="mobile-candidate-avatar">
                        <?php
                        $names = explode(' ', $candidate['candidate_name']);
                        echo substr($names[0], 0, 1) . (isset($names[1]) ? substr($names[1], 0, 1) : '');
                        ?>
                    </div>
                    <div class="mobile-candidate-info">
                        <h6><?= sanitize($candidate['candidate_name']) ?></h6>
                        <small class="text-muted"><?= sanitize($candidate['gender']) ?></small>
                    </div>
                    <div class="ms-auto">
                        <span class="status-badge status-approved">Approved</span>
                    </div>
                </div>
                
                <div class="mobile-candidate-details">
                    <div class="mobile-detail-item">
                        <span class="mobile-detail-label">Position</span>
                        <span class="mobile-detail-value"><?= sanitize($candidate['position_name']) ?></span>
                    </div>
                    <div class="mobile-detail-item">
                        <span class="mobile-detail-label">Class</span>
                        <span class="mobile-detail-value"><?= sanitize($candidate['class']) ?></span>
                    </div>
                    <div class="mobile-detail-item">
                        <span class="mobile-detail-label">Votes</span>
                        <span class="mobile-detail-value"><?= $candidate['vote_count'] ?></span>
                    </div>
                    <div class="mobile-detail-item">
                        <span class="mobile-detail-label">Registered</span>
                        <span class="mobile-detail-value">
                            <?= date('M j', strtotime($candidate['created_at'])) ?>
                            <br><small class="text-muted"><?= date('g:i A', strtotime($candidate['created_at'])) ?></small>
                        </span>
                    </div>
                </div>
                
                <div class="mobile-candidate-actions">
                    <a href="<?= SITE_URL ?>/election-officer/candidates/manage.php?candidate_id=<?= $candidate['candidate_id'] ?>" 
                       class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-edit me-1"></i>Edit
                    </a>
                    <button class="btn btn-outline-info btn-sm" 
                            onclick="viewCandidate(<?= $candidate['candidate_id'] ?>)">
                        <i class="fas fa-eye me-1"></i>View
                    </button>
                    <button class="btn btn-outline-danger btn-sm" 
                            onclick="confirmDelete(<?= $candidate['candidate_id'] ?>, '<?= addslashes($candidate['candidate_name']) ?>')">
                        <i class="fas fa-trash me-1"></i>Delete
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <nav aria-label="Candidates pagination" class="mt-4">
            <ul class="pagination justify-content-center">
                <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=1<?= $search ? "&search=" . urlencode($search) : "" ?><?= $election_filter ? "&election=" . urlencode($election_filter) : "" ?><?= $position_filter ? "&position=" . urlencode($position_filter) : "" ?><?= $status_filter ? "&status=" . urlencode($status_filter) : "" ?>">
                            First
                        </a>
                    </li>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?= $page - 1 ?><?= $search ? "&search=" . urlencode($search) : "" ?><?= $election_filter ? "&election=" . urlencode($election_filter) : "" ?><?= $position_filter ? "&position=" . urlencode($position_filter) : "" ?><?= $status_filter ? "&status=" . urlencode($status_filter) : "" ?>">
                            Previous
                        </a>
                    </li>
                <?php endif; ?>
                
                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?><?= $search ? "&search=" . urlencode($search) : "" ?><?= $election_filter ? "&election=" . urlencode($election_filter) : "" ?><?= $position_filter ? "&position=" . urlencode($position_filter) : "" ?><?= $status_filter ? "&status=" . urlencode($status_filter) : "" ?>">
                            <?= $i ?>
                        </a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?= $page + 1 ?><?= $search ? "&search=" . urlencode($search) : "" ?><?= $election_filter ? "&election=" . urlencode($election_filter) : "" ?><?= $position_filter ? "&position=" . urlencode($position_filter) : "" ?><?= $status_filter ? "&status=" . urlencode($status_filter) : "" ?>">
                            Next
                        </a>
                    </li>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?= $total_pages ?><?= $search ? "&search=" . urlencode($search) : "" ?><?= $election_filter ? "&election=" . urlencode($election_filter) : "" ?><?= $position_filter ? "&position=" . urlencode($position_filter) : "" ?><?= $status_filter ? "&status=" . urlencode($status_filter) : "" ?>">
                            Last
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<script>
function loadPositions() {
    const electionSelect = document.getElementById('election');
    const positionSelect = document.getElementById('position');
    const electionId = electionSelect.value;
    
    // Clear current options except "All Positions"
    positionSelect.innerHTML = '<option value="">All Positions</option>';
    
    if (electionId) {
        fetch(`<?= SITE_URL ?>/api/elections/positions.php?election_id=${electionId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    data.positions.forEach(position => {
                        const option = document.createElement('option');
                        option.value = position.position_id;
                        option.textContent = position.position_name;
                        positionSelect.appendChild(option);
                    });
                }
            })
            .catch(error => console.error('Error loading positions:', error));
    }
}

function viewCandidate(candidateId) {
    // Show loading state
    const modal = new bootstrap.Modal(document.getElementById('candidateModal'));
    document.getElementById('candidateModalContent').innerHTML = `
        <div class="text-center p-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <div class="mt-3">Loading candidate details...</div>
        </div>
    `;
    modal.show();
    
    // Fetch candidate data
    fetch(`<?= SITE_URL ?>/api/candidates/view.php?id=${candidateId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayCandidateInfo(data.candidate);
            } else {
                document.getElementById('candidateModalContent').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Error: ${data.message}
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('candidateModalContent').innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Failed to load candidate details.
                </div>
            `;
        });
}

function displayCandidateInfo(candidate) {
    const content = `
        <div class="p-3">
            <div class="text-center mb-3">
                <h5>${candidate.candidate_name}</h5>
                <p class="text-muted mb-0">${candidate.position_name}</p>
                <small class="text-muted">${candidate.election_name}</small>
                <br><span class="badge bg-primary mt-1">${candidate.vote_count} Votes</span>
            </div>
            
            <div class="mb-2"><strong>Student ID:</strong> ${candidate.student_number}</div>
            <div class="mb-2"><strong>Gender:</strong> ${candidate.gender}</div>
            <div class="mb-2"><strong>Level:</strong> ${candidate.level}</div>
            <div class="mb-2"><strong>Program:</strong> ${candidate.program}</div>
            <div class="mb-2"><strong>Class:</strong> ${candidate.class}</div>
            ${candidate.phone ? `<div class="mb-2"><strong>Phone:</strong> ${candidate.phone}</div>` : ''}
            <div class="mb-3">
                <strong>Status:</strong> 
                <span class="badge bg-${candidate.election_status === 'active' ? 'success' : candidate.election_status === 'completed' ? 'secondary' : 'warning'}">
                    ${candidate.election_status.charAt(0).toUpperCase() + candidate.election_status.slice(1)}
                </span>
            </div>
            
            <div class="text-center">
                <a href="<?= SITE_URL ?>/election-officer/candidates/manage.php?candidate_id=${candidate.candidate_id}" 
                   class="btn btn-primary btn-sm">Edit</a>
                <button type="button" class="btn btn-secondary btn-sm ms-2" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    `;
    
    document.getElementById('candidateModalContent').innerHTML = content;
}

function deleteCandidate(candidateId, candidateName) {
    if (confirm(`Are you sure you want to delete candidate "${candidateName}"?\n\nThis action cannot be undone.`)) {
        fetch('<?= SITE_URL ?>/api/candidates/delete.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                candidate_id: candidateId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Remove the row from table with animation
                const row = document.querySelector(`tr[data-candidate-id="${candidateId}"]`);
                if (row) {
                    row.style.opacity = '0';
                    row.style.transform = 'translateX(-20px)';
                    setTimeout(() => {
                        location.reload();
                    }, 300);
                } else {
                    location.reload();
                }
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while deleting the candidate.');
        });
    }
}

function approveCandidate(candidateId, candidateName) {
    if (confirm(`Approve candidate "${candidateName}"?`)) {
        updateCandidateStatus(candidateId, 'approved');
    }
}

function rejectCandidate(candidateId, candidateName) {
    const reason = prompt(`Reject candidate "${candidateName}"?\nOptionally provide a reason:`);
    if (reason !== null) {
        updateCandidateStatus(candidateId, 'rejected', reason);
    }
}

function updateCandidateStatus(candidateId, status, reason = '') {
    fetch('<?= SITE_URL ?>/api/candidates/approve.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            candidate_id: candidateId,
            status: status,
            reason: reason
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while updating candidate status.');
    });
}

function bulkApprove() {
    if (confirm('Approve all pending candidates in current view?')) {
        const pendingBadges = document.querySelectorAll('.status-pending');
        if (pendingBadges.length === 0) {
            alert('No pending candidates found in current view.');
            return;
        }
        
        // This is a simplified version - in a real implementation, you'd collect all candidate IDs
        alert('Bulk approval feature would be implemented here.');
    }
}
</script>

<!-- Candidate View Modal -->
<div class="modal fade" id="candidateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title">
                    <i class="fas fa-user me-2"></i>Candidate Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div id="candidateModalContent">
                    <!-- Content will be loaded here -->
                </div>
            </div>
        </div>
    </div>
</div>


<?php include __DIR__ . '/../../includes/footer.php'; ?>