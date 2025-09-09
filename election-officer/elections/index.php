<?php
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth(['election_officer']);

$page_title = 'Elections Management';
$breadcrumbs = [
    ['title' => 'Election Officer', 'url' => SITE_URL . 'election-officer/'],
    ['title' => 'Elections']
];

$db = Database::getInstance()->getConnection();

// Handle pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Handle filters
$status_filter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

// Build WHERE clause  
$where_conditions = [];
$params = [];

if ($status_filter) {
    $where_conditions[] = 'e.status = ?';
    $params[] = $status_filter;
}

if ($search) {
    $where_conditions[] = '(e.title LIKE ? OR e.description LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count
$count_sql = "SELECT COUNT(*) FROM elections e $where_clause";
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_records = $stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

// Get elections
$sql = "
    SELECT 
        e.*,
        et.name as type_name,
        COUNT(DISTINCT c.candidate_id) as candidate_count,
        COUNT(DISTINCT v.vote_id) as vote_count,
        COUNT(DISTINCT p.position_id) as position_count
    FROM elections e
    LEFT JOIN election_types et ON e.election_type_id = et.election_type_id
    LEFT JOIN candidates c ON e.election_id = c.election_id
    LEFT JOIN votes v ON e.election_id = v.election_id
    LEFT JOIN positions p ON e.election_id = p.position_id AND p.is_active = 1
    $where_clause
    GROUP BY e.election_id
    ORDER BY e.created_at DESC
    LIMIT ? OFFSET ?
";

$params[] = $per_page;
$params[] = $offset;
$stmt = $db->prepare($sql);
$stmt->execute($params);
$elections = $stmt->fetchAll();

// Get election statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as scheduled,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
    FROM elections
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
    background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="dots" width="20" height="20" patternUnits="userSpaceOnUse"><circle cx="10" cy="10" r="1" fill="%23ffffff" opacity="0.2"/></pattern></defs><rect width="100" height="100" fill="url(%23dots)"/></svg>');
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

.election-card {
    background: white;
    border-radius: 15px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem;
    overflow: hidden;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    border: 1px solid rgba(255,255,255,0.8);
    animation: slideIn 0.6s ease-out;
}

.election-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 40px rgba(0,0,0,0.12);
}

