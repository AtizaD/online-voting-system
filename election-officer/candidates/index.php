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

.candidates-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 1.5rem;
}

.candidate-card {
    background: white;
    border-radius: 0.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    overflow: hidden;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.candidate-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.candidate-header {
    position: relative;
    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
    color: white;
    padding: 1.5rem;
}

.candidate-photo {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: rgba(255,255,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    font-weight: 600;
    margin: 0 auto 1rem;
    border: 3px solid rgba(255,255,255,0.3);
}

.candidate-name {
    font-size: 1.25rem;
    font-weight: 600;
    margin: 0;
    text-align: center;
}

.candidate-position {
    text-align: center;
    opacity: 0.9;
    margin: 0.5rem 0 0;
}

.status-badge {
    position: absolute;
    top: 1rem;
    right: 1rem;
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 500;
}

.status-pending { background: rgba(251, 191, 36, 0.2); color: #f59e0b; border: 1px solid rgba(251, 191, 36, 0.3); }
.status-approved { background: rgba(34, 197, 94, 0.2); color: #22c55e; border: 1px solid rgba(34, 197, 94, 0.3); }
.status-rejected { background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); }

.candidate-body {
    padding: 1.5rem;
}

.candidate-info {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.5rem;
    margin-bottom: 1rem;
    font-size: 0.875rem;
}

.candidate-info-item {
    display: flex;
    justify-content: space-between;
}

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
        <p class="stat-label">Total Candidates</p>
    </div>
    <div class="stat-card">
        <h3 class="stat-number"><?= number_format($stats['pending']) ?></h3>
        <p class="stat-label">Pending Approval</p>
    </div>
    <div class="stat-card">
        <h3 class="stat-number"><?= number_format($stats['approved']) ?></h3>
        <p class="stat-label">Approved</p>
    </div>
    <div class="stat-card">
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
            <a href="<?= SITE_URL ?>election-officer/candidates/manage.php" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>Add Candidate
            </a>
            <button class="btn btn-success" onclick="bulkApprove()">
                <i class="fas fa-check me-2"></i>Bulk Approve
            </button>
        </div>
    </div>
</div>

<!-- Candidates Grid -->
<?php if (empty($candidates)): ?>
    <div class="text-center py-5">
        <i class="fas fa-users fa-4x text-muted mb-3"></i>
        <h4>No Candidates Found</h4>
        <p class="text-muted mb-4">
            <?php if ($search || $election_filter || $position_filter || $status_filter): ?>
                No candidates match your current filters.
            <?php else: ?>
                No candidates have been registered yet.
            <?php endif; ?>
        </p>
        <a href="<?= SITE_URL ?>election-officer/candidates/manage.php" class="btn btn-primary">
            <i class="fas fa-plus me-2"></i>Add First Candidate
        </a>
    </div>
<?php else: ?>
    <div class="candidates-grid">
        <?php foreach ($candidates as $candidate): ?>
            <div class="candidate-card">
                <div class="candidate-header">
                    <span class="status-badge status-<?= $candidate['approval_status'] ?>">
                        <?= ucfirst($candidate['approval_status']) ?>
                    </span>
                    
                    <div class="candidate-photo">
                        <?php
                        $names = explode(' ', $candidate['candidate_name']);
                        echo substr($names[0], 0, 1) . (isset($names[1]) ? substr($names[1], 0, 1) : '');
                        ?>
                    </div>
                    
                    <h3 class="candidate-name"><?= sanitize($candidate['candidate_name']) ?></h3>
                    <p class="candidate-position"><?= sanitize($candidate['position_name']) ?></p>
                </div>
                
                <div class="candidate-body">
                    <div class="candidate-info">
                        <div class="candidate-info-item">
                            <span>Student ID:</span>
                            <strong><?= sanitize($candidate['student_number']) ?></strong>
                        </div>
                        <div class="candidate-info-item">
                            <span>Program:</span>
                            <strong><?= sanitize($candidate['program']) ?></strong>
                        </div>
                        <div class="candidate-info-item">
                            <span>Class:</span>
                            <strong><?= sanitize($candidate['class']) ?></strong>
                        </div>
                        <div class="candidate-info-item">
                            <span>Votes:</span>
                            <strong><?= $candidate['vote_count'] ?></strong>
                        </div>
                        <div class="candidate-info-item">
                            <span>Election:</span>
                            <strong><?= sanitize($candidate['election_title']) ?></strong>
                        </div>
                        <div class="candidate-info-item">
                            <span>Registered:</span>
                            <strong><?= date('M j, Y', strtotime($candidate['created_at'])) ?></strong>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <a href="<?= SITE_URL ?>election-officer/candidates/manage.php?id=<?= $candidate['candidate_id'] ?>" 
                           class="btn btn-outline-primary btn-sm flex-fill">
                            <i class="fas fa-edit me-1"></i>Edit
                        </a>
                        
                        <?php if ($candidate['approval_status'] === 'pending'): ?>
                            <button class="btn btn-success btn-sm" 
                                    onclick="approveCandidate(<?= $candidate['candidate_id'] ?>, '<?= sanitize($candidate['candidate_name']) ?>')">
                                <i class="fas fa-check me-1"></i>Approve
                            </button>
                            <button class="btn btn-danger btn-sm" 
                                    onclick="rejectCandidate(<?= $candidate['candidate_id'] ?>, '<?= sanitize($candidate['candidate_name']) ?>')">
                                <i class="fas fa-times me-1"></i>Reject
                            </button>
                        <?php elseif ($candidate['approval_status'] === 'approved'): ?>
                            <button class="btn btn-warning btn-sm" 
                                    onclick="rejectCandidate(<?= $candidate['candidate_id'] ?>, '<?= sanitize($candidate['candidate_name']) ?>')">
                                <i class="fas fa-ban me-1"></i>Reject
                            </button>
                        <?php elseif ($candidate['approval_status'] === 'rejected'): ?>
                            <button class="btn btn-success btn-sm" 
                                    onclick="approveCandidate(<?= $candidate['candidate_id'] ?>, '<?= sanitize($candidate['candidate_name']) ?>')">
                                <i class="fas fa-check me-1"></i>Approve
                            </button>
                        <?php endif; ?>
                    </div>
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
        fetch(`<?= SITE_URL ?>api/elections/positions.php?election_id=${electionId}`)
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
    fetch('<?= SITE_URL ?>api/candidates/approve.php', {
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
        const pendingCards = document.querySelectorAll('.status-pending');
        if (pendingCards.length === 0) {
            alert('No pending candidates found in current view.');
            return;
        }
        
        // This is a simplified version - in a real implementation, you'd collect all candidate IDs
        alert('Bulk approval feature would be implemented here.');
    }
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>