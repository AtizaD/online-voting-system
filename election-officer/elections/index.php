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
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: white;
    border-radius: 0.5rem;
    padding: 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    text-align: center;
    border-left: 4px solid var(--primary-color);
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
    margin: 0.5rem 0 0;
}

.election-card {
    background: white;
    border-radius: 0.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-bottom: 1rem;
    overflow: hidden;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.election-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.election-header {
    padding: 1.5rem;
    border-bottom: 1px solid #f1f5f9;
}

.election-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #1e293b;
    margin: 0 0 0.5rem;
}

.election-description {
    color: #64748b;
    margin: 0;
}

.election-body {
    padding: 1.5rem;
}

.election-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.election-stat {
    text-align: center;
    padding: 1rem;
    background: #f8fafc;
    border-radius: 0.375rem;
}

.election-stat-number {
    font-size: 1.5rem;
    font-weight: 600;
    color: #1e293b;
    margin: 0;
}

.election-stat-label {
    font-size: 0.75rem;
    color: #64748b;
    margin: 0.25rem 0 0;
}

.election-dates {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1rem;
}

.date-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #64748b;
    font-size: 0.875rem;
}

.status-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 500;
}

.status-draft { background: #f3f4f6; color: #374151; }
.status-scheduled { background: #dbeafe; color: #1d4ed8; }
.status-active { background: #dcfce7; color: #166534; }
.status-completed { background: #f3e8ff; color: #7c3aed; }
.status-cancelled { background: #fee2e2; color: #dc2626; }

.filter-card {
    background: white;
    border-radius: 0.5rem;
    padding: 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-bottom: 2rem;
}
</style>

<!-- Statistics -->
<div class="stats-grid">
    <div class="stat-card">
        <h3 class="stat-number"><?= number_format($stats['total']) ?></h3>
        <p class="stat-label">Total Elections</p>
    </div>
    <div class="stat-card">
        <h3 class="stat-number"><?= number_format($stats['active']) ?></h3>
        <p class="stat-label">Active Elections</p>
    </div>
    <div class="stat-card">
        <h3 class="stat-number"><?= number_format($stats['scheduled']) ?></h3>
        <p class="stat-label">Scheduled Elections</p>
    </div>
    <div class="stat-card">
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
        
        <a href="<?= SITE_URL ?>election-officer/elections/manage.php" class="btn btn-primary">
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
        <a href="<?= SITE_URL ?>election-officer/elections/manage.php" class="btn btn-primary">
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
                    <a href="<?= SITE_URL ?>election-officer/elections/manage.php?id=<?= $election['election_id'] ?>" 
                       class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-edit me-1"></i>Edit
                    </a>
                    <a href="<?= SITE_URL ?>election-officer/elections/positions.php?election_id=<?= $election['election_id'] ?>" 
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
                    <a href="<?= SITE_URL ?>election-officer/results/view.php?election_id=<?= $election['election_id'] ?>" 
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