.election-header {
    padding: 1.75rem;
    border-bottom: 1px solid #f1f5f9;
    background: linear-gradient(135deg, #f8fafc, #ffffff);
    position: relative;
}

.election-header::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: linear-gradient(90deg, #667eea, #764ba2);
    opacity: 0.6;
}

.election-title {
    font-size: 1.4rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 0.5rem;
}

.election-description {
    color: #64748b;
    margin: 0;
    line-height: 1.5;
}

.election-body {
    padding: 1.75rem;
}

.election-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.election-stat {
    text-align: center;
    padding: 1.25rem;
    background: linear-gradient(135deg, #f8fafc, #ffffff);
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    transition: all 0.3s ease;
    cursor: pointer;
}

.election-stat:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    border-color: var(--primary-color);
}

.election-stat-number {
    font-size: 1.75rem;
    font-weight: 700;
    background: linear-gradient(135deg, #667eea, #764ba2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    margin: 0;
}

.election-stat-label {
    font-size: 0.75rem;
    color: #64748b;
    font-weight: 500;
    margin: 0.5rem 0 0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.election-dates {
    display: flex;
    align-items: center;
    gap: 1.5rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}

.date-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #64748b;
    font-size: 0.875rem;
    font-weight: 500;
    padding: 0.5rem 1rem;
    background: #f8fafc;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    transition: all 0.3s ease;
}

.date-item:hover {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    transform: translateY(-1px);
}

.date-item i {
    color: var(--primary-color);
    transition: color 0.3s ease;
}

.date-item:hover i {
    color: white;
}

.status-badge {
    padding: 0.4rem 1rem;
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

.status-draft { 
    background: linear-gradient(135deg, #f3f4f6, #e5e7eb); 
    color: #374151; 
    box-shadow: 0 2px 8px rgba(55, 65, 81, 0.15);
}

.status-scheduled { 
    background: linear-gradient(135deg, #dbeafe, #bfdbfe); 
    color: #1d4ed8; 
    box-shadow: 0 2px 8px rgba(29, 78, 216, 0.2);
}

.status-active { 
    background: linear-gradient(135deg, #dcfce7, #bbf7d0); 
    color: #166534; 
    box-shadow: 0 2px 8px rgba(22, 101, 52, 0.2);
}

.status-completed { 
    background: linear-gradient(135deg, #f3e8ff, #e9d5ff); 
    color: #7c3aed; 
    box-shadow: 0 2px 8px rgba(124, 58, 237, 0.2);
}

.status-cancelled { 
    background: linear-gradient(135deg, #fee2e2, #fecaca); 
    color: #dc2626; 
    box-shadow: 0 2px 8px rgba(220, 38, 38, 0.2);
}

.filter-card {
    background: white;
    border-radius: 15px;
    padding: 1.75rem;
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

.no-elections {
    text-align: center;
    padding: 3rem 1rem;
    background: white;
    border-radius: 15px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    animation: fadeInUp 0.6s ease-out;
}

.no-elections i {
    color: #cbd5e1;
    margin-bottom: 1rem;
}

.fade-in {
    animation: fadeInUp 0.6s ease-out both;
}

.delay-1 { animation-delay: 0.1s; }
.delay-2 { animation-delay: 0.2s; }
.delay-3 { animation-delay: 0.3s; }
.delay-4 { animation-delay: 0.4s; }

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
</style>

<!-- Page Header -->
<div class="page-header">
    <div class="container">
        <div class="text-center">
            <h1 class="page-title">
                <i class="fas fa-poll me-3"></i>
                Elections Management
            </h1>
            <p class="page-subtitle">
                Create, manage, and monitor all your elections in one place
            </p>
        </div>
    </div>
</div>

<!-- Statistics -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-poll"></i>
        </div>
        <h3 class="stat-number"><?= number_format($stats['total']) ?></h3>
        <p class="stat-label">Total Elections</p>
    </div>
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-play-circle"></i>
        </div>
        <h3 class="stat-number"><?= number_format($stats['active']) ?></h3>
        <p class="stat-label">Active Elections</p>
    </div>
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-clock"></i>
        </div>
        <h3 class="stat-number"><?= number_format($stats['scheduled']) ?></h3>
        <p class="stat-label">Scheduled Elections</p>
    </div>
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-check-circle"></i>
        </div>
        <h3 class="stat-number"><?= number_format($stats['completed']) ?></h3>
        <p class="stat-label">Completed Elections</p>
    </div>
</div>

<!-- Filters and Actions -->
<div class="filter-card">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div class="d-flex gap-3 flex-wrap">
            <!-- Search -->
            <form method="GET" class="d-flex gap-2">
                <input type="text" name="search" class="form-control" placeholder="Search elections..." 
                       value="<?= sanitize($search) ?>" style="min-width: 250px;">
                <select name="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="draft" <?= $status_filter === 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="scheduled" <?= $status_filter === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                    <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                </select>
                <button type="submit" class="btn btn-outline-primary">
                    <i class="fas fa-search"></i>
                </button>
                <?php if ($search || $status_filter): ?>
                    <a href="?" class="btn btn-outline-secondary">
                        <i class="fas fa-times"></i>
                    </a>
                <?php endif; ?>
            </form>
        </div>
        
        <a href="<?= SITE_URL ?>/election-officer/elections/manage.php" class="btn btn-primary">
            <i class="fas fa-plus me-2"></i>Create Election
        </a>
    </div>
</div>

<!-- Elections List -->
<?php if (empty($elections)): ?>
    <div class="text-center py-5">
        <i class="fas fa-poll fa-4x text-muted mb-3"></i>
        <h4>No Elections Found</h4>
        <p class="text-muted mb-4">
            <?php if ($search || $status_filter): ?>
                No elections match your current filters.
            <?php else: ?>
                You haven't created any elections yet.
            <?php endif; ?>
        </p>
        <a href="<?= SITE_URL ?>/election-officer/elections/manage.php" class="btn btn-primary">
            <i class="fas fa-plus me-2"></i>Create First Election
        </a>
    </div>
<?php else: ?>
    <?php foreach ($elections as $election): ?>
        <div class="election-card">
            <div class="election-header">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="flex-grow-1">
                        <h3 class="election-title"><?= sanitize($election['name']) ?></h3>
                        <p class="election-description"><?= sanitize($election['description']) ?></p>
                    </div>
                    <span class="status-badge status-<?= $election['status'] ?>">
                        <?= ucfirst($election['status']) ?>
                    </span>
                </div>
            </div>
            
            <div class="election-body">
                <div class="election-stats">
                    <div class="election-stat">
                        <h4 class="election-stat-number"><?= $election['position_count'] ?></h4>
                        <p class="election-stat-label">Positions</p>
                    </div>
                    <div class="election-stat">
                        <h4 class="election-stat-number"><?= $election['candidate_count'] ?></h4>
                        <p class="election-stat-label">Candidates</p>
                    </div>
                    <div class="election-stat">
                        <h4 class="election-stat-number"><?= $election['vote_count'] ?></h4>
                        <p class="election-stat-label">Votes</p>
                    </div>
                </div>
                
                <div class="election-dates">
                    <div class="date-item">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Start: <?= date('M j, Y g:i A', strtotime($election['start_date'])) ?></span>
                    </div>
                    <div class="date-item">
                        <i class="fas fa-calendar-check"></i>
                        <span>End: <?= date('M j, Y g:i A', strtotime($election['end_date'])) ?></span>
                    </div>
                </div>
                
                <div class="d-flex gap-2 flex-wrap">
                    <a href="<?= SITE_URL ?>/election-officer/elections/manage.php?id=<?= $election['election_id'] ?>" 
                       class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-edit me-1"></i>Edit
                    </a>
                    <a href="<?= SITE_URL ?>/election-officer/elections/positions.php?election_id=<?= $election['election_id'] ?>" 
                       class="btn btn-outline-info btn-sm">
                        <i class="fas fa-list me-1"></i>Positions
                    </a>
                    <?php if ($election['status'] === 'draft'): ?>
                        <button class="btn btn-outline-success btn-sm" onclick="changeStatus(<?= $election['election_id'] ?>, 'scheduled')">
                            <i class="fas fa-clock me-1"></i>Schedule
                        </button>
                    <?php elseif ($election['status'] === 'scheduled'): ?>
                        <button class="btn btn-outline-success btn-sm" onclick="changeStatus(<?= $election['election_id'] ?>, 'active')">
                            <i class="fas fa-play me-1"></i>Start
                        </button>
                    <?php elseif ($election['status'] === 'active'): ?>
                        <button class="btn btn-outline-warning btn-sm" onclick="changeStatus(<?= $election['election_id'] ?>, 'completed')">
                            <i class="fas fa-stop me-1"></i>End
                        </button>
                    <?php endif; ?>
                    <a href="<?= SITE_URL ?>/election-officer/results/view.php?election_id=<?= $election['election_id'] ?>" 
                       class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-chart-bar me-1"></i>Results
                    </a>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    
    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <nav aria-label="Elections pagination">
            <ul class="pagination justify-content-center">
                <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=1<?= $search ? "&search=" . urlencode($search) : "" ?><?= $status_filter ? "&status=" . urlencode($status_filter) : "" ?>">
                            First
                        </a>
                    </li>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?= $page - 1 ?><?= $search ? "&search=" . urlencode($search) : "" ?><?= $status_filter ? "&status=" . urlencode($status_filter) : "" ?>">
                            Previous
                        </a>
                    </li>
                <?php endif; ?>
                
                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?><?= $search ? "&search=" . urlencode($search) : "" ?><?= $status_filter ? "&status=" . urlencode($status_filter) : "" ?>">
                            <?= $i ?>
                        </a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?= $page + 1 ?><?= $search ? "&search=" . urlencode($search) : "" ?><?= $status_filter ? "&status=" . urlencode($status_filter) : "" ?>">
                            Next
                        </a>
                    </li>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?= $total_pages ?><?= $search ? "&search=" . urlencode($search) : "" ?><?= $status_filter ? "&status=" . urlencode($status_filter) : "" ?>">
                            Last
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<script>
function changeStatus(electionId, newStatus) {
    if (confirm(`Are you sure you want to change the election status to ${newStatus}?`)) {
        fetch('<?= SITE_URL ?>api/elections/status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                election_id: electionId,
                status: newStatus
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
            alert('An error occurred while updating the election status.');
        });
    }
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>